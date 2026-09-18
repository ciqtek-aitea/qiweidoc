<?php
// Copyright © 2016- 2025 Sesame Network Technology all right reserved

/**
 * 会话存档消息拉取服务
 */

namespace Modules\Main\Service;

use Basis\Nats\Message\Payload;
use Carbon\Carbon;
use Common\Broadcast;
use Common\Exceptions\RetryableJobException;
use Common\Job\Producer;
use Common\Micro;
use Common\Yii;
use LogicException;
use Modules\Main\Consumer\DownloadChatSessionBitMediasConsumer;
use Modules\Main\Consumer\DownloadChatSessionMediasConsumer;
use Modules\Main\Consumer\UploadStorageToCloudConsumer;
use Modules\Main\Enum\EnumChatConversationType;
use Modules\Main\Enum\EnumChatMessageRole;
use Modules\Main\Enum\EnumMessageType;
use Modules\Main\Model\ChatConversationsModel;
use Modules\Main\Model\ChatMessageModel;
use Modules\Main\Model\CorpModel;
use Modules\Main\Model\CloudStorageSettingModel;
use Modules\Main\Model\CustomersModel;
use Modules\Main\Model\GroupModel;
use Modules\Main\Model\SettingModel;
use Modules\Main\Model\StaffModel;
use Modules\Main\Model\StorageModel;
use Ramsey\Uuid\Uuid;
use Throwable;

class ChatSessionPullService
{
    private const MESSAGE_LIMIT = 100;
    private const MAX_FETCH_ROUNDS = 10;
    private const LARGE_FILE_THRESHOLD = 20 * 1024 * 1024; // 20MB
    private static CorpModel $corp;

    /**
     * 拉取并保存会话消息
     *
     * @throws Throwable
     */
    public static function handleMessage(CorpModel $corp): void
    {
        self::$corp = $corp;

        $chatSeq = (int) self::$corp->get('chat_seq');
        for ($round = 0; $round < self::MAX_FETCH_ROUNDS; $round++) {
            $messages = self::fetchMessages($chatSeq);
            if (empty($messages)) {
                break;
            }

            $lastSeq = null;
            foreach ($messages as $msg) {
                if (!empty($msg['seq'])) {
                    $lastSeq = $msg['seq'];
                }

                // 过滤掉不能识别和重复的消息
                if (!self::isValidMessage($msg)) {
                    continue;
                }

                //处理并保存消息内容
                $messageData = self::processMessage($msg);
                if (!$messageData) {
                    continue;
                }

                Yii::logger()->info("保存消息成功", ['msg_content' => $messageData->get('msg_content'), 'msg_type' => $messageData->get('msg_type')]);

                // 创建会话
                $conversation = self::saveConversation($messageData);

                // 更新消息的会话信息
                $messageData->update([
                    'conversation_id' => $conversation->get('id'),
                    'conversation_type' => $conversation->get('type'),
                ]);

                // 下载资源
                if (in_array($messageData->get('msg_type'), ChatSessionService::ValidMediaType)) {
                    if (self::isLargeFile($messageData)) { // 大文件到单独的队列中处理
                        Producer::dispatch(DownloadChatSessionBitMediasConsumer::class, ['corp' => $corp, 'message' => $messageData]);
                    } else {
                        Producer::dispatch(DownloadChatSessionMediasConsumer::class, ['corp' => $corp, 'message' => $messageData]);
                    }
                }

                // 广播
                Broadcast::event('chat-session-pull')->send(json_encode([
                    'msg_id' => $messageData->get('id'),
                    'msg_type' => $messageData->get('msg_type'),
                    'from_role' => $messageData->get('from_role'),
                    'to_role' => $messageData->get('to_role'),
                ]));
            }

            // 每批处理完成后推进游标，避免下一批重复拉取。
            if (!empty($lastSeq)) {
                $chatSeq = (int) $lastSeq;
                self::$corp->update(['chat_seq' => $chatSeq]);
            }

            if (count($messages) < self::MESSAGE_LIMIT) {
                break;
            }
        }
    }

    public static function isLargeFile(ChatMessageModel $message): bool
    {
        return $message->get('msg_type') === 'file' && $message->get('raw_content')['filesize'] > self::LARGE_FILE_THRESHOLD;
    }

    /**
     * 下载并保存资源
     *
     * @throws Throwable
     */
    public static function handleMedia(CorpModel $corp, ChatMessageModel $message)
    {
        if (!in_array($message->get('msg_type'), ChatSessionService::ValidMediaType)) {
            throw new LogicException("消息类型不正确");
        }

        $rawContent = $message->get('raw_content');
        $sdkFileId = $rawContent['sdkfileid'] ?? '';
        $sourceMd5 = strtolower($rawContent['md5sum'] ?? '');
        if (empty($sdkFileId)) {
            throw new LogicException("消息不完整, 缺少sdkfileid字段");
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $sourceMd5)) {
            $sourceMd5 = '';
        }

        $md5 = $sourceMd5 ?: md5($sdkFileId);
        $lockKey = 'chat-session-media:' . $corp->get('id') . ':' . $md5;
        // 下载 RPC 最长等待 500 秒；锁必须覆盖整个下载和数据库写入过程。
        $mutex = Yii::mutex(900);
        if (!$mutex->acquire($lockKey, 600)) {
            throw new RetryableJobException('等待相同会话附件处理超时');
        }

        try {
            // 队列负载中的 message 可能是旧快照，必须在锁内重新读取。
            $currentMessage = ChatMessageModel::query()->where(['msg_id' => $message->get('msg_id')])->getOne();
            if ($currentMessage === null) {
                throw new LogicException('会话消息不存在');
            }

            $existingHash = $currentMessage->get('msg_content');
            if ($existingHash && self::findAvailableStorage($existingHash, $rawContent)) {
                return;
            }

            $reusable = self::findReusableMedia($corp, $currentMessage, $sourceMd5, $sdkFileId, $rawContent);
            if ($reusable !== null) {
                $currentMessage->update(['msg_content' => $reusable->get('hash')]);
                return;
            }

            self::downloadMedia($corp, $currentMessage, $sdkFileId, $sourceMd5);
        } finally {
            $mutex->release($lockKey);
        }
    }

    private static function findReusableMedia(CorpModel $corp, ChatMessageModel $message, string $sourceMd5, string $sdkFileId, array $rawContent): ?StorageModel
    {
        $query = ChatMessageModel::query()
            ->where(['corp_id' => $corp->get('id'), 'msg_type' => $message->get('msg_type')])
            ->andWhere(['<>', 'msg_content', ''])
            ->andWhere(['<>', 'msg_id', $message->get('msg_id')]);

        if ($sourceMd5) {
            $query->andWhere("LOWER(raw_content->>'md5sum') = :source_md5", [':source_md5' => $sourceMd5]);
        } else {
            // 没有可信的内容 MD5 时，仅允许相同 sdkfileid 复用。
            $query->andWhere("raw_content->>'sdkfileid' = :sdk_file_id", [':sdk_file_id' => $sdkFileId]);
        }

        foreach ($query->orderBy(['msg_time' => SORT_DESC])->limit(20)->getAll() as $candidate) {
            $storage = self::findAvailableStorage($candidate->get('msg_content'), $rawContent);
            if ($storage !== null) {
                return $storage;
            }
        }

        return null;
    }

    private static function findAvailableStorage(string $hash, array $rawContent): ?StorageModel
    {
        $expectedSize = $rawContent['filesize'] ?? null;
        foreach (StorageModel::query()->where(['hash' => $hash])->orderBy(['id' => SORT_DESC])->limit(500)->getAll() as $storage) {
            if (is_numeric($expectedSize) && (int)$expectedSize > 0 && (int)$storage->get('file_size') !== (int)$expectedSize) {
                continue;
            }

            if (!$storage->get('is_deleted_local')) {
                $bucket = $storage->get('local_storage_bucket');
                $key = $storage->get('local_storage_object_key');
                if ($bucket && $key && StorageService::getLocalS3Client()->doesObjectExistV2($bucket, $key)) {
                    return $storage;
                }
            }

            if ($storage->get('cloud_storage_setting_id') && $storage->get('cloud_storage_object_key')) {
                $setting = CloudStorageSettingModel::query()->where(['id' => $storage->get('cloud_storage_setting_id')])->getOne();
                if ($setting && StorageService::getCloudS3Client($setting)->doesObjectExistV2($setting->get('bucket'), $storage->get('cloud_storage_object_key'))) {
                    return $storage;
                }
            }
        }

        return null;
    }

    private static function downloadMedia(CorpModel $corp, ChatMessageModel $message, string $sdkFileId, string $sourceMd5): void
    {
        $fileName = Uuid::uuid4();
        $fileExtension = "";

        if ($message->get('msg_type') == 'file') {
            $fileName = $message->get('raw_content')['filename'] ?? 'default';
            $fileExtension = $message->get('raw_content')['fileext'] ?? 'default';
        } elseif ($message->get('msg_type') == 'image') {
            $fileName = Uuid::uuid4() . '.png';
            $fileExtension = "png";
        } elseif ($message->get('msg_type') == 'voice') {
            $fileName = Uuid::uuid4() . '.amr';
            $fileExtension = 'amr';
        } elseif ($message->get('msg_type') == 'video') {
            $fileName = Uuid::uuid4() . '.mp4';
            $fileExtension = 'mp4';
        } elseif ($message->get('msg_type') == 'emotion') {
            $type = $message->get('raw_content')['type'] ?? 2;
            $fileExtension = $type == 1 ? 'gif' : 'png';
            $fileName = Uuid::uuid4() . "." . $fileExtension;
        } elseif ($message->get('msg_type') == 'meeting_voice_call') {
            $fileName = $message->get('raw_content')['voiceid'] . '.mp3';
            $fileExtension = "mp4";
        }

        // 消息级固定对象键：上传成功但数据库写入失败时，重试不会占用新的物理对象。
        $objectKey = sprintf('chat-media/%s/%s/%s',
            hash('sha256', (string)$corp->get('id')),
            hash('sha256', (string)$message->get('msg_id')),
            $message->get('msg_type')
        );
        $stored = StorageModel::query()->where([
            'local_storage_bucket' => StorageModel::SESSION_BUCKET,
            'local_storage_object_key' => $objectKey,
        ])->orderBy(['id' => SORT_DESC])->getOne();

        $client = StorageService::getLocalS3Client();
        $fileInfo = null;
        if ($client->doesObjectExistV2(StorageModel::SESSION_BUCKET, $objectKey)) {
            if ($stored !== null) {
                $fileInfo = [
                    'hash' => $stored->get('hash'),
                    'size' => $stored->get('file_size'),
                    'mime' => $stored->get('mime_type'),
                ];
            } else {
                // 分片上传的 ETag 不是文件 MD5；回读对象计算真实内容哈希。
                $head = $client->headObject(['Bucket' => StorageModel::SESSION_BUCKET, 'Key' => $objectKey]);
                $downloaded = $client->getObject(['Bucket' => StorageModel::SESSION_BUCKET, 'Key' => $objectKey]);
                $body = $downloaded['Body'];
                $digest = hash_init('md5');
                $actualSize = 0;
                try {
                    while (!$body->eof()) {
                        $chunk = $body->read(1024 * 1024);
                        if ($chunk === '') {
                            break;
                        }
                        hash_update($digest, $chunk);
                        $actualSize += strlen($chunk);
                    }
                } finally {
                    $body->close();
                }
                $fileInfo = [
                    'hash' => hash_final($digest),
                    'size' => $actualSize,
                    'mime' => (string)$head['ContentType'],
                ];
                if ($actualSize !== (int)$head['ContentLength'] || ($sourceMd5 && $fileInfo['hash'] !== $sourceMd5)) {
                    $fileInfo = null;
                }
            }

            $expectedSize = $message->get('raw_content')['filesize'] ?? null;
            if ($fileInfo !== null && is_numeric($expectedSize) && (int)$expectedSize > 0 && (int)$fileInfo['size'] !== (int)$expectedSize) {
                $fileInfo = null;
            }
        }

        if ($fileInfo === null || empty($fileInfo['hash'])) {
            $request = [
                'corp_id' => $corp->get('id'),
                'chat_secret' => $corp->get('chat_secret'),
                'sdk_file_id' => $sdkFileId,

                'storage_endpoint' => Yii::params()['local-storage']['endpoint'],
                'storage_region' => Yii::params()['local-storage']['region'],
                'storage_access_key' => Yii::params()['local-storage']['access_key'],
                'storage_secret_key' => Yii::params()['local-storage']['secret_key'],
                'storage_bucket_name' => StorageModel::SESSION_BUCKET,
                'storage_object_key' => $objectKey,
            ];
            $fileInfo = Micro::call('wxfinance', 'FetchAndStreamMediaData', json_encode($request), 500);
        }
        if (empty($fileInfo) || empty($fileInfo['hash'])) {
            throw new LogicException("下载资源失败");
        }

        if ($stored !== null) {
            $fileName = $stored->get('original_filename') ?: $fileName;
            $fileExtension = $stored->get('file_extension') ?: $fileExtension;
        }
        $retentionDays = (int)SettingModel::getValue('local_session_file_retention_days');
        $storageData = [
            'hash'                          => $fileInfo['hash'],
            'original_filename'             => $fileName,
            'file_extension'                => $fileExtension,
            'mime_type'                     => $fileInfo['mime'] ?? '',
            'file_size'                     => $fileInfo['size'] ?? 0,
            'is_deleted_local'              => false,
            'local_storage_bucket'          => StorageModel::SESSION_BUCKET,
            'local_storage_object_key'      => $objectKey,
            'local_storage_expired_at'      => $retentionDays > 0 ? Carbon::now()->addDays($retentionDays)->toDateTimeString('m') : null,
        ];
        if ($stored !== null) {
            $stored->update($storageData);
            $storage = $stored;
        } else {
            $storage = StorageModel::create($storageData);
        }
        $message->update(['msg_content' => $fileInfo['hash']]);

        // 异步保存到云存储
        Producer::dispatch(UploadStorageToCloudConsumer::class, ['storage' => $storage]);
    }

    /**
     * 从企微拉取消息
     * 由golang处理并自动解密
     */
    private static function fetchMessages(int $chatSeq)
    {
        $request = [
            'corp_id' => self::$corp->get('id'),
            'chat_secret' => self::$corp->get('chat_secret'),
            'chat_private_key' => self::$corp->get('chat_private_key'),
            'chat_public_key_version' => self::$corp->get('chat_public_key_version'),
            'chat_seq' => $chatSeq,
            'limit' => self::MESSAGE_LIMIT,
        ];

        $result = [];
        Yii::getNatsClient()->request('wxfinance.FetchData', json_encode($request), function (Payload $payload) use (&$result) {
            $result = json_decode($payload->body, true);
        });
        return $result;
    }

    /**
     * 检查消息格式是否合法以及消息是否存在
     * @throws Throwable
     */
    private static function isValidMessage(array $msg): bool
    {
        // 缺少字段的忽略
        if (empty($msg['decrypted_data']) || empty($msg['msgid']) || empty($msg['seq'])) {
            return false;
        }

        // 解密失败的忽略
        $decryptedData = json_decode($msg['decrypted_data'], true);
        if (empty($decryptedData['msgtime']) || empty($decryptedData['from']) || empty($decryptedData['tolist'])) {
            return false;
        }

        // 重复消息忽略
        $old = ChatMessageModel::query()
            ->where(['and',
                ['msg_id' => $msg['msgid']],
                ['msg_time' => Carbon::createFromTimestampMsUTC($decryptedData['msgtime'])->timezone('Asia/Shanghai')->format('Y-m-d H:i:s.v')],
            ])
            ->getOne();
        if (!empty($old)) {
            return false;
        }

        // 不在会话存档中的员工的消息忽略掉
        $inArchive = false;
        $validStaffList = StaffModel::query()
            ->select('userid')
            ->where(["chat_status" => 1])
            // ->andWhere(['enable_archive' => true])
            ->all();
        $validStaffList = array_column($validStaffList, 'userid');
        if (in_array($decryptedData['from'], $validStaffList)) {
            $inArchive = true;
        }
        foreach ($decryptedData['tolist'] as $to) {
            if (in_array($to, $validStaffList)) {
                $inArchive = true;
                break;
            }
        }
        if (!$inArchive) {
            return false;
        }

        return true;
    }

    /**
     * 保存会话
     * @throws Throwable
     */
    private static function saveConversation(ChatMessageModel $messageData): ChatConversationsModel
    {
        $idList = [self::$corp->get('id')];
        if (!empty($messageData->get('roomid'))) {
            $type = EnumChatConversationType::Group;
            $idList[] = $messageData->get('roomid');
        } else {
            if (self::checkIsExternal($messageData->get('from'), $messageData->get('to_list')[0])) {
                $type = EnumChatConversationType::Single;
            } else {
                $type = EnumChatConversationType::Internal;
            }
            $idList[] = $messageData->get('from');
            $idList[] = $messageData->get('to_list')[0];
        }
        sort($idList);
        $id = md5(implode('', $idList));

        $conversation = ChatConversationsModel::query()->where(['id' => $id])->getOne();
        if (empty($conversation)) {
            if (self::hasExternalPrefix($messageData->get('from'))) {
                $fromRole = EnumChatMessageRole::Customer;
                CustomersModel::hasConversationSave(self::$corp, $messageData->get('from'));
            } else {
                $fromRole = EnumChatMessageRole::Staff;
                StaffModel::hasConversationSave(self::$corp, $messageData->get('from'));
            }

            if ($type == EnumChatConversationType::Group) {
                $toRole = EnumChatMessageRole::Group;
                GroupModel::hasConversationSave(self::$corp, $messageData->get('roomid'));
            } else {
                if (self::hasExternalPrefix($messageData->get('to_list')[0])) {
                    $toRole = EnumChatMessageRole::Customer;
                    CustomersModel::hasConversationSave(self::$corp, $messageData->get('to_list')[0]);
                } else {
                    $toRole = EnumChatMessageRole::Staff;
                    StaffModel::hasConversationSave(self::$corp, $messageData->get('to_list')[0]);
                }
            }

            $data = [
                'id' => $id,
                'corp_id' => self::$corp->get('id'),
                'type' => $type,
                'from' => $messageData->get('from'),
                'from_role' => $fromRole,
                'to' => $type == EnumChatConversationType::Group ? $messageData->get('roomid') : $messageData->get('to_list')[0],
                'to_role' => $toRole,
                'last_msg_time' => $messageData->get('msg_time'),
            ];
            if ($data['from_role'] == EnumChatMessageRole::Staff) {
                $data['staff_last_reply_time'] = Carbon::now()->format('Y-m-d H:i:s.v');
            }
            $conversation = ChatConversationsModel::create($data);
        } else {
            $data = ['last_msg_time' => $messageData->get('msg_time')];
            if ($messageData->get('from_role') == EnumChatMessageRole::Staff) {
                $data['staff_last_reply_time'] = Carbon::now()->format('Y-m-d H:i:s.v');
            }
            $conversation->update($data);
        }

        return $conversation;
    }

    /**
     * 对消息内容进行处理
     * 统一成能够被保存到数据库中的字段格式
     * @throws Throwable
     */
    private static function processMessage(array $msg): ?ChatMessageModel
    {
        $decryptedData = json_decode($msg['decrypted_data'], true);
        $msgType = $decryptedData['msgtype'] ?? '';
        $enumMsgType = EnumMessageType::tryFrom($msgType);
        if (!$enumMsgType) {
            return null;
        }
        $content = $enumMsgType->getMessageHandler()($decryptedData);

        $messageData = ChatMessageModel::create(array_merge([
            'corp_id' => self::$corp->get('id'),
            'msg_id' => $msg['msgid'],
            'seq' => $msg['seq'],
            'public_key_ver' => self::$corp->get('chat_public_key_version'),
            'action' => $decryptedData['action'] ?? '',
            'from' => $decryptedData['from'] ?? '',
            'to_list' => $decryptedData['tolist'] ?? [],
            'msg_type' => $decryptedData['msgtype'] ?? '',
            'roomid' => $decryptedData['roomid'] ?? '',
            'msg_time' => Carbon::createFromTimestampMsUTC($decryptedData['msgtime'])->timezone('Asia/Shanghai')->format('Y-m-d H:i:s.v'),
        ], $content));
        if (self::hasExternalPrefix($messageData->get('from'))) {
            $messageData->set('from_role', EnumChatMessageRole::Customer);
        } else {
            $messageData->set('from_role', EnumChatMessageRole::Staff);
        }

        if (!empty($messageData->get('roomid'))) {
            $messageData->set('to_role', EnumChatMessageRole::Group);
        } else {
            if (self::hasExternalPrefix($messageData->get('to_list')[0])) {
                $messageData->set('to_role', EnumChatMessageRole::Customer);
            } else {
                $messageData->set('to_role', EnumChatMessageRole::Staff);
            }
        }
        $messageData->save();

        return $messageData;
    }

    public static function hasExternalPrefix($id): bool
    {
        $prefixList = ['wo', 'wm'];
        foreach ($prefixList as $prefix) {
            if (str_starts_with($id, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查是否为外部联系人聊天
     */
    public static function checkIsExternal(string $from, string $to): bool
    {
        $length = 32;
        foreach ([[$from, $length], [$to, $length]] as [$id, $length]) {
            if (strlen($id) === $length && self::hasExternalPrefix($id)) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace Modules\Main\Service;

use Aws\S3\S3Client;
use Aws\S3\MultipartUploader;
use Carbon\Carbon;
use Common\Yii;
use Exception;
use Modules\Main\Model\CloudStorageSettingModel;
use Modules\Main\Model\StorageModel;
use Throwable;

class StorageService
{
    /**
     * 生成对象键
     */
    public static function generateObjectKey(string $fileName, string $md5): string
    {
        $now = Carbon::now();
        return sprintf("%d/%02d/%02d/%s/%s", $now->year, $now->month, $now->day, $md5, basename($fileName));
    }

    public static function getLocalS3Client()
    {
        return new S3Client([
            'version' => 'latest',
            'region' => Yii::params()['local-storage']['region'],
            'endpoint' => Yii::params()['local-storage']['endpoint'],
            'credentials' => [
                'key'    => Yii::params()['local-storage']['access_key'],
                'secret' => Yii::params()['local-storage']['secret_key'],
            ],
            'use_path_style_endpoint' => true,
        ]);
    }

    public static function getCloudS3Client(CloudStorageSettingModel $setting)
    {
        $usePathStyleEndpoint = false;
        if ($setting->get('provider') == 'MinIO') {
            $usePathStyleEndpoint = true;
        }
        return new S3Client([
            'version' => 'latest',
            'region' => $setting->get('region'),
            'endpoint' => $setting->get('endpoint'),
            'credentials' => [
                'key'    => $setting->get('access_key'),
                'secret' => $setting->get('secret_key'),
            ],
            'use_path_style_endpoint' => $usePathStyleEndpoint,
        ]);
    }

    /**
     * 初始化本地存储
     *
     * @throws Exception
     */
    public static function initLocalBucket(): void
    {
        $s3Client = self::getLocalS3Client();
        foreach (StorageModel::LOCAL_BUCKET_LIST as $bucket) {
            if (!$s3Client->doesBucketExist($bucket)) {
                $s3Client->createBucket([
                    'Bucket' => $bucket,
                ]);
            }
            $s3Client->putBucketPolicy([
                'Bucket' => $bucket,
                'Policy' => json_encode([
                    'Version' => '2012-10-17',
                    'Statement' => [
                        [
                            'Sid' => 'PublicRead',
                            'Effect' => 'Allow',
                            'Principal' => '*',
                            'Action' => ['s3:GetObject'],
                            'Resource' => ["arn:aws:s3:::$bucket/*"],
                        ]
                    ]
                ])
            ]);
        }
    }

    /**
     * 保存文件到本地对象存储
     *
     * @throws Throwable
     */
    public static function saveLocal(string $filePath, string $bucketName = StorageModel::DEFAULT_BUCKET, ?int $preserveSeconds = 0): StorageModel
    {
        if (! file_exists($filePath)) {
            throw new Exception("文件{$filePath}不存在");
        }

        $objectKey = self::generateObjectKey($filePath, md5_file($filePath));

        $s3Client = self::getLocalS3Client();
        $s3Client->putObject([
            'Bucket' => $bucketName,
            'Key' => $objectKey,
            'SourceFile' => $filePath,
        ]);

        $expiredAt = null;
        if ($preserveSeconds > 0) {
            $expiredAt = Carbon::now()->addSeconds($preserveSeconds);
        }

        $result = StorageModel::create([
            'hash'                      => hash_file('md5', $filePath),
            'original_filename'         => basename($filePath),
            'file_extension'            => pathinfo($filePath, PATHINFO_EXTENSION),
            'mime_type'                 => mime_content_type($filePath) ?: "application/octet-stream",
            'file_size'                 => filesize($filePath),
            'local_storage_bucket'      => $bucketName,
            'local_storage_object_key'  => $objectKey,
            'local_storage_expired_at'  => $expiredAt,
        ]);
        @unlink($filePath);

        return $result;
    }

    /**
     * 从本地对象存储删除文件
     *
     * @throws Throwable
     */
    public static function removeExpiredLocalFile(StorageModel $model): void
    {
        if (getenv('QIWEIDOC_LOCAL_PURGE_ENABLED') !== '1') {
            return;
        }

        if (!$model->get('is_deleted_local') && $model->get('local_storage_expired_at')
            && $model->get('local_storage_expired_at') < now()
            && $model->get('cloud_storage_setting_id') && $model->get('cloud_storage_object_key')) {
            $setting = CloudStorageSettingModel::query()->where(['id' => $model->get('cloud_storage_setting_id')])->getOne();
            if (!$setting || !self::getCloudS3Client($setting)->doesObjectExistV2($setting->get('bucket'), $model->get('cloud_storage_object_key'))) {
                throw new Exception('云端对象不可访问，保留本地文件');
            }
            $s3Client = self::getLocalS3Client();
            $s3Client->deleteObject([
                'Bucket' => $model->get('local_storage_bucket'),
                'Key' => $model->get('local_storage_object_key'),
            ]);
            $model->update(['is_deleted_local' => true]);
        }
    }

    /**
     * 保存文件到云存储
     *
     * @throws Throwable
     */
    public static function saveCloud(StorageModel $model): void
    {
        if ($model->get('cloud_storage_setting_id') && $model->get('cloud_storage_object_key')) {
            return;
        }

        $cloudStorageSetting = CloudStorageSettingModel::query()->orderBy(['id' => SORT_DESC])->getOne();
        if (empty($cloudStorageSetting)) {
            return;
        }

        $localBucket = $model->get('local_storage_bucket');
        $localKey = $model->get('local_storage_object_key');
        $cloudBucket = $cloudStorageSetting->get('bucket');
        $size = (int)$model->get('file_size');
        $savedHash = strtolower((string)$model->get('hash'));
        if (!preg_match('/^[a-f0-9]{32}$/', $savedHash) || $size < 0) {
            throw new Exception('存储记录缺少合法 MD5 或文件大小');
        }
        $cloudClient = self::getCloudS3Client($cloudStorageSetting);
        $savedHashKey = sprintf('content/md5/%s/%s', substr($savedHash, 0, 2), $savedHash);
        $verified = StorageModel::query()->where([
            'hash' => $model->get('hash'),
            'file_size' => $size,
            'cloud_storage_setting_id' => $cloudStorageSetting->get('id'),
            'cloud_storage_object_key' => $savedHashKey,
        ])->getOne();
        if ($verified !== null) {
            $head = $cloudClient->headObject(['Bucket' => $cloudBucket, 'Key' => $savedHashKey]);
            if ((int)$head['ContentLength'] !== $size) {
                throw new Exception('已复用云端对象的大小不一致，停止关联');
            }
            $model->update([
                'cloud_storage_setting_id' => $cloudStorageSetting->get('id'),
                'cloud_storage_object_key' => $savedHashKey,
            ]);
            return;
        }

        // 旧版分片上传曾把 ETag 前段写入 hash。该字段也被消息引用，不能改写；
        // 云端键必须使用源文件逐字节计算出的真实 MD5。
        $actualHash = $savedHash;
        if (!$model->get('is_deleted_local')) {
            $localClient = self::getLocalS3Client();
            $sourceHead = $localClient->headObject(['Bucket' => $localBucket, 'Key' => $localKey]);
            if ((int)$sourceHead['ContentLength'] !== $size) {
                throw new Exception('本地对象大小与数据库不一致，停止云端复制');
            }
            [$actualHash, $sourceSize] = self::hashObject($localClient, $localBucket, $localKey);
            if ($sourceSize !== $size) {
                throw new Exception('本地对象读取大小与数据库不一致，停止云端复制');
            }
        }
        $cloudObjectKey = sprintf('content/md5/%s/%s', substr($actualHash, 0, 2), $actualHash);
        $uploaded = false;
        if (!$cloudClient->doesObjectExistV2($cloudBucket, $cloudObjectKey)) {
            if ($model->get('is_deleted_local')) {
                throw new Exception('本地对象已删除，不能重新复制到云端');
            }
            $source = $localClient->getObject(['Bucket' => $localBucket, 'Key' => $localKey]);
            $body = $source['Body'];
            try {
                $mime = $model->get('mime_type') ?: 'application/octet-stream';
                if ($size >= 16 * 1024 * 1024) {
                    (new MultipartUploader($cloudClient, $body, [
                        'bucket' => $cloudBucket,
                        'key' => $cloudObjectKey,
                        'part_size' => 16 * 1024 * 1024,
                        'concurrency' => 2,
                        'before_initiate' => static function ($command) use ($mime): void {
                            $command['ContentType'] = $mime;
                        },
                    ]))->upload();
                } else {
                    $cloudClient->putObject([
                        'Bucket' => $cloudBucket,
                        'Key' => $cloudObjectKey,
                        'Body' => $body,
                        'ContentLength' => $size,
                        'ContentType' => $mime,
                    ]);
                }
                $uploaded = true;
            } finally {
                $body->close();
            }
        }

        // 分片 ETag 不是文件 MD5；回读云端对象并逐块计算哈希，校验通过后才记账。
        try {
            [$copiedHash, $copiedSize] = self::hashObject($cloudClient, $cloudBucket, $cloudObjectKey);
            if ($copiedSize !== $size || $copiedHash !== $actualHash) {
                throw new Exception('云端对象校验失败，未更新数据库');
            }
        } catch (Throwable $error) {
            if ($uploaded) {
                try {
                    $cloudClient->deleteObject(['Bucket' => $cloudBucket, 'Key' => $cloudObjectKey]);
                } catch (Throwable $cleanupError) {
                    throw new Exception('云端对象校验失败且新上传对象清理失败：' . $cleanupError->getMessage(), 0, $error);
                }
            }
            throw $error;
        }

        $model->update([
            'cloud_storage_setting_id' => $cloudStorageSetting->get('id'),
            'cloud_storage_object_key' => $cloudObjectKey,
        ]);
    }

    private static function hashObject(S3Client $client, string $bucket, string $key): array
    {
        $response = $client->getObject(['Bucket' => $bucket, 'Key' => $key]);
        $body = $response['Body'];
        $digest = hash_init('md5');
        $size = 0;
        try {
            while (!$body->eof()) {
                $chunk = $body->read(1024 * 1024);
                if ($chunk === '') {
                    break;
                }
                hash_update($digest, $chunk);
                $size += strlen($chunk);
            }
        } finally {
            $body->close();
        }
        return [hash_final($digest), $size];
    }

    /**
     * 生成生成对象的下载链接
     * 默认生成本地存储的下载链接
     * 如果本地文件已过期则取云存储的下载链接
     */
    public static function getDownloadUrl(string $hash): string
    {
        $storage = StorageModel::query()->where(['hash' => $hash])->orderBy(['id' => SORT_DESC])->getOne();
        if (empty($storage)) {
            return "";
        }

        if (!$storage->get('is_deleted_local')) { // 本地文件路径特殊处理,直接走内部代理访问
            $s3Client = self::getLocalS3Client();
            $cmd = $s3Client->getCommand('GetObject', [
                'Bucket' => $storage->get('local_storage_bucket'),
                'Key'    => $storage->get('local_storage_object_key'),
            ]);
            $request = $s3Client->createPresignedRequest($cmd, '+1 hour');

            return self::convertMinioUrlToLocalPath($request->getUri());
        } elseif (!empty($storage->get('cloud_storage_object_key')) && $setting = CloudStorageSettingModel::query()->where(['id' => $storage->get('cloud_storage_setting_id')])->getOne()) { // 云存储的话生成预签名地址
            /* @var CloudStorageSettingModel $setting */
            $s3Client = self::getCloudS3Client($setting);
            $cmd = $s3Client->getCommand('GetObject', [
                'Bucket' => $setting->get('bucket'),
                'Key'    => $storage->get('cloud_storage_object_key'),
            ]);
            $request = $s3Client->createPresignedRequest($cmd, '+1 hour');

            return (string) $request->getUri();
        } else {
            return "";
        }
    }

    /**
     * 根据哈希值下载对象内容
     * 优先从本地 MinIO 读取，若本地文件已删除且存在云端副本，则从云端读取
     *
     * @throws Throwable
     */
    public static function downloadObjectContent(string $hash): string
    {
        $storage = StorageModel::query()->where(['hash' => $hash])->orderBy(['id' => SORT_DESC])->getOne();
        if (empty($storage)) {
            throw new Exception('文件不存在');
        }

        // 读取本地 MinIO
        if (!$storage->get('is_deleted_local')) {
            $s3Client = self::getLocalS3Client();
            $result = $s3Client->getObject([
                'Bucket' => $storage->get('local_storage_bucket'),
                'Key'    => $storage->get('local_storage_object_key'),
            ]);
            return (string) $result['Body'];
        }

        // 读取云端（如已迁移）
        if (!empty($storage->get('cloud_storage_object_key')) && $setting = CloudStorageSettingModel::query()->where(['id' => $storage->get('cloud_storage_setting_id')])->getOne()) {
            /* @var CloudStorageSettingModel $setting */
            $s3Client = self::getCloudS3Client($setting);
            $result = $s3Client->getObject([
                'Bucket' => $setting->get('bucket'),
                'Key'    => $storage->get('cloud_storage_object_key'),
            ]);
            return (string) $result['Body'];
        }

        throw new Exception('文件内容不存在或已被清理');
    }

    /**
     * 本地文件特殊处理
     */
    private static function convertMinioUrlToLocalPath($minioUrl): string
    {
        $urlParts = parse_url($minioUrl);
        if ($urlParts === false || !isset($urlParts['path'])) {
            return "";
        }
        $pathParts = explode('/', trim($urlParts['path'], '/'));
        $newPath = '/storage/' . implode('/', $pathParts);
        if (isset($urlParts['query'])) {
            $newPath .= '?' . $urlParts['query'];
        }

        return $newPath;
    }
}

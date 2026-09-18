<?php

// Copyright © 2016- 2025 Sesame Network Technology all right reserved

declare(strict_types=1);

namespace Common\Command;

use Carbon\Carbon;
use Common\Job\Producer;
use Modules\Main\Consumer\DownloadChatSessionBitMediasConsumer;
use Modules\Main\Consumer\DownloadChatSessionMediasConsumer;
use Modules\Main\Model\ChatMessageModel;
use Modules\Main\Model\CorpModel;
use Modules\Main\Service\ChatSessionPullService;
use Modules\Main\Service\ChatSessionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(name: 'download-message-media', description: 'download message media', hidden: false)]
class DownloadMessageMediasCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('seq', null, InputOption::VALUE_REQUIRED, '仅检查指定 seq 的待下载媒体；默认不投递任务');
        $this->addOption('dispatch', null, InputOption::VALUE_NONE, '投递指定 seq 的补拉任务，另需 QIWEIDOC_MEDIA_REPLAY_ENABLED=1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $seqOption = $input->getOption('seq');
        $dispatch = (bool)$input->getOption('dispatch');
        if ($dispatch && $seqOption === null) {
            $output->writeln('单条补拉必须指定 --seq');
            return Command::FAILURE;
        }
        if ($seqOption !== null && (!ctype_digit((string)$seqOption) || (int)$seqOption <= 0 || (string)(int)$seqOption !== (string)$seqOption)) {
            $output->writeln('seq 必须是正整数');
            return Command::FAILURE;
        }

        $corp = CorpModel::query()->getOne();
        if (empty($corp)) {
            return ExitCode::OK;
        }

        if ($seqOption !== null) {
            $message = ChatMessageModel::query()
                ->where(['corp_id' => $corp->get('id'), 'seq' => (int)$seqOption, 'msg_content' => ''])
                ->andWhere(['in', 'msg_type', ChatSessionService::ValidMediaType])
                ->getOne();
            if ($message === null) {
                $output->writeln('未找到该 seq 的待下载媒体；没有投递任务');
                return Command::FAILURE;
            }
            $rawContent = $message->get('raw_content') ?: [];
            if (empty($rawContent['sdkfileid'])) {
                $output->writeln('该媒体缺少 sdkfileid；没有投递任务');
                return Command::FAILURE;
            }
            $output->writeln(sprintf(
                '待补拉媒体 seq=%d，类型=%s，时间=%s，声明大小=%s 字节',
                (int)$seqOption,
                (string)$message->get('msg_type'),
                (string)$message->get('msg_time'),
                isset($rawContent['filesize']) ? (string)$rawContent['filesize'] : '未知',
            ));
            if (!$dispatch) {
                $output->writeln('仅预览；未投递任务');
                return ExitCode::OK;
            }
            if (getenv('QIWEIDOC_MEDIA_REPLAY_ENABLED') !== '1') {
                $output->writeln('补拉开关未开启；没有投递任务');
                return Command::FAILURE;
            }
            self::dispatchMedia($corp, $message);
            $output->writeln('已投递单条补拉任务；完成状态需以消息附件和对象校验为准');
            return ExitCode::OK;
        }

        $messages = ChatMessageModel::query()
            ->where(['corp_id' => $corp->get('id')])
            ->andWhere(['in', 'msg_type', ChatSessionService::ValidMediaType])
            ->andWhere(['msg_content' => ''])
            ->andWhere(['<', 'msg_time', Carbon::now()->subHour()->toDateTimeString('millisecond')])
            ->andWhere(['>', 'msg_time', Carbon::now()->subDays(5)->toDateTimeString('millisecond')])
            ->orderBy(['msg_time' => SORT_ASC])
            ->limit(1000)
            ->getAll();
        foreach ($messages as $message) {
            self::dispatchMedia($corp, $message);
        }

        return ExitCode::OK;
    }

    private static function dispatchMedia(CorpModel $corp, ChatMessageModel $message): void
    {
        if (ChatSessionPullService::isLargeFile($message)) {
            Producer::dispatch(DownloadChatSessionBitMediasConsumer::class, ['corp' => $corp, 'message' => $message]);
        } else {
            Producer::dispatch(DownloadChatSessionMediasConsumer::class, ['corp' => $corp, 'message' => $message]);
        }
    }
}

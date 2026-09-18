<?php

// Copyright © 2016- 2025 Sesame Network Technology all right reserved

declare(strict_types=1);

namespace Common\Command;

use Common\Job\Producer;
use Modules\Main\Consumer\UploadStorageToCloudConsumer;
use Modules\Main\Model\CloudStorageSettingModel;
use Modules\Main\Model\CorpModel;
use Modules\Main\Model\StorageModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(name: 'upload-message-media', description: 'upload message media', hidden: false)]
class UploadMessageMediasCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (getenv('QIWEIDOC_CLOUD_BACKFILL_ENABLED') !== '1') {
            $output->writeln('历史云端复制未启用：设置 QIWEIDOC_CLOUD_BACKFILL_ENABLED=1 后再执行');
            return ExitCode::OK;
        }

        $corp = CorpModel::query()->getOne();
        if (empty($corp)) {
            return ExitCode::OK;
        }

        $cloudStorageSetting = CloudStorageSettingModel::query()->orderBy(['id' => SORT_DESC])->getOne();
        if (empty($cloudStorageSetting)) {
            return ExitCode::OK;
        }

        $storages = StorageModel::query()
            ->where(['cloud_storage_setting_id' => 0])
            ->orderBy(['id' => SORT_ASC])
            ->limit(1000)
            ->getAll();
        foreach ($storages as $storage) {
            Producer::dispatch(UploadStorageToCloudConsumer::class, ['storage' => $storage]);
        }

        return ExitCode::OK;
    }
}

<?php

// Copyright © 2016- 2025 Sesame Network Technology all right reserved

declare(strict_types=1);

namespace Common\Command;

use Common\Job\Producer;
use Modules\Main\Consumer\UploadStorageToCloudConsumer;
use Modules\Main\Model\CloudStorageSettingModel;
use Modules\Main\Model\CorpModel;
use Modules\Main\Model\StorageModel;
use Modules\Main\Service\StorageService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(name: 'upload-message-media', description: 'upload message media', hidden: false)]
class UploadMessageMediasCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('storage-id', null, InputOption::VALUE_REQUIRED, '仅检查指定 storage ID；默认不复制');
        $this->addOption('execute', null, InputOption::VALUE_NONE, '复制指定 storage ID，另需 QIWEIDOC_CLOUD_COPY_ENABLED=1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storageIdOption = $input->getOption('storage-id');
        $execute = (bool)$input->getOption('execute');
        if ($execute && $storageIdOption === null) {
            $output->writeln('单对象复制必须指定 --storage-id');
            return Command::FAILURE;
        }
        if ($storageIdOption !== null && (!ctype_digit((string)$storageIdOption)
            || (int)$storageIdOption <= 0
            || (string)(int)$storageIdOption !== (string)$storageIdOption)) {
            $output->writeln('storage-id 必须是正整数');
            return Command::FAILURE;
        }

        if ($storageIdOption !== null) {
            $storage = StorageModel::query()->where(['id' => (int)$storageIdOption])->getOne();
            if ($storage === null) {
                $output->writeln('未找到该 storage ID；没有复制对象');
                return Command::FAILURE;
            }
            $output->writeln(sprintf(
                '媒体 storage_id=%d，大小=%d 字节，云端状态=%s',
                (int)$storage->get('id'),
                (int)$storage->get('file_size'),
                $storage->get('cloud_storage_setting_id') && $storage->get('cloud_storage_object_key') ? '已关联' : '未关联',
            ));
            if (!$execute) {
                $output->writeln('仅预览；未复制对象');
                return ExitCode::OK;
            }
            if (getenv('QIWEIDOC_CLOUD_COPY_ENABLED') !== '1') {
                $output->writeln('云复制开关未开启；没有复制对象');
                return Command::FAILURE;
            }
            StorageService::saveCloud($storage);
            if (!$storage->get('cloud_storage_setting_id') || !$storage->get('cloud_storage_object_key')) {
                $output->writeln('复制未生成云端定位；请检查配置和日志');
                return Command::FAILURE;
            }
            $output->writeln('单对象复制完成；仍需回读字节、数据库引用和业务下载验收');
            return ExitCode::OK;
        }

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

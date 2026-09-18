<?php

namespace Modules\Main\Consumer;

use Modules\Main\Model\StorageModel;
use Modules\Main\Service\StorageService;
use Throwable;

readonly class UploadStorageToCloudConsumer
{
    private StorageModel $storage;

    public function __construct(StorageModel $storage)
    {
        $this->storage = $storage;
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        // 新媒体和历史回填都进入此队列；只有明确启用云复制后才消费。
        if (getenv('QIWEIDOC_CLOUD_COPY_ENABLED') !== '1') {
            return;
        }
        StorageService::saveCloud($this->storage);
    }
}

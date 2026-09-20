<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Allow production OSS bucket names accepted by CloudStorageSettingDTO.
 */
final class M260921004500ExpandCloudStorageBucket implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        migrate_exec(
            $b,
            'alter table main.cloud_storage_setting alter column bucket type varchar(64)'
        );
    }

    public function down(MigrationBuilder $b): void
    {
        migrate_exec(
            $b,
            'alter table main.cloud_storage_setting alter column bucket type varchar(32)'
        );
    }
}

<?php
// Standalone in-memory command test: no database, queue, OSS, or local service.

namespace Pilot {
    final class State
    {
        public static array $storages = [];
        public static int $copies = 0;
    }

    class Row
    {
        public function __construct(public array $values) {}
        public function get(string $key): mixed { return $this->values[$key] ?? null; }
        public function update(array $values): void { $this->values = array_replace($this->values, $values); }
    }

    final class Query
    {
        private array $filters = [];
        public function where(array $filters): self { $this->filters = $filters; return $this; }
        public function getOne(): ?Row
        {
            foreach (State::$storages as $row) {
                foreach ($this->filters as $key => $value) {
                    if ($row->get($key) !== $value) continue 2;
                }
                return $row;
            }
            return null;
        }
    }

    function check(bool $condition, string $label): void
    {
        if (!$condition) throw new \RuntimeException($label);
        echo "PASS: {$label}\n";
    }
}

namespace Modules\Main\Model {
    final class StorageModel extends \Pilot\Row
    {
        public static function query(): \Pilot\Query { return new \Pilot\Query(); }
    }
    final class CloudStorageSettingModel {}
    final class CorpModel {}
}

namespace Modules\Main\Service {
    final class StorageService
    {
        public static function saveCloud(\Modules\Main\Model\StorageModel $storage): void
        {
            if ($storage->get('cloud_storage_setting_id') && $storage->get('cloud_storage_object_key')) return;
            \Pilot\State::$copies++;
            $storage->update([
                'cloud_storage_setting_id' => 9,
                'cloud_storage_object_key' => 'content/md5/aa/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ]);
        }
        public static function verifyCloud(\Modules\Main\Model\StorageModel $storage): array
        {
            if (!$storage->get('cloud_storage_object_key')) throw new \RuntimeException('not linked');
            return [
                'object_key' => $storage->get('cloud_storage_object_key'),
                'size' => (int)$storage->get('file_size'),
                'md5' => str_repeat('a', 32),
                'sha256' => str_repeat('b', 64),
            ];
        }
    }
}

namespace {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        $autoload = '/var/www/php/vendor/autoload.php';
    }
    require $autoload;
    require __DIR__ . '/../common/Command/UploadMessageMediasCommand.php';

    $storage = new \Modules\Main\Model\StorageModel([
        'id' => 17,
        'file_size' => 8025,
        'cloud_storage_setting_id' => 0,
        'cloud_storage_object_key' => '',
    ]);
    \Pilot\State::$storages[] = $storage;
    $command = new \Common\Command\UploadMessageMediasCommand();
    $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
    putenv('QIWEIDOC_CLOUD_COPY_ENABLED');

    \Pilot\check($tester->execute(['--storage-id' => '17']) === 0, 'single storage can be previewed');
    \Pilot\check(str_contains($tester->getDisplay(), '仅预览'), 'preview clearly says no object was copied');
    \Pilot\check(\Pilot\State::$copies === 0, 'preview performs no copy');
    \Pilot\check($tester->execute(['--storage-id' => '17', '--execute' => true]) !== 0, 'execution is blocked without copy switch');
    \Pilot\check(\Pilot\State::$copies === 0, 'disabled copy switch changes no metadata');

    putenv('QIWEIDOC_CLOUD_COPY_ENABLED=1');
    \Pilot\check($tester->execute(['--storage-id' => '17', '--execute' => true]) === 0, 'enabled pilot copies one exact storage');
    \Pilot\check(\Pilot\State::$copies === 1, 'pilot performs exactly one copy');
    \Pilot\check($storage->get('cloud_storage_setting_id') === 9 && $storage->get('cloud_storage_object_key'), 'successful copy records cloud location');
    \Pilot\check($tester->execute(['--storage-id' => '17', '--execute' => true]) === 0, 'already linked storage remains idempotent');
    \Pilot\check(\Pilot\State::$copies === 1, 'retry creates no second copy');
    \Pilot\check($tester->execute(['--storage-id' => '17', '--verify-cloud' => true]) === 0, 'linked cloud object can be verified read-only');
    \Pilot\check(str_contains($tester->getDisplay(), str_repeat('b', 64)), 'cloud verification prints SHA-256');
    \Pilot\check($tester->execute(['--storage-id' => '17', '--execute' => true, '--verify-cloud' => true]) !== 0, 'copy and verification are mutually exclusive');
    \Pilot\check($tester->execute(['--verify-cloud' => true]) !== 0, 'cloud verification requires one exact storage ID');
    \Pilot\check($tester->execute(['--storage-id' => '18', '--execute' => true]) !== 0, 'missing storage ID cannot be copied');
    \Pilot\check($tester->execute(['--storage-id' => 'bad', '--execute' => true]) !== 0, 'invalid storage ID is rejected');
    \Pilot\check($tester->execute(['--execute' => true]) !== 0, 'execution requires one exact storage ID');
    putenv('QIWEIDOC_CLOUD_COPY_ENABLED');
}

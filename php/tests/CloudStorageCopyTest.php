<?php
// Standalone in-memory test; it never starts a service or reads production data.

namespace Test {
    final class State
    {
        public static array $objects = [];
        public static array $storages = [];
        public static int $localReads = 0;
        public static bool $corruptNextCloudUpload = false;
        public static array $deleted = [];
    }

    final class Body
    {
        private int $offset = 0;

        public function __construct(private string $content) {}
        public function eof(): bool { return $this->offset >= strlen($this->content); }
        public function read(int $length): string
        {
            $part = substr($this->content, $this->offset, $length);
            $this->offset += strlen($part);
            return $part;
        }
        public function close(): void {}
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

        public function __construct(private string $table) {}
        public function orderBy(array $order): self { return $this; }
        public function where(array $filters): self { $this->filters = $filters; return $this; }
        public function getOne(): ?Row
        {
            $rows = $this->table === 'storage' ? State::$storages : [new \Modules\Main\Model\CloudStorageSettingModel([
                'id' => 7, 'provider' => '阿里云', 'region' => 'cn-hangzhou',
                'endpoint' => 'https://cloud.test', 'bucket' => 'private',
                'access_key' => 'fake', 'secret_key' => 'fake',
            ])];
            foreach ($rows as $row) {
                foreach ($this->filters as $key => $value) {
                    if ($row->get($key) !== $value) continue 2;
                }
                return $row;
            }
            return null;
        }
    }

    function expect(bool $condition, string $label): void
    {
        if (!$condition) throw new \RuntimeException($label);
        echo "PASS: {$label}\n";
    }
}

namespace Aws\S3 {
    final class S3Client
    {
        private string $endpoint;
        public function __construct(array $options) { $this->endpoint = $options['endpoint']; }
        private function key(string $bucket, string $key): string { return $this->endpoint . '/' . $bucket . '/' . $key; }
        public function doesObjectExistV2(string $bucket, string $key): bool
        {
            return isset(\Test\State::$objects[$this->key($bucket, $key)]);
        }
        public function headObject(array $request): array
        {
            $value = \Test\State::$objects[$this->key($request['Bucket'], $request['Key'])] ?? null;
            if ($value === null) throw new \RuntimeException('not found');
            return ['ContentLength' => strlen($value)];
        }
        public function getObject(array $request): array
        {
            if ($this->endpoint === 'http://local.test') \Test\State::$localReads++;
            $value = \Test\State::$objects[$this->key($request['Bucket'], $request['Key'])] ?? null;
            if ($value === null) throw new \RuntimeException('not found');
            return ['Body' => new \Test\Body($value)];
        }
        public function putObject(array $request): void
        {
            $body = $request['Body'];
            $value = '';
            while (!$body->eof()) $value .= $body->read(1024);
            if ($this->endpoint === 'https://cloud.test' && \Test\State::$corruptNextCloudUpload) {
                $value = 'corrupt bytes';
                \Test\State::$corruptNextCloudUpload = false;
            }
            \Test\State::$objects[$this->key($request['Bucket'], $request['Key'])] = $value;
        }
        public function deleteObject(array $request): void
        {
            $key = $this->key($request['Bucket'], $request['Key']);
            \Test\State::$deleted[] = $key;
            unset(\Test\State::$objects[$key]);
        }
    }
}

namespace Common {
    final class Yii
    {
        public static function params(): array
        {
            return ['local-storage' => [
                'endpoint' => 'http://local.test', 'region' => 'local',
                'access_key' => 'fake', 'secret_key' => 'fake',
            ]];
        }
    }
}

namespace Modules\Main\Model {
    final class CloudStorageSettingModel
        extends \Test\Row
    {
        public static function query(): \Test\Query { return new \Test\Query('setting'); }
    }
    final class StorageModel
        extends \Test\Row
    {
        public static function query(): \Test\Query { return new \Test\Query('storage'); }
    }
}

namespace {
    require __DIR__ . '/../modules/main/backend/Service/StorageService.php';

    $content = 'cloud migration test';
    $hash = md5($content);
    $values = [
        'id' => 1, 'hash' => $hash, 'file_size' => strlen($content),
        'mime_type' => 'text/plain', 'is_deleted_local' => false,
        'local_storage_bucket' => 'session', 'local_storage_object_key' => 'first',
        'cloud_storage_setting_id' => 0, 'cloud_storage_object_key' => '',
    ];
    $first = new \Modules\Main\Model\StorageModel($values);
    \Test\State::$storages[] = $first;
    \Test\State::$objects['http://local.test/session/first'] = $content;
    \Modules\Main\Service\StorageService::saveCloud($first);
    $cloudKey = 'content/md5/' . substr($hash, 0, 2) . '/' . $hash;
    \Test\expect($first->get('cloud_storage_object_key') === $cloudKey, 'first row uses content-addressed key');
    \Test\expect(\Test\State::$objects['https://cloud.test/private/' . $cloudKey] === $content, 'cloud bytes match source');

    $second = new \Modules\Main\Model\StorageModel(array_replace($values, ['id' => 2, 'local_storage_object_key' => 'missing']));
    \Test\State::$storages[] = $second;
    $reads = \Test\State::$localReads;
    \Modules\Main\Service\StorageService::saveCloud($second);
    \Test\expect($second->get('cloud_storage_object_key') === $cloudKey, 'second row reuses verified cloud object');
    \Test\expect(\Test\State::$localReads === $reads, 'second row needs no local redownload');

    $bad = new \Modules\Main\Model\StorageModel(array_replace($values, ['id' => 3, 'hash' => md5('good'), 'file_size' => 4]));
    \Test\State::$objects['http://local.test/session/first'] = 'good';
    \Test\State::$objects['https://cloud.test/private/content/md5/' . substr($bad->get('hash'), 0, 2) . '/' . $bad->get('hash')] = 'bad!';
    try {
        \Modules\Main\Service\StorageService::saveCloud($bad);
        throw new \RuntimeException('corrupt object was accepted');
    } catch (\Exception $error) {
        \Test\expect(str_contains($error->getMessage(), '校验失败'), 'corrupt cloud object does not update database');
    }
    \Test\expect(!$bad->get('cloud_storage_object_key'), 'corrupt object leaves metadata unchanged');
    \Test\State::$objects['http://local.test/session/first'] = $content;

    $legacy = new \Modules\Main\Model\StorageModel(array_replace($values, [
        'id' => 4, 'hash' => md5('multipart ETag prefix'),
        'local_storage_object_key' => 'legacy',
    ]));
    \Test\State::$objects['http://local.test/session/legacy'] = $content;
    \Modules\Main\Service\StorageService::saveCloud($legacy);
    \Test\expect($legacy->get('hash') === md5('multipart ETag prefix'), 'legacy message reference remains unchanged');
    \Test\expect($legacy->get('cloud_storage_object_key') === $cloudKey, 'legacy row uses actual content MD5 key');
    $wrongKey = 'content/md5/' . substr($legacy->get('hash'), 0, 2) . '/' . $legacy->get('hash');
    \Test\expect(!isset(\Test\State::$objects['https://cloud.test/private/' . $wrongKey]), 'legacy hash creates no wrong-key object');

    $broken = new \Modules\Main\Model\StorageModel(array_replace($values, [
        'id' => 5, 'hash' => md5('broken saved hash'),
        'local_storage_object_key' => 'broken',
    ]));
    \Test\State::$objects['http://local.test/session/broken'] = 'new content';
    $broken->update(['file_size' => strlen('new content')]);
    \Test\State::$corruptNextCloudUpload = true;
    try {
        \Modules\Main\Service\StorageService::saveCloud($broken);
        throw new \RuntimeException('corrupt upload was accepted');
    } catch (\Exception $error) {
        \Test\expect(str_contains($error->getMessage(), '校验失败'), 'corrupt upload is rejected');
    }
    $brokenHash = md5('new content');
    $brokenKey = 'https://cloud.test/private/content/md5/' . substr($brokenHash, 0, 2) . '/' . $brokenHash;
    \Test\expect(in_array($brokenKey, \Test\State::$deleted, true) && !isset(\Test\State::$objects[$brokenKey]), 'failed new upload is removed');
    \Test\expect(!$broken->get('cloud_storage_object_key'), 'failed new upload does not update database');

    $first->update(['local_storage_expired_at' => '2020-01-01 00:00:00']);
    \Modules\Main\Service\StorageService::removeExpiredLocalFile($first);
    \Test\expect(isset(\Test\State::$objects['http://local.test/session/first']), 'local purge stays off by default');
}

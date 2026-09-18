<?php
// Opt-in: run only against two isolated MinIO services with synthetic objects.

namespace Drill {
    final class State
    {
        public static array $storages = [];
        public static ?\Modules\Main\Model\CloudStorageSettingModel $setting = null;
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
            $rows = $this->table === 'storage' ? State::$storages : [State::$setting];
            foreach ($rows as $row) {
                if ($row === null) continue;
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

namespace Common {
    final class Yii
    {
        public static function params(): array
        {
            return ['local-storage' => [
                'endpoint' => getenv('DRILL_LOCAL_ENDPOINT'), 'region' => 'us-east-1',
                'access_key' => 'drillaccess', 'secret_key' => 'drillsecret20260918',
            ]];
        }
    }
}

namespace Modules\Main\Model {
    final class CloudStorageSettingModel extends \Drill\Row
    {
        public static function query(): \Drill\Query { return new \Drill\Query('setting'); }
    }
    final class StorageModel extends \Drill\Row
    {
        public static function query(): \Drill\Query { return new \Drill\Query('storage'); }
    }
}

namespace {
    if (!getenv('DRILL_LOCAL_ENDPOINT') || !getenv('DRILL_CLOUD_ENDPOINT')) {
        throw new \RuntimeException('Two isolated MinIO endpoints are required');
    }
    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../modules/main/backend/Service/StorageService.php';
    require __DIR__ . '/../modules/main/backend/Consumer/UploadStorageToCloudConsumer.php';

    $setting = new \Modules\Main\Model\CloudStorageSettingModel([
        'id' => 7, 'provider' => getenv('DRILL_CLOUD_PROVIDER') ?: 'MinIO',
        'region' => getenv('DRILL_CLOUD_REGION') ?: 'us-east-1',
        'endpoint' => getenv('DRILL_CLOUD_ENDPOINT'),
        'bucket' => getenv('DRILL_CLOUD_BUCKET') ?: 'private',
        'access_key' => getenv('DRILL_CLOUD_ACCESS_KEY_ID') ?: 'drillaccess',
        'secret_key' => getenv('DRILL_CLOUD_SECRET_ACCESS_KEY') ?: 'drillsecret20260918',
    ]);
    \Drill\State::$setting = $setting;
    $local = \Modules\Main\Service\StorageService::getLocalS3Client();
    $cloud = \Modules\Main\Service\StorageService::getCloudS3Client($setting);
    $cloudBucket = $setting->get('bucket');
    if (!$local->doesBucketExist('session')) {
        $local->createBucket(['Bucket' => 'session']);
    }
    if (!$cloud->doesBucketExist($cloudBucket)) {
        $cloud->createBucket(['Bucket' => $cloudBucket]);
    }
    $makeRow = static function (int $id, string $body, string $localKey) use ($local): \Modules\Main\Model\StorageModel {
        $local->putObject(['Bucket' => 'session', 'Key' => $localKey, 'Body' => $body]);
        $row = new \Modules\Main\Model\StorageModel([
            'id' => $id, 'hash' => md5($body), 'file_size' => strlen($body),
            'mime_type' => 'application/octet-stream', 'is_deleted_local' => false,
            'local_storage_bucket' => 'session', 'local_storage_object_key' => $localKey,
            'cloud_storage_setting_id' => 0, 'cloud_storage_object_key' => '',
        ]);
        \Drill\State::$storages[] = $row;
        return $row;
    };

    $small = $makeRow(1, 'synthetic small payload', 'drill/small');
    \Modules\Main\Service\StorageService::saveCloud($small);
    $smallKey = $small->get('cloud_storage_object_key');
    \Drill\expect($smallKey === 'content/md5/' . substr($small->get('hash'), 0, 2) . '/' . $small->get('hash'), 'small object uses stable content key');
    \Drill\expect((string)$cloud->getObject(['Bucket' => $cloudBucket, 'Key' => $smallKey])['Body'] === 'synthetic small payload', 'small cloud bytes match');

    $duplicate = new \Modules\Main\Model\StorageModel(array_replace($small->values, [
        'id' => 2, 'local_storage_object_key' => 'drill/absent',
        'cloud_storage_setting_id' => 0, 'cloud_storage_object_key' => '',
    ]));
    \Drill\State::$storages[] = $duplicate;
    \Modules\Main\Service\StorageService::saveCloud($duplicate);
    \Drill\expect($duplicate->get('cloud_storage_object_key') === $smallKey, 'duplicate row reuses verified object without local source');

    $largeBody = str_repeat('large synthetic media!', 900000);
    $large = $makeRow(3, $largeBody, 'drill/large');
    \Modules\Main\Service\StorageService::saveCloud($large);
    $largeKey = $large->get('cloud_storage_object_key');
    \Drill\expect((int)$cloud->headObject(['Bucket' => $cloudBucket, 'Key' => $largeKey])['ContentLength'] === strlen($largeBody), 'multipart cloud size matches');
    \Drill\expect(md5((string)$cloud->getObject(['Bucket' => $cloudBucket, 'Key' => $largeKey])['Body']) === md5($largeBody), 'multipart cloud checksum matches');

    $legacy = $makeRow(5, 'legacy multipart hash fixture', 'drill/legacy');
    $legacyHash = md5('not the file contents');
    $legacy->update(['hash' => $legacyHash]);
    \Modules\Main\Service\StorageService::saveCloud($legacy);
    $legacyKey = $legacy->get('cloud_storage_object_key');
    \Drill\expect($legacy->get('hash') === $legacyHash, 'legacy message reference is preserved');
    \Drill\expect($legacyKey === 'content/md5/' . substr(md5('legacy multipart hash fixture'), 0, 2) . '/' . md5('legacy multipart hash fixture'), 'legacy file uses actual content key');
    \Drill\expect(!$cloud->doesObjectExistV2($cloudBucket, 'content/md5/' . substr($legacyHash, 0, 2) . '/' . $legacyHash), 'legacy hash creates no wrong-key cloud object');

    $bad = $makeRow(4, 'good', 'drill/bad-source');
    $badKey = 'content/md5/' . substr($bad->get('hash'), 0, 2) . '/' . $bad->get('hash');
    $cloud->putObject(['Bucket' => $cloudBucket, 'Key' => $badKey, 'Body' => 'bad!']);
    try {
        \Modules\Main\Service\StorageService::saveCloud($bad);
        throw new \RuntimeException('corrupt cloud object was accepted');
    } catch (\Exception $error) {
        \Drill\expect(str_contains($error->getMessage(), '校验失败'), 'corrupt cloud object is rejected');
    }
    \Drill\expect(!$bad->get('cloud_storage_object_key'), 'corrupt object leaves database metadata unchanged');
    (new \Modules\Main\Consumer\UploadStorageToCloudConsumer($bad))->handle();
    \Drill\expect(!$bad->get('cloud_storage_object_key'), 'cloud copy consumer remains off by default');
    $small->update(['local_storage_expired_at' => '2020-01-01 00:00:00']);
    \Modules\Main\Service\StorageService::removeExpiredLocalFile($small);
    \Drill\expect($local->doesObjectExistV2('session', 'drill/small'), 'local purge remains off by default');
    foreach ([$smallKey, $largeKey, $legacyKey, $badKey] as $key) {
        $cloud->deleteObject(['Bucket' => $cloudBucket, 'Key' => $key]);
    }
    \Drill\expect(true, 'synthetic cloud objects deleted');

    $realSmallFile = getenv('DRILL_REAL_SMALL_FILE');
    $realLargeFile = getenv('DRILL_REAL_LARGE_FILE');
    if ($realSmallFile || $realLargeFile) {
        if (!$realSmallFile || !$realLargeFile) {
            throw new \RuntimeException('Both real sample paths are required');
        }
        $realSmall = file_get_contents($realSmallFile);
        $realLarge = file_get_contents($realLargeFile);
        $savedLargeHash = strtolower((string)getenv('DRILL_REAL_LARGE_SAVED_MD5'));
        if ($realSmall === false || $realLarge === false || !preg_match('/^[a-f0-9]{32}$/', $savedLargeHash)) {
            throw new \RuntimeException('Real sample files or saved MD5 are missing');
        }

        $realSmallRow = $makeRow(10, $realSmall, 'drill/real-small');
        \Modules\Main\Service\StorageService::saveCloud($realSmallRow);
        $realSmallKey = $realSmallRow->get('cloud_storage_object_key');
        \Drill\expect(md5((string)$cloud->getObject(['Bucket' => $cloudBucket, 'Key' => $realSmallKey])['Body']) === md5($realSmall), 'real small source bytes survive cloud copy');

        $realLargeRow = $makeRow(11, $realLarge, 'drill/real-large');
        $realLargeRow->update(['hash' => $savedLargeHash]);
        $wrongKey = 'content/md5/' . substr($savedLargeHash, 0, 2) . '/' . $savedLargeHash;
        \Modules\Main\Service\StorageService::saveCloud($realLargeRow);
        $realLargeKey = $realLargeRow->get('cloud_storage_object_key');
        \Drill\expect($realLargeRow->get('hash') === $savedLargeHash, 'real legacy message reference remains unchanged');
        \Drill\expect($realLargeKey === 'content/md5/' . substr(md5($realLarge), 0, 2) . '/' . md5($realLarge), 'real multipart source uses actual content key');
        \Drill\expect(!$cloud->doesObjectExistV2($cloudBucket, $wrongKey), 'real legacy hash leaves no wrong-key orphan');
        \Drill\expect(md5((string)$cloud->getObject(['Bucket' => $cloudBucket, 'Key' => $realLargeKey])['Body']) === md5($realLarge), 'real multipart source copies without changing saved hash');
        foreach ([$realSmallKey, $wrongKey, $realLargeKey] as $key) {
            $cloud->deleteObject(['Bucket' => $cloudBucket, 'Key' => $key]);
        }
        \Drill\expect(true, 'real cloud test objects deleted');
    }
}

<?php
// Real qiweidoc models, Yii DB query, Redis mutex and S3 SDK against disposable local services.
// WeCom RPC and cloud-copy queue are replaced with deterministic, synthetic test doubles.

namespace Common {
    final class Yii
    {
        private static \Yiisoft\Db\Pgsql\Connection $connection;
        private static array $mutexes = [];

        public static function setConnection(\Yiisoft\Db\Pgsql\Connection $connection): void
        {
            self::$connection = $connection;
        }

        public static function db(): \Yiisoft\Db\Pgsql\Connection
        {
            return self::$connection;
        }

        public static function mutex(int $ttl = 30): \Yiisoft\Mutex\SimpleMutex
        {
            if (!isset(self::$mutexes[$ttl])) {
                $redis = new \Predis\Client('tcp://qiweidoc-int-redis:6379');
                self::$mutexes[$ttl] = new \Yiisoft\Mutex\SimpleMutex(new \Yiisoft\Mutex\Redis\RedisMutexFactory($redis, $ttl));
            }
            return self::$mutexes[$ttl];
        }

        public static function params(): array
        {
            return ['local-storage' => [
                'endpoint' => 'http://qiweidoc-int-minio:9000',
                'region' => 'us-east-1',
                'access_key' => 'qiweidoc_test',
                'secret_key' => 'qiweidoc_test_only_123',
            ]];
        }
    }

    final class Micro
    {
        public static int $calls = 0;

        public static function call(string $service, string $method, string $request, int $timeout): array
        {
            if ($service !== 'wxfinance' || $method !== 'FetchAndStreamMediaData') {
                throw new \RuntimeException('Unexpected RPC in integration test');
            }
            self::$calls++;
            $data = json_decode($request, true, flags: JSON_THROW_ON_ERROR);
            $isRace = str_starts_with($data['sdk_file_id'], 'race-');
            if ($isRace) usleep((int)getenv('QIWEIDOC_WORKER_DELAY_MS') * 1000);
            $body = $isRace ? str_repeat('race-content', 256) : str_repeat('synthetic-video-byte', 256);
            $result = \Modules\Main\Service\StorageService::getLocalS3Client()->putObject([
                'Bucket' => $data['storage_bucket_name'],
                'Key' => $data['storage_object_key'],
                'Body' => $body,
                'ContentType' => 'video/mp4',
            ]);
            return ['hash' => trim((string)$result['ETag'], '"'), 'size' => strlen($body), 'mime' => 'video/mp4'];
        }
    }
}

namespace Common\Job {
    final class Producer
    {
        public static int $calls = 0;
        public static function dispatch(string $consumer, array $data): void
        {
            self::$calls++;
        }
    }
}

namespace {
    use Common\Micro;
    use Common\Yii;
    use Modules\Main\Model\ChatMessageModel;
    use Modules\Main\Model\CorpModel;
    use Modules\Main\Model\StorageModel;
    use Modules\Main\Service\ChatSessionPullService;
    use Modules\Main\Service\StorageService;
    use Yiisoft\Cache\File\FileCache;
    use Yiisoft\Db\Cache\SchemaCache;
    use Yiisoft\Db\Pgsql\Connection;
    use Yiisoft\Db\Pgsql\Driver;

    if (getenv('QIWEIDOC_INTEGRATION_SANDBOX') !== '1') {
        throw new RuntimeException('Refusing to run outside the explicit isolated sandbox');
    }
    $loader = require dirname(__DIR__) . '/vendor/autoload.php';
    $loader->addPsr4('Modules\\Main\\', dirname(__DIR__) . '/modules/main/backend');

    $driver = new Driver(
        'pgsql:host=qiweidoc-int-pg;port=5432;dbname=qiweidoc_test',
        'qiweidoc_test',
        'qiweidoc_test_only',
        [PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $connection = new Connection($driver, new SchemaCache(new FileCache('/tmp/qiweidoc-integration-schema')));
    Yii::setConnection($connection);

    $corp = new CorpModel();
    $corp->setAttributes(['id' => 'corp-test', 'chat_secret' => 'synthetic-secret'], true);
    if (($argv[1] ?? '') === 'worker') {
        $workerMessage = ChatMessageModel::query()->where(['msg_id' => $argv[2] ?? ''])->getOne();
        if ($workerMessage === null) throw new RuntimeException('Concurrent worker message not found');
        ChatSessionPullService::handleMedia($corp, $workerMessage);
        echo "WORKER_DONE\n";
        exit;
    }

    foreach ([
        'CREATE SCHEMA main',
        'CREATE TABLE main.chat_messages (msg_id varchar(64) primary key, corp_id varchar(32) not null, msg_type varchar(32) not null, msg_time timestamp not null, raw_content jsonb not null, msg_content text not null default \'\', created_at timestamp not null, updated_at timestamp not null)',
        'CREATE TABLE main.storage (id serial primary key, hash char(32) not null, original_filename varchar(255) not null default \'\', file_extension varchar(16) not null default \'\', mime_type varchar(128) not null default \'\', file_size bigint not null default 0, is_deleted_local boolean default false, local_storage_bucket varchar(64) not null default \'\', local_storage_object_key varchar(1024) not null default \'\', local_storage_expired_at timestamp null, cloud_storage_setting_id bigint not null default 0, cloud_storage_object_key varchar(1024) not null default \'\', created_at timestamp not null, updated_at timestamp not null)',
        'CREATE TABLE main.settings (key varchar(128) primary key, value text not null, created_at timestamp not null default now(), updated_at timestamp not null default now())',
        "INSERT INTO main.settings (key, value) VALUES ('local_session_file_retention_days', '0')",
    ] as $sql) {
        $connection->createCommand($sql)->execute();
    }

    $s3 = StorageService::getLocalS3Client();
    $s3->createBucket(['Bucket' => StorageModel::SESSION_BUCKET]);

    function check(bool $condition, string $label): void
    {
        if (!$condition) throw new RuntimeException('FAILED: ' . $label);
        echo "PASS: $label\n";
    }

    function message(string $id, string $sdkId, string $sourceMd5): ChatMessageModel
    {
        return ChatMessageModel::create([
            'msg_id' => $id,
            'corp_id' => 'corp-test',
            'msg_type' => 'video',
            'msg_time' => '2026-09-18 11:00:00',
            'raw_content' => ['sdkfileid' => $sdkId, 'md5sum' => $sourceMd5],
            'msg_content' => '',
        ]);
    }

    function storageCount(Connection $db): int
    {
        return (int)$db->createCommand('SELECT count(*) FROM main.storage')->queryScalar();
    }

    $sourceMd5 = md5(str_repeat('synthetic-video-byte', 256));

    $first = message('m1', 'sdk-1', $sourceMd5);
    ChatSessionPullService::handleMedia($corp, $first);
    check(Micro::$calls === 1 && storageCount($connection) === 1, 'real DB, Redis and MinIO first upload');

    ChatSessionPullService::handleMedia($corp, $first);
    check(Micro::$calls === 1 && storageCount($connection) === 1, 'retry skips second upload');

    $second = message('m2', 'sdk-2', strtoupper($sourceMd5));
    ChatSessionPullService::handleMedia($corp, $second);
    $first = ChatMessageModel::query()->where(['msg_id' => 'm1'])->getOne();
    $second = ChatMessageModel::query()->where(['msg_id' => 'm2'])->getOne();
    check(Micro::$calls === 1 && storageCount($connection) === 1 && $second->get('msg_content') === $first->get('msg_content'), 'different SDK ID reuses existing object through real JSONB query');

    $firstStorage = StorageModel::query()->where(['hash' => $first->get('msg_content')])->getOne();
    $s3->deleteObject(['Bucket' => StorageModel::SESSION_BUCKET, 'Key' => $firstStorage->get('local_storage_object_key')]);
    $third = message('m3', 'sdk-3', $sourceMd5);
    ChatSessionPullService::handleMedia($corp, $third);
    check(Micro::$calls === 2 && storageCount($connection) === 2, 'missing physical object triggers one new upload');

    $orphanBody = 'another-content';
    $fourth = message('m4', 'sdk-4', md5($orphanBody));
    $orphanKey = sprintf('chat-media/%s/%s/video', hash('sha256', 'corp-test'), hash('sha256', 'm4'));
    $s3->putObject(['Bucket' => StorageModel::SESSION_BUCKET, 'Key' => $orphanKey, 'Body' => $orphanBody, 'ContentType' => 'video/mp4']);
    ChatSessionPullService::handleMedia($corp, $fourth);
    $fourth = ChatMessageModel::query()->where(['msg_id' => 'm4'])->getOne();
    check(Micro::$calls === 2 && storageCount($connection) === 3 && $fourth->get('msg_content') === md5($orphanBody), 'orphaned upload recovers metadata without downloading again');

    $raceMd5 = md5(str_repeat('race-content', 256));
    message('race-1', 'race-sdk-1', $raceMd5);
    message('race-2', 'race-sdk-2', $raceMd5);
    putenv('QIWEIDOC_WORKER_DELAY_MS=350');
    $workers = [];
    foreach (['race-1', 'race-2'] as $id) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $id], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start concurrent worker');
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || !str_contains($stdout, 'WORKER_DONE')) {
            throw new RuntimeException('Concurrent worker failed: ' . $stderr);
        }
    }
    $raceOne = ChatMessageModel::query()->where(['msg_id' => 'race-1'])->getOne();
    $raceTwo = ChatMessageModel::query()->where(['msg_id' => 'race-2'])->getOne();
    $corpPath = hash('sha256', 'corp-test');
    $raceOneKey = sprintf('chat-media/%s/%s/video', $corpPath, hash('sha256', 'race-1'));
    $raceTwoKey = sprintf('chat-media/%s/%s/video', $corpPath, hash('sha256', 'race-2'));
    $physicalCount = (int)$s3->doesObjectExistV2(StorageModel::SESSION_BUCKET, $raceOneKey)
        + (int)$s3->doesObjectExistV2(StorageModel::SESSION_BUCKET, $raceTwoKey);
    check(storageCount($connection) === 4 && $physicalCount === 1 && $raceOne->get('msg_content') === $raceTwo->get('msg_content'), 'two PHP workers create one storage row and one physical object');
}

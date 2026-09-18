<?php
// Standalone regression test: php php/tests/ChatSessionMediaReuseTest.php
// Uses in-memory doubles; it never connects to production services.

namespace Test {
    final class State
    {
        public static array $messages = [];
        public static array $storages = [];
        public static array $objects = [];
        public static int $downloads = 0;
        public static int $cloudJobs = 0;
        public static bool $lockAvailable = true;

        public static function reset(): void
        {
            self::$messages = [];
            self::$storages = [];
            self::$objects = [];
            self::$downloads = 0;
            self::$cloudJobs = 0;
            self::$lockAvailable = true;
        }
    }

    final class Query
    {
        private array $filters = [];

        public function __construct(private string $table)
        {
        }

        public function where(array|string $condition, array $params = []): self
        {
            return $this->andWhere($condition, $params);
        }

        public function andWhere(array|string $condition, array $params = []): self
        {
            $this->filters[] = [$condition, $params];
            return $this;
        }

        public function orderBy(array $order): self
        {
            return $this;
        }

        public function limit(int $limit): self
        {
            return $this;
        }

        public function getOne(): ?object
        {
            return $this->getAll()[0] ?? null;
        }

        public function getAll(): array
        {
            $rows = $this->table === 'messages' ? State::$messages : State::$storages;
            $rows = array_reverse($rows);
            return array_values(array_filter($rows, function ($row): bool {
                foreach ($this->filters as [$condition, $params]) {
                    if (is_string($condition)) {
                        $field = str_contains($condition, 'md5sum') ? 'md5sum' : 'sdkfileid';
                        $expected = reset($params);
                        $actual = $row->get('raw_content')[$field] ?? null;
                        if ($field === 'md5sum') {
                            $actual = strtolower($actual ?? '');
                        }
                        if ($actual !== $expected) {
                            return false;
                        }
                    } elseif (isset($condition[0]) && $condition[0] === '<>') {
                        if ($row->get($condition[1]) === $condition[2]) {
                            return false;
                        }
                    } else {
                        foreach ($condition as $field => $value) {
                            if ($row->get($field) !== $value) {
                                return false;
                            }
                        }
                    }
                }
                return true;
            }));
        }
    }

    final class Mutex
    {
        public function acquire(string $key, int $timeout): bool
        {
            return State::$lockAvailable;
        }

        public function release(string $key): void
        {
        }
    }

    final class S3
    {
        public function doesObjectExistV2(string $bucket, string $key): bool
        {
            return (bool)(State::$objects[$bucket . '/' . $key] ?? false);
        }

        public function headObject(array $request): array
        {
            return State::$objects[$request['Bucket'] . '/' . $request['Key']];
        }

        public function getObject(array $request): array
        {
            $object = State::$objects[$request['Bucket'] . '/' . $request['Key']];
            return ['Body' => new Body($object['Content'])];
        }
    }

    final class Body
    {
        private int $offset = 0;
        public function __construct(private string $content) {}
        public function eof(): bool { return $this->offset >= strlen($this->content); }
        public function read(int $length): string
        {
            $chunk = substr($this->content, $this->offset, $length);
            $this->offset += strlen($chunk);
            return $chunk;
        }
        public function close(): void {}
    }
}

namespace Common {
    final class Yii
    {
        public static function mutex(int $ttl): \Test\Mutex
        {
            return new \Test\Mutex();
        }

        public static function params(): array
        {
            return ['local-storage' => ['endpoint' => '', 'region' => '', 'access_key' => '', 'secret_key' => '']];
        }
    }

    final class Micro
    {
        public static function call(string $service, string $method, string $request, int $timeout): array
        {
            \Test\State::$downloads++;
            $data = json_decode($request, true);
            $hash = str_repeat('a', 32);
            \Test\State::$objects[$data['storage_bucket_name'] . '/' . $data['storage_object_key']] = [
                'ETag' => '"' . $hash . '-2"', 'ContentLength' => 123, 'ContentType' => 'video/mp4',
            ];
            return ['hash' => $hash, 'size' => 123, 'mime' => 'video/mp4'];
        }
    }
}

namespace Common\Exceptions {
    class RetryableJobException extends \Exception {}
}

namespace Common\Job {
    final class Producer
    {
        public static function dispatch(string $consumer, array $data): void
        {
            \Test\State::$cloudJobs++;
        }
    }
}

namespace Ramsey\Uuid {
    final class Uuid
    {
        private static int $next = 0;

        public static function uuid4(): string
        {
            return 'file-' . ++self::$next;
        }
    }
}

namespace Modules\Main\Model {
    class CorpModel
    {
        public function get(string $field): string
        {
            return $field === 'id' ? 'corp-1' : 'secret';
        }
    }

    class ChatMessageModel
    {
        public function __construct(private array $data) {}
        public function get(string $field): mixed { return $this->data[$field] ?? null; }
        public function update(array $data): void { $this->data = array_replace($this->data, $data); }
        public static function query(): \Test\Query { return new \Test\Query('messages'); }
    }

    class StorageModel
    {
        public const SESSION_BUCKET = 'session';
        public function __construct(private array $data) {}
        public function get(string $field): mixed { return $this->data[$field] ?? null; }
        public function update(array $data): void { $this->data = array_replace($this->data, $data); }
        public static function query(): \Test\Query { return new \Test\Query('storages'); }
        public static function create(array $data): self
        {
            $storage = new self($data + ['id' => count(\Test\State::$storages) + 1]);
            \Test\State::$storages[] = $storage;
            return $storage;
        }
    }

    class SettingModel
    {
        public static function getValue(string $key): int { return 0; }
    }

    class CloudStorageSettingModel
    {
        public static function query(): \Test\Query { return new \Test\Query('cloud'); }
    }
}

namespace Modules\Main\Service {
    class ChatSessionService
    {
        public const ValidMediaType = ['video'];
    }

    class StorageService
    {
        public static function generateObjectKey(string $name, string $md5): string
        {
            return $md5 . '/' . $name;
        }

        public static function getLocalS3Client(): \Test\S3 { return new \Test\S3(); }
        public static function getCloudS3Client(object $setting): \Test\S3 { return new \Test\S3(); }
    }
}

namespace {
    require dirname(__DIR__) . '/modules/main/backend/Service/ChatSessionPullService.php';

    use Common\Exceptions\RetryableJobException;
    use Modules\Main\Model\ChatMessageModel;
    use Modules\Main\Model\CorpModel;
    use Modules\Main\Service\ChatSessionPullService;
    use Test\State;

    function check(bool $condition, string $name): void
    {
        if (!$condition) {
            throw new \RuntimeException('FAILED: ' . $name);
        }
        echo "PASS: $name\n";
    }

    State::reset();
    $corp = new CorpModel();
    $md5 = '91f46ac151a6139252b67b3460268bd9';
    $first = new ChatMessageModel(['msg_id' => 'm1', 'corp_id' => 'corp-1', 'msg_type' => 'video', 'msg_time' => '2026-09-18', 'msg_content' => '', 'raw_content' => ['sdkfileid' => 'sdk-1', 'md5sum' => $md5, 'filesize' => 123]]);
    State::$messages[] = $first;
    ChatSessionPullService::handleMedia($corp, $first);
    check(State::$downloads === 1 && count(State::$storages) === 1, 'first message downloads once');

    $staleSnapshot = new ChatMessageModel(['msg_id' => 'm1', 'msg_type' => 'video', 'raw_content' => $first->get('raw_content')]);
    ChatSessionPullService::handleMedia($corp, $staleSnapshot);
    check(State::$downloads === 1 && count(State::$storages) === 1, 'retry with stale message does not download');

    $second = new ChatMessageModel(['msg_id' => 'm2', 'corp_id' => 'corp-1', 'msg_type' => 'video', 'msg_time' => '2026-09-18', 'msg_content' => '', 'raw_content' => ['sdkfileid' => 'sdk-2', 'md5sum' => strtoupper($md5), 'filesize' => 123]]);
    State::$messages[] = $second;
    ChatSessionPullService::handleMedia($corp, $second);
    check(State::$downloads === 1 && $second->get('msg_content') === $first->get('msg_content'), 'same content in another message reuses object');

    $storage = State::$storages[0];
    State::$objects[$storage->get('local_storage_bucket') . '/' . $storage->get('local_storage_object_key')] = false;
    $third = new ChatMessageModel(['msg_id' => 'm3', 'corp_id' => 'corp-1', 'msg_type' => 'video', 'msg_time' => '2026-09-18', 'msg_content' => '', 'raw_content' => ['sdkfileid' => 'sdk-3', 'md5sum' => $md5, 'filesize' => 123]]);
    State::$messages[] = $third;
    ChatSessionPullService::handleMedia($corp, $third);
    check(State::$downloads === 2 && count(State::$storages) === 2, 'missing object is downloaded again');

    State::$lockAvailable = false;
    try {
        ChatSessionPullService::handleMedia($corp, $third);
        throw new \RuntimeException('Expected lock timeout');
    } catch (RetryableJobException) {
        check(State::$downloads === 2, 'lock contention does not create duplicate object');
    }

    State::$lockAvailable = true;
    State::$objects[$storage->get('local_storage_bucket') . '/' . $storage->get('local_storage_object_key')] = true;
    $latestStorage = State::$storages[1];
    State::$objects[$latestStorage->get('local_storage_bucket') . '/' . $latestStorage->get('local_storage_object_key')] = false;
    $fourth = new ChatMessageModel(['msg_id' => 'm4', 'corp_id' => 'corp-1', 'msg_type' => 'video', 'msg_time' => '2026-09-18', 'msg_content' => '', 'raw_content' => ['sdkfileid' => 'sdk-4', 'md5sum' => $md5, 'filesize' => 123]]);
    State::$messages[] = $fourth;
    ChatSessionPullService::handleMedia($corp, $fourth);
    check(State::$downloads === 2 && $fourth->get('msg_content') === $first->get('msg_content'), 'older available storage is reused when newest copy is missing');

    State::reset();
    $withoutMd5 = new ChatMessageModel(['msg_id' => 'no-md5-1', 'corp_id' => 'corp-1', 'msg_type' => 'video', 'msg_time' => '2026-09-18', 'msg_content' => '', 'raw_content' => ['sdkfileid' => 'same-sdk', 'filesize' => 123]]);
    $sameSdk = new ChatMessageModel(['msg_id' => 'no-md5-2', 'corp_id' => 'corp-1', 'msg_type' => 'video', 'msg_time' => '2026-09-18', 'msg_content' => '', 'raw_content' => ['sdkfileid' => 'same-sdk', 'filesize' => 123]]);
    State::$messages = [$withoutMd5, $sameSdk];
    ChatSessionPullService::handleMedia($corp, $withoutMd5);
    ChatSessionPullService::handleMedia($corp, $sameSdk);
    check(State::$downloads === 1, 'missing MD5 only reuses identical sdkfileid');

    $differentSize = new ChatMessageModel(['msg_id' => 'different-size', 'corp_id' => 'corp-1', 'msg_type' => 'video', 'msg_time' => '2026-09-18', 'msg_content' => '', 'raw_content' => ['sdkfileid' => 'same-sdk', 'filesize' => 456]]);
    State::$messages[] = $differentSize;
    ChatSessionPullService::handleMedia($corp, $differentSize);
    check(State::$downloads === 2, 'declared size mismatch does not reuse object');

    State::reset();
    $recoveredContent = str_repeat('x', 123);
    $recoveredHash = md5($recoveredContent);
    $interrupted = new ChatMessageModel(['msg_id' => 'interrupted', 'corp_id' => 'corp-1', 'msg_type' => 'video', 'msg_time' => '2026-09-18', 'msg_content' => '', 'raw_content' => ['sdkfileid' => 'sdk-interrupted', 'md5sum' => $recoveredHash, 'filesize' => 123]]);
    State::$messages[] = $interrupted;
    $objectKey = sprintf('chat-media/%s/%s/video', hash('sha256', 'corp-1'), hash('sha256', 'interrupted'));
    State::$objects['session/' . $objectKey] = ['ETag' => '"' . str_repeat('b', 32) . '-2"', 'ContentLength' => 123, 'ContentType' => 'video/mp4', 'Content' => $recoveredContent];
    ChatSessionPullService::handleMedia($corp, $interrupted);
    check(State::$downloads === 0 && count(State::$storages) === 1 && $interrupted->get('msg_content') === $recoveredHash, 'uploaded object without database row is recovered using content MD5, not multipart ETag');

    $interrupted->update(['msg_content' => '']);
    ChatSessionPullService::handleMedia($corp, $interrupted);
    check(State::$downloads === 0 && count(State::$storages) === 1, 'existing object and metadata recover message without duplicate row');

    State::$objects['session/' . $objectKey] = false;
    ChatSessionPullService::handleMedia($corp, $interrupted);
    check(State::$downloads === 1 && count(State::$storages) === 1, 'missing object for same message reuses fixed key and storage row');

    State::reset();
    $corrupt = new ChatMessageModel(['msg_id' => 'corrupt', 'corp_id' => 'corp-1', 'msg_type' => 'video', 'msg_time' => '2026-09-18', 'msg_content' => '', 'raw_content' => ['sdkfileid' => 'sdk-corrupt', 'md5sum' => md5('good'), 'filesize' => 4]]);
    State::$messages[] = $corrupt;
    $corruptKey = sprintf('chat-media/%s/%s/video', hash('sha256', 'corp-1'), hash('sha256', 'corrupt'));
    State::$objects['session/' . $corruptKey] = ['ETag' => '"' . md5('good') . '-2"', 'ContentLength' => 4, 'ContentType' => 'video/mp4', 'Content' => 'bad!'];
    ChatSessionPullService::handleMedia($corp, $corrupt);
    check(State::$downloads === 1, 'existing object with wrong content MD5 is downloaded again');
}

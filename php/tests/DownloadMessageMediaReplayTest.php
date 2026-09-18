<?php
// Standalone in-memory command test: no database, queue, WeCom, or local service.

namespace Drill {
    final class State
    {
        public static array $messages = [];
        public static array $dispatched = [];
    }

    class Row
    {
        public function __construct(public array $values) {}
        public function get(string $key): mixed { return $this->values[$key] ?? null; }
    }

    final class Query
    {
        private array $filters = [];
        private bool $mediaOnly = false;
        public function __construct(private string $table) {}
        public function where(array $filters): self { $this->filters = $filters; return $this; }
        public function andWhere(array $filter): self
        {
            if (($filter[0] ?? null) === 'in' && ($filter[1] ?? null) === 'msg_type') $this->mediaOnly = true;
            return $this;
        }
        public function getOne(): ?Row
        {
            if ($this->table === 'corp') return new \Modules\Main\Model\CorpModel(['id' => 'synthetic-corp']);
            foreach (State::$messages as $row) {
                foreach ($this->filters as $key => $value) {
                    if ($row->get($key) !== $value) continue 2;
                }
                if ($this->mediaOnly && !in_array($row->get('msg_type'), \Modules\Main\Service\ChatSessionService::ValidMediaType, true)) continue;
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

namespace Common\Job {
    final class Producer
    {
        public static function dispatch(string $class, array $args): void
        {
            \Drill\State::$dispatched[] = [$class, $args['message']->get('seq')];
        }
    }
}

namespace Modules\Main\Model {
    final class CorpModel extends \Drill\Row
    {
        public static function query(): \Drill\Query { return new \Drill\Query('corp'); }
    }
    final class ChatMessageModel extends \Drill\Row
    {
        public static function query(): \Drill\Query { return new \Drill\Query('message'); }
    }
}

namespace Modules\Main\Service {
    final class ChatSessionService
    {
        public const ValidMediaType = ['image', 'voice', 'video', 'emotion', 'file', 'meeting_voice_call'];
    }
    final class ChatSessionPullService
    {
        public static function isLargeFile(\Modules\Main\Model\ChatMessageModel $message): bool
        {
            return $message->get('msg_type') === 'file' && (int)($message->get('raw_content')['filesize'] ?? 0) > 20 * 1024 * 1024;
        }
    }
}

namespace {
    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../common/Command/DownloadMessageMediasCommand.php';

    \Drill\State::$messages[] = new \Modules\Main\Model\ChatMessageModel([
        'corp_id' => 'synthetic-corp', 'seq' => 659, 'msg_type' => 'file',
        'msg_time' => '2026-07-22 10:38:07.902', 'msg_content' => '',
        'raw_content' => ['sdkfileid' => 'synthetic-id', 'filesize' => 614788943],
    ]);
    $command = new \Common\Command\DownloadMessageMediasCommand();
    $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
    putenv('QIWEIDOC_MEDIA_REPLAY_ENABLED');

    \Drill\check($tester->execute(['--seq' => '659']) === 0, 'single old media can be previewed');
    \Drill\check(str_contains($tester->getDisplay(), '仅预览'), 'preview clearly says no task was sent');
    \Drill\check(\Drill\State::$dispatched === [], 'preview does not dispatch');

    \Drill\check($tester->execute(['--seq' => '659', '--dispatch' => true]) !== 0, 'dispatch is blocked without explicit replay switch');
    \Drill\check(\Drill\State::$dispatched === [], 'disabled replay leaves queue untouched');

    putenv('QIWEIDOC_MEDIA_REPLAY_ENABLED=1');
    \Drill\check($tester->execute(['--seq' => '659', '--dispatch' => true]) === 0, 'enabled replay accepts one exact seq');
    \Drill\check(count(\Drill\State::$dispatched) === 1
        && \Drill\State::$dispatched[0][0] === \Modules\Main\Consumer\DownloadChatSessionBitMediasConsumer::class
        && \Drill\State::$dispatched[0][1] === 659, 'large old file enters the large-media queue only once');

    \Drill\check($tester->execute(['--seq' => '660', '--dispatch' => true]) !== 0, 'nonexistent seq cannot be dispatched');
    \Drill\check(count(\Drill\State::$dispatched) === 1, 'nonexistent seq adds no queue task');
    \Drill\check($tester->execute(['--seq' => 'bad', '--dispatch' => true]) !== 0, 'invalid seq is rejected');
    \Drill\check($tester->execute(['--dispatch' => true]) !== 0, 'dispatch requires an exact seq');
    putenv('QIWEIDOC_MEDIA_REPLAY_ENABLED');
}

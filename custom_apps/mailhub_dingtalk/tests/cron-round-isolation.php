<?php
declare(strict_types=1);
/**
 * Runs the installed NC 34.0.5 CronService CLI loop and native TimedJob in isolation.
 * No lib/base.php, database, actual config, mail accounts, cron rows or network.
 * Run as a standalone PHP process; dependencies below are deliberately in-memory.
 */
namespace Psr\Log { interface LoggerInterface {} }
namespace OCP {
    interface IConfig {}
    interface ITempManager {}
    class ILogger { public const FATAL = 4; public const ERROR = 3; public const WARN = 2; public const INFO = 1; public const DEBUG = 0; }
    final class Server {
        public static \Psr\Log\LoggerInterface $logger;
        public static function get(string $class): mixed {
            if ($class !== \Psr\Log\LoggerInterface::class) { throw new \LogicException('Unexpected external service lookup'); }
            return self::$logger;
        }
    }
}
namespace OCP\Files { interface ISetupManager {} }
namespace OCP\AppFramework\Utility { interface ITimeFactory { public function getTime(): int; } }
namespace OC\DB {
    final class Connection {
        public function inTransaction(): bool { return false; }
        public function rollBack(): void { throw new \LogicException('No database transaction is permitted'); }
    }
}
namespace OC\BackgroundJob {
    final class JobClassesRegistry { public function getId(string $class): int { return 1; } }
    final class JobRuns {
        public int $starts = 0;
        public int $finishes = 0;
        public function started(int $class): int { return ++$this->starts; }
        public function finished(int $id, int $duration, int $memory): void { ++$this->finishes; }
    }
}
namespace OC\Core\Service {
    // The actual CronService calls unqualified time(); make its clock deterministic.
    function time(): int { return \MailhubCronProbe\Clock::$now; }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    if (!function_exists('posix_getuid')) { throw new RuntimeException('Run this isolated check in the target Linux PHP container'); }
    class OC { public static string $configDir; }
    $root = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
    $version = (static function (string $file): array { require $file; return $OC_Version ?? []; })($root . '/version.php');
    if (array_slice($version, 0, 3) !== [34, 0, 5]) { throw new RuntimeException('This core scheduling probe is reviewed for Nextcloud 34.0.5 only'); }
    foreach (['IJob', 'IParallelAwareJob', 'IJobList', 'Job', 'TimedJob'] as $class) {
        require $root . '/lib/public/BackgroundJob/' . $class . '.php';
    }
    require $root . '/core/Service/CronService.php';
}
namespace MailhubCronProbe {
    use OCP\BackgroundJob\IJob;
    use OCP\BackgroundJob\IJobList;
    use OCP\BackgroundJob\TimedJob;

    final class Clock implements \OCP\AppFramework\Utility\ITimeFactory {
        public static int $now = 1000;
        public function getTime(): int { return self::$now; }
    }
    final class Logger implements \Psr\Log\LoggerInterface {
        public array $errors = [];
        public function debug(...$arguments): void {}
        public function info(...$arguments): void {}
        public function warning(...$arguments): void { $this->errors[] = $arguments; }
        public function error(...$arguments): void { $this->errors[] = $arguments; }
        public function log(...$arguments): void { $this->errors[] = $arguments; }
    }
    final class Config implements \OCP\IConfig {
        public function getSystemValueInt(string $key, int $default): int { return $default; }
    }
    final class TempManager implements \OCP\ITempManager { public function clean(): void {} }
    final class SetupManager implements \OCP\Files\ISetupManager { public function tearDown(): void {} }
    final class ProbeJob extends TimedJob {
        public array $runs = [];
        protected function run($argument): void {
            $key = $argument['key'];
            $this->runs[$key] = ($this->runs[$key] ?? 0) + 1;
            Clock::$now += 3; // Make the same ID eligible again before this round ends.
        }
    }
    /** A cached job instance plus durable-looking in-memory rows, with no real JobList/DB. */
    final class JobList implements IJobList {
        public array $rows = [];
        public array $order = [];
        public array $lastRunWrites = [];
        public array $unlocks = [];
        public int $selections = 0;
        public function __construct(private ProbeJob $job) {}
        public function getNext(bool $onlyTimeSensitive = false, ?array $jobClasses = null): ?IJob {
            ++$this->selections;
            $id = array_shift($this->order);
            if ($id === null) { return null; }
            $this->job->setId($id);
            $this->job->setLastRun($this->rows[$id] ?? 0);
            $this->job->setArgument(['key' => $id]);
            return $this->job;
        }
        public function setLastRun(IJob $job): void {
            $this->rows[$job->getId()] = Clock::$now;
            $this->lastRunWrites[] = [$job->getId(), Clock::$now];
        }
        public function setExecutionTime(IJob $job, int $timeTaken): void {}
        public function setLastJob(IJob $job): void { $this->unlockJob($job); }
        public function unlockJob(IJob $job): void { $this->unlocks[] = $job->getId(); }
        public function add(IJob|string $job, mixed $argument = null): void { throw new \LogicException('No real job registration'); }
        public function scheduleAfter(string $job, int $runAfter, mixed $argument = null): void { throw new \LogicException('No real job registration'); }
        public function remove(IJob|string $job, mixed $argument = null): void { throw new \LogicException('No real job removal'); }
        public function removeById(string $id): void { throw new \LogicException('No real job removal'); }
        public function has(IJob|string $job, mixed $argument): bool { return false; }
        public function getJobs(IJob|string|null $job, ?int $limit, int $offset): array { return []; }
        public function getJobsIterator(IJob|string|null $job, ?int $limit, int $offset): iterable { return []; }
        public function getById(string $id): ?IJob { return null; }
        public function getDetailsById(string $id): ?array { return null; }
        public function resetBackgroundJob(IJob $job): void { throw new \LogicException('No real job reset'); }
        public function hasReservedJob(?string $className): bool { return false; }
        public function countByClass(): array { return []; }
    }
    function cron(JobList $jobs, Logger $logger, \OC\BackgroundJob\JobRuns $runs): \OC\Core\Service\CronService {
        // Invoke the real private CLI loop, bypassing run() and its app/session bootstrap.
        $class = new \ReflectionClass(\OC\Core\Service\CronService::class);
        $service = $class->newInstanceWithoutConstructor();
        foreach ([
            'logger' => $logger, 'config' => new Config(), 'jobList' => $jobs,
            'jobRuns' => $runs, 'jobClassesRegistry' => new \OC\BackgroundJob\JobClassesRegistry(),
            'setupManager' => new SetupManager(), 'tempManager' => new TempManager(), 'connection' => new \OC\DB\Connection(),
        ] as $name => $value) {
            $class->getProperty($name)->setValue($service, $value);
        }
        return $service;
    }
}
namespace {
    use MailhubCronProbe\Clock;
    use MailhubCronProbe\JobList;
    use MailhubCronProbe\Logger;
    use MailhubCronProbe\ProbeJob;
    use OCA\MailhubDingtalk\Service\FastSyncConfigurator;

    require __DIR__ . '/../lib/Service/FastSyncConfigurator.php';
    $checks = 0;
    function check(bool $condition, string $label): void {
        global $checks; ++$checks;
        if (!$condition) { throw new RuntimeException('FAILED: ' . $label); }
    }
    $logger = new Logger(); \OCP\Server::$logger = $logger;
    check(FastSyncConfigurator::EFFECTIVE_INTERVAL === 1, 'Minute rounds use the reviewed one-second eligibility threshold');
    $runCli = new ReflectionMethod(\OC\Core\Service\CronService::class, 'runCli');
    check(realpath($runCli->getFileName()) === realpath($root . '/core/Service/CronService.php'), 'The installed native CronService loop is executed');
    check(realpath((new ReflectionMethod(\OCP\BackgroundJob\TimedJob::class, 'start'))->getFileName())
        === realpath($root . '/lib/public/BackgroundJob/TimedJob.php'), 'The installed native TimedJob threshold is executed');

    $job = new ProbeJob(new Clock()); $jobs = new JobList($job);
    $job->setId('timing'); $job->setArgument(['key' => 'timing']); $job->setLastRun(100);
    Clock::$now = 144; $job->setInterval(55); $job->start($jobs);
    check($job->runs === [], 'Old 55-second threshold skips a next-round job moved 16 seconds earlier');
    check($jobs->lastRunWrites === [], 'Skipped native TimedJob does not update its last-run timestamp');
    $job->setInterval(FastSyncConfigurator::EFFECTIVE_INTERVAL); $job->start($jobs);
    check(($job->runs['timing'] ?? 0) === 1, 'One-second eligibility lets that 44-second-old job run this round');
    check($jobs->lastRunWrites === [['timing', 144]], 'Eligible native TimedJob records exactly one run');
    foreach ([0 => 0, 1 => 0, 2 => 1] as $age => $expected) {
        $job = new ProbeJob(new Clock()); $jobs = new JobList($job);
        $job->setId('boundary'); $job->setArgument(['key' => 'boundary']); $job->setLastRun(200);
        $job->setInterval(FastSyncConfigurator::EFFECTIVE_INTERVAL); Clock::$now = 200 + $age;
        $job->start($jobs);
        check(($job->runs['boundary'] ?? 0) === $expected, 'Native strict interval boundary at age ' . $age);
    }

    // CronService checks config ownership. Use an empty disposable file, never the real config.
    $fixtureDir = sys_get_temp_dir() . '/mailhub-cron-probe-' . bin2hex(random_bytes(12));
    if (!mkdir($fixtureDir, 0700) || file_put_contents($fixtureDir . '/config.php', "<?php\n") === false) {
        throw new RuntimeException('Unable to create isolated ownership fixture');
    }
    OC::$configDir = $fixtureDir . '/';
    try {
        $job = new ProbeJob(new Clock()); $job->setInterval(FastSyncConfigurator::EFFECTIVE_INTERVAL);
        $jobs = new JobList($job); $jobs->rows = ['first' => 900, 'second' => 900];
        $jobs->order = ['first', 'second', 'first', 'second']; Clock::$now = 1000;
        $runs = new \OC\BackgroundJob\JobRuns(); $service = \MailhubCronProbe\cron($jobs, $logger, $runs);
        $runCli->invoke($service, 'cron', [ProbeJob::class]);
        check($job->runs === ['first' => 1, 'second' => 1], 'Each distinct ID executes once even when the native service object is reused');
        check($jobs->selections === 3, 'Native CronService stops when the first already-executed ID is selected again');
        check($jobs->unlocks === ['first', 'second', 'first'], 'Repeated selection is unlocked without another execution');
        check(count($jobs->lastRunWrites) === 2 && $runs->starts === 2 && $runs->finishes === 2, 'Repeated selection creates no extra run or metrics entry');
        check(Clock::$now - $jobs->rows['first'] > $job->getInterval(), 'Repeated ID was eligible again, so the per-round guard prevented the duplicate');

        $jobs->order = ['second', 'first', 'second']; Clock::$now = 1060;
        $runCli->invoke($service, 'cron', [ProbeJob::class]);
        check($job->runs === ['first' => 2, 'second' => 2], 'The next cron round can execute both IDs again in a different order');
        check(count($jobs->lastRunWrites) === 4 && $runs->starts === 4, 'The executed-ID set resets between rounds');
        check($logger->errors === [], 'Native scheduling scenarios finish without suppressed errors');
        check(!in_array(realpath($root . '/lib/base.php'), array_map('realpath', get_included_files()), true), 'Nextcloud bootstrap and real service registry were never loaded');
    } finally {
        unlink($fixtureDir . '/config.php');
        rmdir($fixtureDir);
    }
    echo "PASS $checks native cron-round isolation checks; no database, mailbox, message or real configuration access.\n";
}

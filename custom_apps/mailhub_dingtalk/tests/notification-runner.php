<?php
declare(strict_types=1);
/** Real local flock/fork checks in an isolated temporary directory; no Nextcloud or network. */
namespace OCP { interface IConfig {} }
namespace Psr\Log { interface LoggerInterface {} }
namespace OCP\AppFramework\Utility { interface ITimeFactory {} }
namespace OCP\BackgroundJob {
    abstract class TimedJob {
        public const TIME_SENSITIVE = 1;
        public function __construct($time) {}
        public function setInterval($interval): void {}
        public function setTimeSensitivity($sensitivity): void {}
        public function setAllowParallelRuns($allow): void {}
    }
}
namespace OCA\MailhubDingtalk\Service {
    final class NotificationService {
        public int $ticks = 0;
        public ?\Closure $callback = null;
        public function tick(): void { ++$this->ticks; if ($this->callback) { ($this->callback)(); } }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    if (PHP_OS_FAMILY !== 'Linux' || !function_exists('pcntl_fork') || !function_exists('posix_geteuid')) {
        fwrite(STDERR, "Run this test in the Linux Nextcloud PHP container with pcntl/posix.\n"); exit(2);
    }
    require __DIR__ . '/../lib/Service/NotificationRunner.php';
    require __DIR__ . '/../lib/BackgroundJob/NotificationJob.php';
    use OCA\MailhubDingtalk\Service\NotificationRunner;
    use OCA\MailhubDingtalk\Service\NotificationService;
    final class TestConfig implements \OCP\IConfig {
        public array $status = [];
        public function __construct(public string $directory) {}
        public function getSystemValueString($key, $default): string { return $this->directory; }
        public function setAppValue($app, $key, $value): void { $this->status[$key] = $value; }
    }
    final class TestLogger implements \Psr\Log\LoggerInterface {
        public array $entries = [];
        public function error($message, $context): void { $this->entries[] = [$message, $context]; }
    }
    $checks = 0;
    function check(bool $condition, string $label): void {
        global $checks; ++$checks;
        if (!$condition) { throw new \RuntimeException('FAILED: ' . $label); }
    }
    $directory = sys_get_temp_dir() . '/mailhub-runner-test-' . bin2hex(random_bytes(10));
    if (!mkdir($directory, 0700)) { throw new \RuntimeException('Cannot create test directory'); }
    $path = $directory . '/' . NotificationRunner::LOCK_FILE;
    $service = new NotificationService(); $config = new TestConfig($directory); $logger = new TestLogger();
    $runner = new NotificationRunner($service, $config, $logger);
    $child = null; $channel = null;
    try {
        check($runner->run() && $service->ticks === 1, 'Uncontended runner executes once');
        clearstatcache(true, $path);
        check((fileperms($path) & 0777) === 0600 && fileowner($path) === posix_geteuid(), 'Lock is created 0600 for the running user');
        check(filesize($path) === 0, 'Lock file contains no user data');
        $inode = fileinode($path);
        check($runner->run() && fileinode($path) === $inode, 'Repeated passes retain the same lock inode');
        $service->callback = static function (): void { throw new \RuntimeException('private-recipient-secret'); };
        try { $runner->run(); throw new \LogicException('Expected sanitized exception'); }
        catch (\RuntimeException $e) {
            check($e->getMessage() === 'Mailhub notification runner failed' && $e->getPrevious() === null, 'Tick error exposes no original exception chain');
        }
        check(($config->status['notifications_last_error'] ?? '') === 'notification_runner_failed'
            && count($logger->entries) === 1 && !str_contains(json_encode($logger->entries), 'private-recipient-secret'), 'Failure records fixed status and sanitized log');
        $service->callback = null;
        check($runner->run(), 'Finally releases the lock after tick throws');

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) { throw new \RuntimeException('Cannot create test synchronization channel'); }
        $child = pcntl_fork();
        if ($child === -1) { throw new \RuntimeException('Cannot fork test worker'); }
        if ($child === 0) {
            fclose($pair[0]); stream_set_timeout($pair[1], 10);
            $workerService = new NotificationService();
            $workerService->callback = static function () use ($pair): void {
                fwrite($pair[1], 'L'); fflush($pair[1]);
                if (fread($pair[1], 1) !== 'R') { throw new \RuntimeException('Test worker release missing'); }
            };
            try {
                $worker = new NotificationRunner($workerService, new TestConfig($directory), new TestLogger());
                $ok = $worker->run(); fclose($pair[1]); exit($ok && $workerService->ticks === 1 ? 0 : 3);
            } catch (\Throwable) { fclose($pair[1]); exit(4); }
        }
        fclose($pair[1]); $channel = $pair[0]; stream_set_timeout($channel, 10);
        check(fread($channel, 1) === 'L', 'Separate process holds the actual filesystem lock');
        $beforeTicks = $service->ticks; $beforeErrors = count($logger->entries); $beforeTime = microtime(true);
        check($runner->run() === false && microtime(true) - $beforeTime < 1.0, 'Contending runner skips without blocking');
        check($service->ticks === $beforeTicks && count($logger->entries) === $beforeErrors, 'Busy skip neither executes tick nor logs a false failure');
        $otherService = new NotificationService(); $otherRunner = new NotificationRunner($otherService, $config, $logger);
        check($otherRunner->run() === false && $otherService->ticks === 0, 'A second independent runner instance shares the same lock');
        $time = new class implements \OCP\AppFramework\Utility\ITimeFactory {};
        $job = new \OCA\MailhubDingtalk\BackgroundJob\NotificationJob($time, $otherRunner);
        $invokeJob = new \ReflectionMethod($job, 'run'); $invokeJob->invoke($job, null);
        check($otherService->ticks === 0, 'Native background-job entry point also honors the same busy lock');
        fwrite($channel, 'R'); fflush($channel); fclose($channel); $channel = null;
        pcntl_waitpid($child, $status); $child = null;
        check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Holding worker completes normally after release');
        $invokeJob->invoke($job, null);
        check($otherService->ticks === 1 && $runner->run(), 'Both entry points can execute after the worker releases the lock');

        chmod($path, 0644); $beforeTicks = $service->ticks;
        try { $runner->run(); throw new \LogicException('Expected mode rejection'); }
        catch (\RuntimeException) { check($service->ticks === $beforeTicks, 'An unsafe existing file mode is rejected without running tick'); }
        chmod($path, 0600);
        check(link($path, $directory . '/hardlink'), 'Test can create a second link to the lock inode');
        try { $runner->run(); throw new \LogicException('Expected hardlink rejection'); }
        catch (\RuntimeException) { check($service->ticks === $beforeTicks, 'Hard-linked lock file is rejected'); }
        unlink($directory . '/hardlink');
        rename($path, $directory . '/saved-lock'); symlink($directory . '/saved-lock', $path);
        try { $runner->run(); throw new \LogicException('Expected symlink rejection'); }
        catch (\RuntimeException) { check($service->ticks === $beforeTicks, 'Symlink lock path is rejected'); }
        unlink($path); rename($directory . '/saved-lock', $path);
        check($runner->run(), 'Safe original file works after rejected-path tests');
        foreach (['', 'relative-path', $directory . '/missing'] as $badDirectory) {
            $bad = new NotificationRunner($service, new TestConfig($badDirectory), $logger); $beforeTicks = $service->ticks;
            try { $bad->run(); throw new \LogicException('Expected directory rejection'); }
            catch (\RuntimeException) { check($service->ticks === $beforeTicks, 'Missing or relative data directory fails closed'); }
        }
        check(fileinode($path) === $inode && filesize($path) === 0, 'Lock retains its original empty inode for future processes');
        echo "PASS $checks notification runner checks; real cross-process flock, exception release and background entry tested; no mailbox or network access.\n";
    } finally {
        if (is_resource($channel)) { @fwrite($channel, 'R'); @fclose($channel); }
        if ($child !== null && $child > 0) { pcntl_waitpid($child, $unusedStatus); }
        foreach ([$path, $directory . '/hardlink', $directory . '/saved-lock'] as $file) {
            if (is_link($file) || is_file($file)) { unlink($file); }
        }
        rmdir($directory);
    }
}

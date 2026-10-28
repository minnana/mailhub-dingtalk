<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Serializes notification entry points on one Linux host with a shared local data bind.
 * This is not a distributed/HA lock. Never unlink the lock file after use: replacing
 * its inode would allow a second process to acquire a different lock concurrently.
 */
final class NotificationRunner {
    public const LOCK_FILE = '.mailhub-dingtalk-notify.lock';

    public function __construct(private NotificationService $notifications, private IConfig $config,
        private LoggerInterface $logger) {}

    /** @return bool true when tick completed; false for a harmless, nonblocking busy skip. */
    public function run(): bool {
        $handle = null; $locked = false;
        try {
            $directory = $this->config->getSystemValueString('datadirectory', '');
            if (DIRECTORY_SEPARATOR !== '/' || !function_exists('posix_geteuid')
                || $directory === '' || $directory[0] !== '/' || ($directory = realpath($directory)) === false
                || !is_dir($directory)) { throw new \RuntimeException('Invalid lock directory'); }
            $path = $directory . '/' . self::LOCK_FILE;
            clearstatcache(true, $path);
            $existing = @lstat($path);
            if ($existing === false) {
                $mask = umask(0077);
                try { $handle = @fopen($path, 'x+b'); }
                finally { umask($mask); }
            }
            if (!is_resource($handle)) {
                clearstatcache(true, $path);
                if (!$this->safeFile(@lstat($path))) { throw new \RuntimeException('Unsafe lock file'); }
                $handle = @fopen($path, 'r+b');
            }
            if (!is_resource($handle)) { throw new \RuntimeException('Cannot open lock'); }
            $this->verifyHandle($handle, $path);
            $wouldBlock = 0;
            if (!@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                if ($wouldBlock === 1) { return false; }
                throw new \RuntimeException('Cannot acquire lock');
            }
            $locked = true;
            $this->verifyHandle($handle, $path);
            $this->notifications->tick();
            return true;
        } catch (\Throwable) {
            // No path, SQL, recipient, upstream response or exception chain is exposed.
            try { $this->config->setAppValue('mailhub_dingtalk', 'notifications_last_error', 'notification_runner_failed'); }
            catch (\Throwable) { /* Preserve a sanitized failure if the database is unavailable. */ }
            try { $this->logger->error('Mailhub notification runner failed; inspect administrative notification status', ['app' => 'mailhub_dingtalk']); }
            catch (\Throwable) { /* Logging must not interfere with lock release. */ }
            throw new \RuntimeException('Mailhub notification runner failed');
        } finally {
            if (is_resource($handle)) {
                if ($locked) { @flock($handle, LOCK_UN); }
                @fclose($handle);
            }
        }
    }

    private function safeFile(array|false $stat): bool {
        return $stat !== false && ($stat['mode'] & 0170000) === 0100000
            && ($stat['mode'] & 0777) === 0600 && (int)$stat['nlink'] === 1
            && (int)$stat['uid'] === posix_geteuid();
    }

    /** @param resource $handle */
    private function verifyHandle($handle, string $path): void {
        clearstatcache(true, $path);
        $named = @lstat($path); $opened = @fstat($handle);
        if (!$this->safeFile($named) || !$this->safeFile($opened)
            || $named['dev'] !== $opened['dev'] || $named['ino'] !== $opened['ino']) {
            throw new \RuntimeException('Lock inode changed');
        }
    }
}

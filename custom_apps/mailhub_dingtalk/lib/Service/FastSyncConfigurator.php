<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;

use OCA\Mail\BackgroundJob\SyncJob;
use OCP\IConfig;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Version-gated CLI scheduling override; never executes a sync or patches Mail files.
 * NC 34 JobList::buildJob resolves the same cached SyncJob through OCP\Server.
 */
final class FastSyncConfigurator {
    public const EFFECTIVE_INTERVAL = 1;

    public function __construct(private IConfig $config, private LoggerInterface $logger) {}

    public function configure(bool $isCli): bool {
        if (!$isCli || PHP_SAPI !== 'cli'
            || $this->config->getSystemValue('app.mailhub.fast-sync-enabled', false) !== true
            || $this->config->getSystemValue('app.mail.background-sync-interval', 0) !== 60
            || $this->config->getAppValue('mail', 'installed_version', '') !== '5.12.5'
            || preg_match('/^34\.\d+\.\d+(?:\.\d+)?$/D', $this->config->getSystemValueString('version', '')) !== 1) {
            return false;
        }
        try {
            $job = Server::get(SyncJob::class);
            if (!$job instanceof SyncJob) { throw new \RuntimeException('Unexpected Mail sync service'); }
            // The deployment invokes cron once a minute. Jobs can move earlier in
            // the next round, so a near-60-second threshold can skip that round.
            // NC 34 CronService tracks executed job IDs and never starts the same
            // ID twice in one CLI round. A one-second eligibility threshold avoids
            // most order-related skips; it does not create a one-second scheduler.
            // The constructor already observed the positive configured interval, so
            // its forced interval/skip flags, sync implementation and locks stay intact.
            $job->setInterval(self::EFFECTIVE_INTERVAL);
            return true;
        } catch (\Throwable) {
            // A missing/disabled service must not prevent the rest of cron from booting.
            $this->logger->warning('Mailhub fast sync unavailable; retaining native Mail scheduling', ['app' => 'mailhub_dingtalk']);
            return false;
        }
    }
}

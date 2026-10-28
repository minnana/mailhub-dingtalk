<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\BackgroundJob;

use OCA\MailhubDingtalk\Service\NotificationRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

final class NotificationJob extends TimedJob {
    public function __construct(ITimeFactory $time, private NotificationRunner $runner) {
        parent::__construct($time);
        // TimedJob uses elapsed > interval. A one-minute cron needs scheduling headroom.
        $this->setInterval(55);
        $this->setTimeSensitivity(self::TIME_SENSITIVE);
        $this->setAllowParallelRuns(false);
    }
    protected function run($argument): void {
        // The same local lock also covers the post-sync CLI entry point.
        // Runner failures are already logged without private exception details.
        try { $this->runner->run(); } catch (\Throwable) {}
    }
}

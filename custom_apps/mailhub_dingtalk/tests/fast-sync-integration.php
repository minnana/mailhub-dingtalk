<?php
declare(strict_types=1);
/** Read-only real-container check. Does not run jobs, reserve queue rows or contact IMAP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('OC_CONSOLE', 1);
$root = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
$source = $root . '/custom_apps/mail/lib/BackgroundJob/SyncJob.php';
if (!is_file($source)) { throw new RuntimeException('Pinned Mail source file not found'); }
$beforeHash = hash_file('sha256', $source);
require $root . '/lib/base.php';

use OCA\Mail\BackgroundJob\SyncJob;
use OCA\MailhubDingtalk\Service\FastSyncConfigurator;
use OCP\Server;

$checks = 0;
function check(bool $condition, string $label): void {
    global $checks; ++$checks;
    if (!$condition) { throw new RuntimeException('FAILED: ' . $label); }
}
\OC_App::loadApp('mail');
\OC_App::loadApp('mailhub_dingtalk');
$config = Server::get(\OCP\IConfig::class);
check($config->getSystemValue('app.mailhub.fast-sync-enabled', false) === true, 'Explicit fast-sync switch is enabled');
check($config->getSystemValue('app.mail.background-sync-interval', 0) === 60, 'Native configured interval is integer 60');
check($config->getAppValue('mail', 'installed_version', '') === '5.12.5', 'Reviewed Mail version is installed');
$job = Server::get(SyncJob::class);
check($job->getInterval() === FastSyncConfigurator::EFFECTIVE_INTERVAL, 'Actual application boot yields the reviewed per-round eligibility threshold');
check(Server::get(SyncJob::class) === $job, 'DI lookup returns the same cached sync instance');
$forcedInterval = new ReflectionProperty(SyncJob::class, 'forcedSyncInterval');
$forcedSkip = new ReflectionProperty(SyncJob::class, 'forcedSkip');
check($forcedInterval->getValue($job) === true && $forcedSkip->getValue($job) === false, 'Constructor forced-interval and skip semantics remain intact');

// Use the actual JobList hydration method with a synthetic, unexecuted job row.
// This avoids relying on employees having any account, and performs no queue writes.
$jobList = Server::get(\OCP\BackgroundJob\IJobList::class);
check($jobList instanceof \OC\BackgroundJob\JobList, 'Reviewed core JobList implementation is active');
$build = new ReflectionMethod(\OC\BackgroundJob\JobList::class, 'buildJob');
$savedId = $job->getId(); $savedLastRun = $job->getLastRun(); $savedArgument = $job->getArgument();
try {
    $built = $build->invoke($jobList, ['class' => SyncJob::class, 'id' => '0', 'last_run' => 0, 'argument' => '{"accountId":-1}']);
    check($built === $job, 'Actual JobList::buildJob uses the adjusted singleton');
    check($built->getInterval() === 1, 'JobList retains one-second eligibility for minute cron rounds');
} finally {
    $job->setId($savedId); $job->setLastRun($savedLastRun); $job->setArgument($savedArgument);
}
check(hash_file('sha256', $source) === $beforeHash, 'Official Mail SyncJob file hash is unchanged');
check(!Server::get(\OCP\IUserSession::class)->isLoggedIn(), 'Probe has no employee login session');
echo "PASS $checks read-only fast-sync integration checks; effective_interval=1; official_source_unchanged=true; no sync executed.\n";

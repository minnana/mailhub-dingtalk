<?php
declare(strict_types=1);
/** CLI-only post-sync pass. Run as the normal Nextcloud web user, on the same host/data bind as cron. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
define('OC_CONSOLE', 1);
try {
    require dirname(__DIR__, 3) . '/lib/base.php';
    $config = \OCP\Server::get(\OCP\IConfig::class);
    if (\OCP\Util::needUpgrade() || $config->getSystemValueBool('maintenance', false)) {
        fwrite(STDOUT, "SKIP notification pass: maintenance or upgrade required.\n");
        exit(0);
    }
    $apps = \OCP\Server::get(\OCP\App\IAppManager::class);
    if (!$apps->isEnabledForUser('mailhub_dingtalk')) {
        fwrite(STDOUT, "SKIP notification pass: application disabled.\n");
        exit(0);
    }
    \OC_App::loadApp('mailhub_dingtalk');
    $ran = \OCP\Server::get(\OCA\MailhubDingtalk\Service\NotificationRunner::class)->run();
    fwrite(STDOUT, $ran ? "OK notification pass completed.\n" : "SKIP notification pass: another runner holds the lock.\n");
    exit(0);
} catch (\Throwable) {
    error_log('Mailhub post-sync notification pass failed');
    fwrite(STDERR, "ERROR notification pass failed; inspect administrative notification status.\n");
    exit(1);
}

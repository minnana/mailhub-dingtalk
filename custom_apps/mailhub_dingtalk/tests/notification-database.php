<?php
declare(strict_types=1);
/** Read-only integration smoke test: run as the Nextcloud web user in the application container. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('OC_CONSOLE', 1);
$root = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
require $root . '/lib/base.php';

use OCA\MailhubDingtalk\Service\NotificationRepository;
use OCA\MailhubDingtalk\Service\NotificationStatusService;

$repository = \OCP\Server::get(NotificationRepository::class);
$status = \OCP\Server::get(NotificationStatusService::class);
$checks = 0;
function check(bool $condition, string $name): void {
    global $checks;
    ++$checks;
    if (!$condition) { throw new \RuntimeException('FAILED: ' . $name); }
}

// Nonexistent identifiers exercise the real schema/query builder without fixtures or writes.
check(is_array($repository->inboxes()), 'INBOX account and corporate mapping join');
check($repository->mapping('__mailhub_schema_probe__') === null, 'mapping lookup');
check(is_array($repository->authorizationOverrides('__mailhub_schema_probe__')), 'fresh authorization projection reads only permitted preferences');
check($repository->batchState('__mailhub_schema_probe__') === null, 'nonexistent notification batch state');
check($repository->mailbox(-1) === null, 'Mail cache token and lock columns');
check($repository->cursor(-1) === null, 'notification cursor table');
check($repository->highUid(-1) === 0, 'Mail UID aggregate');
check($repository->candidates(-1, 0, 1) === [], 'Mail UID/deleted/draft columns without read-state filtering');
check(is_array($repository->status(null)), 'queue aggregates and recent failure query');
check(is_array($repository->pendingUsers()), 'pending queue query');
check(is_array($repository->dueBatches(0)), 'batch state and schedule query');
check($repository->accountsForUsers(['__mailhub_schema_probe__']) === [], 'safe account metadata projection');
check(is_array($status->adminStatus()), 'status dependency injection and configuration');
check($repository->transaction(static fn (): bool => true), 'connection transaction API');
echo "Notification database: $checks read-only checks passed. No mailbox data was changed and no notifications were sent.\n";

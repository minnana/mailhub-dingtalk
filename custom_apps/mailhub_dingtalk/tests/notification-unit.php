<?php
declare(strict_types=1);
require __DIR__ . '/../lib/Service/NotificationPolicy.php';
use OCA\MailhubDingtalk\Service\NotificationPolicy as P;

$checks = 0;
function check(bool $condition, string $name): void { global $checks; ++$checks; if (!$condition) { throw new RuntimeException('FAILED: ' . $name); } }
$now = 1800000000;
$mailbox = ['id' => 10, 'account_id' => 20, 'nc_uid' => 'dt_abc', 'corp_id' => 'dingCorp', 'ding_user_id' => 'employee_1', 'name' => 'INBOX',
    'sync_new_token' => base64_encode('V42,U900,H100'), 'sync_changed_token' => 'ready', 'sync_vanished_token' => 'ready',
    'sync_new_lock' => null, 'sync_changed_lock' => null, 'sync_vanished_lock' => null];
$cursor = ['account_id' => 20, 'nc_uid' => 'dt_abc', 'corp_id' => 'dingCorp', 'ding_user_id' => 'employee_1', 'uid_validity' => '42', 'epoch' => 'epoch1', 'active' => true];
check(P::validity($mailbox['sync_new_token']) === '42', 'parse fixed Mail token format');
check(P::validity('not-base64!') === null && P::validity(base64_encode('U42')) === null, 'malformed token fails closed');
check(P::ready($mailbox, $now), 'completed inbox eligible');
check(!P::ready(array_replace($mailbox, ['sync_new_token' => null]), $now), 'partial initial synchronization suppressed');
check(!P::ready(array_replace($mailbox, ['sync_changed_lock' => $now]), $now), 'concurrent sync suppressed');
check(!P::ready(array_replace($mailbox, ['name' => 'Sent']), $now), 'sent mailbox excluded');
check(P::mustBaseline(null, $mailbox, 'epoch1', true), 'first account never sends historical mail');
check(!P::mustBaseline($cursor, $mailbox, 'epoch1', true), 'existing unchanged baseline advances');
check(P::mustBaseline($cursor, $mailbox, 'epoch1', false), 'disabled notifications advance silently');
check(P::mustBaseline($cursor, $mailbox, 'epoch2', true), 're-enable epoch rebuilds baseline');
check(P::mustBaseline($cursor, array_replace($mailbox, ['sync_new_token' => base64_encode('V43,U900')]), 'epoch1', true), 'UIDVALIDITY reset suppresses historical cache rebuild');
check(P::mustBaseline($cursor, array_replace($mailbox, ['nc_uid' => 'dt_other']), 'epoch1', true), 'ownership change cannot inherit baseline');
check(P::eventKey($mailbox, 901) === P::eventKey($mailbox, 901), 'persistent deduplication key is stable');
check(P::eventKey($mailbox, 901) !== P::eventKey(array_replace($mailbox, ['nc_uid' => 'dt_other']), 901), 'deduplication is isolated by employee');
check(P::eventKey($mailbox, 901) !== P::eventKey(array_replace($mailbox, ['sync_new_token' => base64_encode('V43,U900')]), 901), 'UID validity participates in deduplication');
check(P::retryDelay(1) === 60 && P::retryDelay(2) === 120 && P::retryDelay(100) === 3600, 'retry is bounded exponential backoff');
check(P::digestSeconds() === 0, 'Default digest adds no artificial delay');
check(P::digestSeconds(-1) === 0 && P::digestSeconds(0) === 0, 'Digest window permits explicit zero and clamps negative values');
check(P::digestSeconds(1) === 1, 'Explicit one-second window is preserved');
check(P::digestSeconds(59) === 59, 'Explicit 59-second window is preserved');
check(P::digestSeconds(60) === 60, 'Explicit one-minute window is preserved');
check(P::digestSeconds(3601) === 3600, 'Digest window retains its maximum bound');
check(P::digestSeconds(300) === 300, 'Explicit longer digest configuration remains effective');
check(P::deliveryState(['task_id' => 123], 'employee_1') === 'pending', 'task acceptance is not delivery');
check(P::deliveryState(['send_result' => ['unread_user_id_list' => ['employee_2']]], 'employee_1') === 'pending', 'different recipient does not confirm delivery');
check(P::deliveryState(['send_result' => ['unread_user_id_list' => ['employee_1']]], 'employee_1') === 'delivered', 'target unread list confirms server delivery');
check(P::deliveryState(['send_result' => ['failed_user_id_list' => ['employee_1']]], 'employee_1') === 'retry', 'explicit failed delivery is retryable');
check(P::deliveryState(['send_result' => ['forbidden_list' => [['userid' => 'employee_1']]]], 'employee_1') === 'failed', 'forbidden recipient stops retries');
$batch = ['ding_user_id' => 'employee_1', 'message_count' => 3, 'created_at' => $now, 'subject' => 'secret subject', 'body' => 'private body'];
$payload = P::payload($batch, 123456, 'https://mail.example.com:18443/apps/mailhub_dingtalk/', 'dingExampleCorp');
check($payload['userid_list'] === 'employee_1' && $payload['to_all_user'] === false && !isset($payload['dept_id_list']), 'only one explicit employee recipient');
$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
check(!str_contains($encoded, 'secret subject') && !str_contains($encoded, 'private body') && !str_contains($encoded, 'attachment'), 'summary never transmits subjects, body or attachments');
check(str_contains($encoded, '3 封') && str_contains($payload['msg']['markdown']['text'], P::dingtalkLink('https://mail.example.com:18443/apps/mailhub_dingtalk/', 'dingExampleCorp', 123456)), 'count and platform-specific login link included');
$failed = false;
try { P::payload(array_replace($batch, ['ding_user_id' => 'employee_1,employee_2']), 1, 'https://example.com', 'dingExampleCorp'); } catch (InvalidArgumentException) { $failed = true; }
check($failed, 'recipient comma injection rejected');

$target = 'https://mail.example.com:18443/index.php/apps/mailhub_dingtalk/?view=inbox&next=a%26b#/folder/(archive)';
$link = P::dingtalkLink($target, 'dingExampleCorp', 123456);
$outer = parse_url($link); $query = [];
parse_str($outer['query'], $query);
$pc = parse_url($query['pcLink']); $pcQuery = []; parse_str($pc['query'], $pcQuery);
$mobile = parse_url($query['mobileLink']); $mobileQuery = []; parse_str($mobile['query'], $mobileQuery);
check($outer['scheme'] === 'dingtalk' && $outer['host'] === 'dingtalkclient' && $outer['path'] === '/action/open_platform_link', 'Fixed official cross-platform DingTalk route');
check(array_keys($query) === ['pcLink', 'mobileLink'] && $pcQuery['redirect_url'] === $target && $mobileQuery === ['url' => $target], 'Both platforms retain high port, query and fragment through two decoding layers');
check(!isset($outer['fragment']) && !str_contains($link, '(') && !str_contains($link, ')'), 'Fragment and Markdown parentheses remain encoded inside target');
$injected = 'https://mail.example.com/?container_type=browser&corpid=other&pcLink=evil&mobileLink=evil&redirect_url=https://other.example/#)[injected](https://other.example/)';
$injectedQuery = []; parse_str(parse_url(P::dingtalkLink($injected, 'dingExampleCorp', 123456), PHP_URL_QUERY), $injectedQuery);
$injectedPc = []; parse_str(parse_url($injectedQuery['pcLink'], PHP_URL_QUERY), $injectedPc);
check(array_keys($injectedQuery) === ['pcLink', 'mobileLink'] && $injectedPc === ['corpid' => 'dingExampleCorp', 'container_type' => 'work_platform', 'app_id' => '0_123456', 'redirect_type' => 'jump', 'redirect_url' => $injected], 'Target cannot override corporate app, desktop container or platform routing');
$upperQuery = []; parse_str(parse_url(P::dingtalkLink('HTTPS://mail.example.com/', 'dingExampleCorp', 123456), PHP_URL_QUERY), $upperQuery);
check($upperQuery['mobileLink'] === 'dingtalk://dingtalkclient/page/link?url=HTTPS%3A%2F%2Fmail.example.com%2F', 'HTTPS scheme check is case insensitive');
foreach ([
    'http://mail.example.com/',
    'javascript:alert(1)',
    'dingtalk://dingtalkclient/page/link?url=https://mail.example.com/',
    '//mail.example.com/',
    'https:///missing-host',
    'https://employee:password@mail.example.com/',
    "https://mail.example.com/\n[injected](https://other.example/)",
    'https://mail.example.com/space here',
    'https://mail.example.com\\@other.example/',
] as $invalid) {
    $rejected = false;
    try { P::dingtalkLink($invalid, 'dingExampleCorp', 123456); } catch (InvalidArgumentException) { $rejected = true; }
    check($rejected, 'Reject unsafe or non-HTTPS deep-link target');
}
check($pc['scheme'] === 'dingtalk' && $pc['host'] === 'dingtalkclient' && $pc['path'] === '/action/openapp', 'Desktop uses application open protocol');
check($pcQuery === ['corpid' => 'dingExampleCorp', 'container_type' => 'work_platform', 'app_id' => '0_123456', 'redirect_type' => 'jump', 'redirect_url' => $target], 'Desktop selects configured internal app and jumps to SSO in work platform');
check($mobile['scheme'] === 'dingtalk' && $mobile['host'] === 'dingtalkclient' && $mobile['path'] === '/page/link' && !isset($mobileQuery['pc_slide']), 'Mobile stays in H5 page container without desktop options');
$expectedPc = 'dingtalk://dingtalkclient/action/openapp?corpid=dingExampleCorp&container_type=work_platform&app_id=0_123456&redirect_type=jump&redirect_url=' . rawurlencode($target);
$expectedMobile = 'dingtalk://dingtalkclient/page/link?url=' . rawurlencode($target);
check($link === 'dingtalk://dingtalkclient/action/open_platform_link?pcLink=' . rawurlencode($expectedPc) . '&mobileLink=' . rawurlencode($expectedMobile), 'Exact nested encoding matches official two-platform construction');
foreach (['', 'dingCorp&app_id=0_999', 'dingCorp#fragment', "dingCorp\n", 'dingCorp%26redirect_url=evil', str_repeat('a', 129)] as $invalidCorp) {
    $rejected = false;
    try { P::dingtalkLink($target, $invalidCorp, 123456); } catch (InvalidArgumentException) { $rejected = true; }
    check($rejected, 'Reject malformed or injecting corporate identifier');
}
foreach ([0, -1] as $invalidAgent) {
    $rejected = false;
    try { P::dingtalkLink($target, 'dingExampleCorp', $invalidAgent); } catch (InvalidArgumentException) { $rejected = true; }
    check($rejected, 'Reject nonpositive application identifier');
}
$rejected = false;
try { P::dingtalkLink($target, 'dingExampleCorp', '123&redirect_url=evil'); } catch (TypeError) { $rejected = true; }
check($rejected, 'Strict integer application identifier rejects query injection');
echo "Notification policy: $checks checks passed. No network calls or real messages were made.\n";

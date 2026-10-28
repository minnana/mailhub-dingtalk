<?php
declare(strict_types=1);
// Standalone isolation test. Stub interfaces/API never bootstrap Nextcloud or open a network connection.
namespace OCP { interface IConfig {} interface IUserManager {} interface IURLGenerator {} }
namespace OCP\AppFramework\Utility { interface ITimeFactory {} }
namespace Psr\Log { interface LoggerInterface {} }
namespace OCA\MailhubDingtalk\Service {
    class Settings {
        public array $values = ['enabled' => true, 'notifications_enabled' => false, 'notifications_epoch' => 'one'];
        public function reload(): void {}
        public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
        public function corpId(): string { return 'corp'; }
        public function agentId(): string { return '123'; }
        public function permits(string $uid): bool { return $uid === 'employee'; }
    }
    class DingTalkApiException extends \RuntimeException { public function getApiCode(): int { return 88; } }
    class DingTalkClient {
        public int $calls = 0; public int $sends = 0; public string $mode = 'success'; public array $payloads = [];
        public string $pollMode = 'success'; public ?\Closure $tokenHook = null; public ?\Closure $sendHook = null; public ?\Closure $pollHook = null;
        public function accessToken(): string { ++$this->calls; if ($this->tokenHook) { ($this->tokenHook)(); } return 'fake-token-only'; }
        public function postLegacy(string $path, array $payload): array {
            ++$this->calls;
            if (str_ends_with($path, 'getsendresult')) {
                if ($this->pollHook) { ($this->pollHook)(); }
                if ($this->pollMode === 'pending') { return ['send_result' => []]; }
                if ($this->pollMode === 'failed') { return ['send_result' => ['failed_user_id_list' => ['employee']]]; }
                return ['send_result' => ['unread_user_id_list' => ['employee']]];
            }
            ++$this->sends; $this->payloads[] = $payload;
            if ($this->sendHook) { ($this->sendHook)(); }
            if ($this->mode === 'rejected') { throw new DingTalkApiException(); }
            if ($this->mode === 'timeout') { throw new \RuntimeException('simulated unknown result'); }
            return ['errcode' => 0, 'task_id' => 1000 + $this->sends];
        }
    }
    class NotificationRepository {
        public array $boxes = []; public array $messages = []; public array $cursors = []; public array $items = []; public array $batches = [];
        public array $authOverrides = []; public ?\Closure $authSource = null;
        public function inboxes(): array { return array_values($this->boxes); }
        public function mapping(string $uid): ?array { return $uid === 'dt_employee' ? ['nc_uid' => $uid, 'corp_id' => 'corp', 'ding_user_id' => 'employee'] : null; }
        public function authorizationOverrides(string $uid): array { return array_replace($this->authSource ? ($this->authSource)() : [], $this->authOverrides); }
        public function batchState(string $key): ?string { return $this->batches[$key]['state'] ?? null; }
        public function transaction(callable $fn): mixed { return $fn(); }
        public function cursor(int $id): ?array { return $this->cursors[$id] ?? null; }
        public function highUid(int $id): int { return max(array_merge([0], array_column($this->messages[$id] ?? [], 'uid'))); }
        public function candidates(int $id, int $after, int $through): array { return array_values(array_filter($this->messages[$id] ?? [], fn($m) => $m['uid'] > $after && $m['uid'] <= $through)); }
        public function mailbox(int $id): ?array { return $this->boxes[$id] ?? null; }
        public function saveCursor(array $box, string $epoch, bool $active, int $high, int $now): void {
            $this->cursors[$box['id']] = $box + ['epoch' => $epoch, 'active' => $active, 'high_uid' => $high, 'uid_validity' => NotificationPolicy::validity($box['sync_new_token'])];
        }
        public function enqueue(array $box, int $uid, int $now): void {
            $key = NotificationPolicy::eventKey($box, $uid);
            if (isset($this->items[$key])) { throw new \RuntimeException('duplicate event'); }
            $this->items[$key] = ['nc_uid' => $box['nc_uid'], 'created_at' => $now, 'batch_key' => null];
        }
        public function pendingUsers(): array { return array_values(array_unique(array_column(array_filter($this->items, fn($i) => $i['batch_key'] === null), 'nc_uid'))); }
        public function cancelUser(string $uid, int $now): void {
            foreach ($this->items as $key => $item) { if ($item['nc_uid'] === $uid && $item['batch_key'] === null) { unset($this->items[$key]); } }
            foreach ($this->batches as &$b) { if ($b['nc_uid'] === $uid && in_array($b['state'], ['queued','retry'], true)) { $b['state'] = 'cancelled'; } }
            foreach ($this->batches as &$b) { if ($b['nc_uid'] === $uid && in_array($b['state'], ['sending','submitted'], true)) { $b['state'] = 'uncertain'; } }
        }
        public function createBatch(string $uid, array $mapping, string $epoch, int $now, int $delay): void {
            foreach ($this->batches as $b) { if ($b['nc_uid'] === $uid && in_array($b['state'], ['queued','retry','sending'], true)) { return; } }
            $items = array_filter($this->items, fn($i) => $i['nc_uid'] === $uid && $i['batch_key'] === null);
            if ($items === [] || min(array_column($items, 'created_at')) + $delay > $now) { return; }
            $key = (string)(count($this->batches) + 1);
            $this->batches[$key] = $mapping + ['batch_key' => $key, 'epoch' => $epoch, 'message_count' => count($items), 'created_at' => $now,
                'state' => 'queued', 'attempts' => 0, 'poll_attempts' => 0, 'next_attempt' => $now, 'updated_at' => $now];
            foreach ($items as $id => $item) { $this->items[$id]['batch_key'] = $key; }
        }
        public function dueBatches(int $now): array { return array_values(array_filter($this->batches, fn($b) => in_array($b['state'], ['queued','retry','submitted'], true) && $b['next_attempt'] <= $now)); }
        public function updateBatch(string $key, array $fields, ?string $expected = null): bool {
            if (!isset($this->batches[$key]) || ($expected !== null && $this->batches[$key]['state'] !== $expected)) { return false; }
            $this->batches[$key] = array_replace($this->batches[$key], $fields); return true;
        }
        public function recoverAbandonedSends(int $now): void {}
        public function pruneHistory(int $now): void {}
    }
}
namespace {
    require __DIR__ . '/../lib/Service/NotificationPolicy.php';
    require __DIR__ . '/../lib/Service/NotificationService.php';
    use OCA\MailhubDingtalk\Service\NotificationRepository;
    use OCA\MailhubDingtalk\Service\NotificationService;
    use OCA\MailhubDingtalk\Service\Settings;
    use OCA\MailhubDingtalk\Service\DingTalkClient;
    $checks = 0;
    function check(bool $condition, string $name): void { global $checks; ++$checks; if (!$condition) { throw new \RuntimeException('FAILED: ' . $name); } }
    $repo = new NotificationRepository(); $settings = new Settings(); $client = new DingTalkClient();
    $clock = new class implements \OCP\AppFramework\Utility\ITimeFactory { public int $now = 1800000000; public function getTime(): int { return $this->now; } };
    $config = new class implements \OCP\IConfig {
        public array $app = ['mail:installed_version' => '5.12.5']; public array $user = [];
        public function getAppValue($app, $key, $default='') { return $this->app["$app:$key"] ?? $default; }
        public function setAppValue($app, $key, $value): void { $this->app["$app:$key"]=$value; }
        public function getUserValue($uid,$app,$key,$default='') { return $this->user["$uid:$key"] ?? $default; }
    };
    $users = new class implements \OCP\IUserManager {
        public bool $enabled = true;
        public function get($uid) { return new class($this->enabled) { public function __construct(private bool $enabled) {} public function isEnabled(): bool { return $this->enabled; } }; }
    };
    $urls = new class implements \OCP\IURLGenerator { public function linkToRouteAbsolute($name): string { return 'https://mail.example.com:18443/apps/mailhub_dingtalk/'; } };
    $logger = new class implements \Psr\Log\LoggerInterface { public array $errors=[]; public function warning($message,$context): void { $this->errors[]=$message; } };
    $service = new NotificationService($repo,$settings,$config,$users,$urls,$clock,$client,$logger);
    $repo->authSource = static fn(): array => ['runtime_epoch' => $config->app['mailhub_dingtalk:notifications_runtime_epoch'] ?? 'initial',
        'user_epoch' => $config->user['dt_employee:notifications_epoch'] ?? 'initial'];
    $repo->boxes[10]=['id'=>10,'account_id'=>20,'nc_uid'=>'dt_employee','corp_id'=>'corp','ding_user_id'=>'employee','name'=>'INBOX',
        'sync_new_token'=>base64_encode('V42,U4,H1'),'sync_changed_token'=>'yes','sync_vanished_token'=>'yes',
        'sync_new_lock'=>null,'sync_changed_lock'=>null,'sync_vanished_lock'=>null];
    $add = function(int $uid, bool $seen = false, bool $deleted = false, bool $draft = false) use ($repo): void {
        $repo->messages[10][]=['uid'=>$uid,'flag_seen'=>$seen,'flag_deleted'=>$deleted,'flag_draft'=>$draft];
        $validity = \OCA\MailhubDingtalk\Service\NotificationPolicy::validity($repo->boxes[10]['sync_new_token']);
        $repo->boxes[10]['sync_new_token']=base64_encode('V'.$validity.',U'.($uid+1).',H'.$uid);
    };
    $add(1); $add(2, true); $add(3); $service->tick();
    check($client->calls===0 && $repo->items===[] && $repo->cursors[10]['high_uid']===3,'global off performs baseline only, zero API calls');
    $settings->values['notifications_enabled']=true; $settings->values['notifications_epoch']='two'; $clock->now+=61; $service->tick();
    check($repo->items===[] && $client->calls===0,'first enable does not push historical mail');
    $repo->messages[10][1]['flag_seen']=false; $service->tick();
    check($repo->items===[] && $client->calls===0,'marking historical read mail unread does not create a new arrival');
    $settings->values['notification_digest_seconds']=60;
    $add(4); $clock->now+=61; $service->tick();
    check(count($repo->items)===1 && $client->sends===0,'new unread message honors explicitly configured 60-second digest');
    $clock->now+=59; $service->tick();
    check($client->sends===0 && $repo->batches===[],'59 seconds does not prematurely form a digest');
    $clock->now+=1; $service->tick();
    check($client->sends===1 && $repo->batches['1']['state']==='submitted','exactly 60 seconds submits one personal digest');
    check(str_contains($client->payloads[0]['msg']['markdown']['text'], \OCA\MailhubDingtalk\Service\NotificationPolicy::dingtalkLink('https://mail.example.com:18443/apps/mailhub_dingtalk/', $settings->corpId(), (int)$settings->agentId())), 'service passes server-configured corporate app into the platform-specific login link');
    $clock->now+=61; $service->tick();
    check($client->sends===1 && $repo->batches['1']['state']==='delivered','server result confirms target delivery without resend');
    $service->tick(); check($client->sends===1 && count($repo->items)===1,'repeated scans are idempotent');
    $add(5, true); $add(6); $add(7, false, true); $add(8, true, false, true); $clock->now+=61; $service->tick();
    check(count($repo->items)===3 && $repo->cursors[10]['high_uid']===8,'new read and unread arrivals enqueue while deleted and draft messages only advance watermark');
    $repo->messages[10][4]['flag_seen']=false; $service->tick(); $repo->messages[10][4]['flag_seen']=true; $service->tick();
    check(count($repo->items)===3 && $client->sends===1,'repeated read-state changes do not enqueue the same new UID again');
    $clock->now+=61; $client->mode='rejected'; $service->tick();
    check($repo->batches['2']['message_count']===2 && $repo->batches['2']['state']==='retry','multiple messages merge, explicit rejection retries');
    $before=$client->sends; $clock->now+=30; $service->tick(); check($client->sends===$before,'retry honors backoff');
    $clock->now+=31; $client->mode='success'; $service->tick(); $clock->now+=61; $service->tick();
    check($repo->batches['2']['state']==='delivered','retry path eventually confirms delivery');
    $add(9); $clock->now+=61; $service->tick(); $clock->now+=61; $client->mode='timeout'; $service->tick();
    check($repo->batches['3']['state']==='uncertain','ambiguous transport result is visible as uncertain');
    $before=$client->sends; $clock->now+=4000; $service->tick(); check($client->sends===$before,'unknown acceptance is never blindly resent');
    $users->enabled=false; $add(10, true); $clock->now+=61; $service->tick();
    check($client->sends===$before && $repo->cursors[10]['high_uid']===10,'disabled employee advances baseline without notification');
    $users->enabled=true; $clock->now+=61; $service->tick(); check($client->sends===$before,'re-enabled employee gets no historical backlog');
    $repo->boxes[10]['sync_new_token']=base64_encode('V43,U11,H1'); $clock->now+=61; $service->tick();
    check($client->sends===$before && $repo->cursors[10]['uid_validity']==='43','mailbox UIDVALIDITY rebuild stays silent');
    $settings->values['notification_digest_seconds']=300; $client->mode='success'; $add(11, true); $clock->now+=61; $service->tick();
    $before=$client->sends; $clock->now+=299; $service->tick();
    check($client->sends===$before,'explicit 300-second window still waits after 299 seconds');
    $clock->now+=1; $service->tick();
    check($client->sends===$before+1 && $repo->batches['4']['message_count']===1,'explicit 300-second window sends the read arrival at its configured boundary');
    $clock->now+=61; $service->tick(); // Confirm the preceding batch before each independent boundary scenario.
    foreach ([null, 0, 1, 59, 60] as $index => $configuredDelay) {
        if ($configuredDelay === null) { unset($settings->values['notification_digest_seconds']); }
        else { $settings->values['notification_digest_seconds']=$configuredDelay; }
        $delay=$configuredDelay ?? 0; $before=$client->sends; $batchKey=(string)(5+$index);
        $add(12+2*$index, true); $add(13+2*$index); $service->tick();
        if ($delay === 0) {
            check($client->sends===$before+1 && $repo->batches[$batchKey]['message_count']===2,
                $configuredDelay === null ? 'default zero merges new read and unread arrivals and submits in the same scan' : 'explicit zero submits current arrivals without waiting');
            $service->tick();
            check($client->sends===$before+1, 'zero delay does not duplicate submissions on repeated scans');
        } else {
            check($client->sends===$before, 'explicit positive delay does not submit immediately');
            $clock->now+=$delay-1; $service->tick();
            check($client->sends===$before, 'explicit 1/59/60-second delay does not submit before its boundary');
            ++$clock->now; $service->tick();
            check($client->sends===$before+1 && $repo->batches[$batchKey]['message_count']===2, 'explicit 1/59/60-second delay submits exactly at its boundary');
        }
        $clock->now+=61; $service->tick();
    }
    $settings->values['notification_digest_seconds']=0; $client->pollMode='pending'; $before=$client->sends;
    $add(22); $service->tick(); $first=(string)count($repo->batches);
    $clock->now+=61; $add(23); $service->tick(); $second=(string)count($repo->batches);
    check($client->sends===$before+2 && $first!==$second && $repo->batches[$first]['state']==='submitted'
        && $repo->batches[$second]['state']==='submitted', 'Unconfirmed receipt for an accepted task does not block a newer arrival');
    $service->tick(); check($client->sends===$before+2, 'Independent receipt polling never resubmits accepted batches');

    $race = static function () use ($repo,$client,$settings,$config,$users,$service,$clock): void {
        $repo->boxes=[]; $repo->messages=[]; $repo->items=[]; $repo->cursors=[]; $repo->authOverrides=[];
        $client->tokenHook=null; $client->sendHook=null; $client->pollHook=null; $client->mode='success'; $client->pollMode='success';
        $users->enabled=true; $settings->values['notifications_enabled']=true; $config->user=[];
        $repo->batches=['race'=>['batch_key'=>'race','nc_uid'=>'dt_employee','corp_id'=>'corp','ding_user_id'=>'employee',
            'epoch'=>$service->epoch('dt_employee'),'message_count'=>1,'created_at'=>$clock->now,'updated_at'=>$clock->now,
            'state'=>'queued','attempts'=>0,'poll_attempts'=>0,'next_attempt'=>$clock->now,'task_id'=>null]];
    };
    foreach (['user_enabled'=>'false','user_deleted'=>'true','user_notifications_enabled'=>'0','global_notifications_enabled'=>'0',
        'global_epoch'=>'changed','user_epoch'=>'changed','runtime_epoch'=>'changed'] as $key=>$value) {
        $race(); $before=$client->sends;
        $client->tokenHook=static function () use ($repo,$key,$value): void { $repo->authOverrides[$key]=$value; };
        $service->tick();
        check($client->sends===$before && $repo->batches['race']['state']==='cancelled',
            'Fresh database authorization change during token acquisition prevents submission: '.$key);
    }
    $race(); $before=$client->sends;
    $client->tokenHook=static function () use ($repo,$clock): void { $repo->cancelUser('dt_employee',$clock->now); };
    $service->tick();
    check($client->sends===$before && $repo->batches['race']['state']==='uncertain', 'A durable concurrent cancellation cannot be overridden before submission');
    foreach (['success','rejected','timeout'] as $mode) {
        $race(); $client->mode=$mode; $before=$client->sends;
        $client->sendHook=static function () use ($repo,$clock): void { $repo->cancelUser('dt_employee',$clock->now); };
        $service->tick();
        check($client->sends===$before+1 && $repo->batches['race']['state']==='uncertain', 'Revocation during an in-flight request is not revived by a late '.$mode.' response');
        $clock->now+=4000; $service->tick();
        check($client->sends===$before+1, 'Revoked in-flight batch is never resent after '.$mode.' response');
    }
    foreach (['success','failed','pending'] as $mode) {
        $race(); $repo->batches['race']['state']='submitted'; $repo->batches['race']['task_id']='2000'; $client->pollMode=$mode;
        $client->pollHook=static function () use ($repo,$clock): void { $repo->cancelUser('dt_employee',$clock->now); };
        $before=$client->sends; $service->tick();
        check($repo->batches['race']['state']==='uncertain' && $client->sends===$before, 'Concurrent revocation is preserved after a '.$mode.' delivery result');
    }
    $race(); $repo->authOverrides['global_notifications_enabled']='0'; $before=$client->calls; $service->tick();
    check($client->calls===$before && $repo->batches['race']['state']==='cancelled', 'Already-revoked global preference makes no token or message API call despite stale config cache');
    $config->app['mail:installed_version']='99.0.0'; $before=$client->calls; $service->tick();
    check($client->calls===$before && $config->app['mailhub_dingtalk:notifications_last_error']==='unsupported_mail_version','unknown Mail schema fails closed');
    check($logger->errors===[],'scenarios complete without swallowed scanner errors');
    echo "Notification flow: $checks checks passed with in-memory repository and fake API. No real notifications were sent.\n";
}

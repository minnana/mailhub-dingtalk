<?php
declare(strict_types=1);
// Small behavioral tests runnable with `php tests/auth-unit.php`, no live credentials.
namespace OCP {
    interface IConfig { public function getSystemValue($key, $default); public function getAppValue($app, $key, $default); }
    interface ISession { public function get($key); public function set($key, $value); public function remove($key); public function regenerateId(); }
    interface IUserSession { public function logout(); }
    interface ICacheFactory { public function createDistributed($prefix); }
}
namespace OCP\Http\Client { interface IClientService { public function newClient(); } }
namespace OC\Security\CSRF {
    class CsrfTokenManager {
        public int $refreshes = 0;
        public function __construct(private \OCP\ISession $session) {}
        public function refreshToken() {
            $this->refreshes++;
            $this->session->set('csrf', 'new-csrf-token');
            return new class { public function getEncryptedValue(): string { return 'new-encrypted-csrf-token'; } };
        }
    }
}
namespace {
    use OCA\MailhubDingtalk\Service\DingTalkApiException;
    use OCA\MailhubDingtalk\Service\DingTalkClient;
    use OCA\MailhubDingtalk\Service\DingTalkTransportException;
    use OCA\MailhubDingtalk\Service\LoginNonce;
    use OCA\MailhubDingtalk\Service\LoginFlow;
    use OCA\MailhubDingtalk\Service\Settings;
    use OCA\MailhubDingtalk\Service\UserProvisioner;
    foreach (['Settings', 'DingTalkApiException', 'DingTalkTransportException', 'DingTalkClient', 'LoginNonce', 'LoginFlow', 'UserProvisioner'] as $class) {
        require_once __DIR__ . '/../lib/Service/' . $class . '.php';
    }
    $passed = 0;
    function check(bool $condition, string $description): void { global $passed; if (!$condition) throw new RuntimeException($description); $passed++; }
    class FakeConfig implements \OCP\IConfig {
        public array $values = []; public array $prefs = [];
        public function getSystemValue($key, $default) { return $this->values; }
        public function getAppValue($app, $key, $default) { return $this->prefs[$key] ?? $default; }
    }
    class FakeSession implements \OCP\ISession {
        public array $values = []; public int $regenerations = 0;
        public function get($key) { return $this->values[$key] ?? null; }
        public function set($key, $value) { $this->values[$key] = $value; }
        public function remove($key) { unset($this->values[$key]); }
        public function regenerateId() { $this->regenerations++; }
    }
    class FakeUsers implements \OCP\IUserSession {
        public int $logouts = 0;
        public function __construct(private FakeSession $session) {}
        public function logout() { $this->logouts++; $this->session->values = []; }
    }
    class FakeCache implements \OCP\ICacheFactory {
        public array $data = [];
        public function createDistributed($prefix) { return $this; }
        public function get($key) { return $this->data[$key] ?? null; }
        public function set($key, $value, $ttl) { $this->data[$key] = $value; }
        public function remove($key) { unset($this->data[$key]); }
    }
    class FakeHttp implements \OCP\Http\Client\IClientService {
        public array $replies = []; public array $calls = [];
        public function newClient() { return $this; }
        public function post($url, $options) {
            $this->calls[] = [$url, $options]; $reply = array_shift($this->replies);
            if ($reply instanceof Throwable) throw $reply;
            return new class($reply) {
                public function __construct(private array $value) {}
                public function getStatusCode() { return $this->value['http'] ?? 200; }
                public function getBody() { return json_encode($this->value['data']); }
            };
        }
    }
    putenv('MAILHUB_DINGTALK_CONFIG');
    $cfg = new FakeConfig(); $settings = new Settings($cfg);
    check(!$settings->permits('employee'), 'Default configuration must deny provisioning');
    $zeroCfg = new FakeConfig(); $zeroCfg->values = ['notification_digest_seconds' => 0];
    check((new Settings($zeroCfg))->get('notification_digest_seconds', 300) === 0, 'Explicit zero notification delay is not replaced by a fallback');
    try { $settings->requireEnabled(); throw new RuntimeException('Disabled settings were accepted'); } catch (RuntimeException $e) { check($e->getMessage() !== 'Disabled settings were accepted', 'Disabled configuration fails closed'); }
    $cfg = new FakeConfig(); $cfg->values = ['enabled' => true, 'corp_id' => 'ding-test', 'client_id' => 'test-app', 'client_secret' => 'test-secret', 'allowed_userids' => ['123']];
    $settings = new Settings($cfg);
    check($settings->permits('123') && !$settings->permits('0123') && !$settings->permits('456'), 'Strict user allowlist');
    check(!str_contains(json_encode($settings->publicStatus()), 'test-secret'), 'Status must never contain secret');
    $cfg->prefs['notifications_enabled'] = '1'; check($settings->get('notifications_enabled', false) === true, 'Admin non-secret preference is effective');
    $session = new FakeSession(); $nonce = new LoginNonce($session); $value = $nonce->issue();
    check(strlen($value) === 64 && $nonce->consume($value), 'Fresh flow nonce accepted');
    check(!$nonce->consume($value), 'Flow nonce cannot be replayed');
    $value = $nonce->issue(); check(!$nonce->consume(str_repeat('0', 64)) && !$nonce->consume($value), 'Wrong nonce also burns flow');
    $value = $nonce->issue(); $session->values['mailhub_dingtalk.login']['expires'] = time() - 1; check(!$nonce->consume($value), 'Expired flow rejected');
    $session = new FakeSession(); $nonce = new LoginNonce($session);
    $users = new FakeUsers($session); $csrf = new \OC\Security\CSRF\CsrfTokenManager($session);
    $flow = new LoginFlow($nonce, $users, $session, $csrf);
    $session->set('user_id', 'previous-employee'); $session->set('csrf', 'old-csrf-token');
    $pageNonce = $nonce->issue('begin');
    try { $flow->begin(false, $pageNonce); throw new RuntimeException('HTTP begin accepted'); }
    catch (RuntimeException $e) { check($e->getMessage() !== 'HTTP begin accepted', 'HTTP begin rejected'); }
    check($users->logouts === 0 && $session->get('user_id') === 'previous-employee', 'Invalid begin does not terminate existing identity');
    $prepared = $flow->begin(true, $pageNonce);
    check($users->logouts === 1 && $session->get('user_id') === null, 'Begin clears previous identity before any configuration or SDK access');
    check($session->regenerations === 1, 'Begin regenerates anonymous session identifier');
    check($csrf->refreshes === 1 && $session->get('csrf') === 'new-csrf-token'
        && $prepared['requesttoken'] === 'new-encrypted-csrf-token', 'Begin refreshes cached and stored CSRF token after logout');
    check($prepared['nonce'] !== $pageNonce && $nonce->consume($prepared['nonce']), 'Authentication receives a fresh purpose-bound nonce');
    try { $flow->begin(true, $pageNonce); throw new RuntimeException('Begin replay accepted'); }
    catch (RuntimeException $e) { check($e->getMessage() !== 'Begin replay accepted' && $users->logouts === 1, 'Begin replay cannot log out another session'); }
    $pageNonce = $nonce->issue('begin');
    check(!$nonce->consume($pageNonce), 'Page begin nonce cannot skip straight to authentication');
    $authNonce = $nonce->issue();
    try { $flow->begin(true, $authNonce); throw new RuntimeException('Wrong-purpose begin accepted'); }
    catch (RuntimeException $e) { check($e->getMessage() !== 'Wrong-purpose begin accepted' && $users->logouts === 1, 'Authentication nonce cannot be used for begin'); }
    $uid = UserProvisioner::stableUid('corp-a', 'employee');
    check(strlen($uid) <= 64 && $uid === UserProvisioner::stableUid('corp-a', 'employee'), 'Stable bounded user identifier');
    check($uid !== UserProvisioner::stableUid('corp-b', 'employee'), 'Different enterprises cannot share employee account');
    check(UserProvisioner::stableUid('ab', 'c') !== UserProvisioner::stableUid('a', 'bc'), 'Identity delimiter prevents ambiguous concatenation');
    $http = new FakeHttp(); $cache = new FakeCache(); $client = new DingTalkClient($settings, $http, $cache);
    $http->replies = [['data' => ['accessToken' => 'fake-access-token', 'expireIn' => 7200]], ['data' => ['errcode' => 0, 'result' => ['userid' => '123', 'name' => 'Employee']]]];
    $identity = $client->identity('one-time-code'); check($identity['user_id'] === '123', 'Identity comes from provider');
    check($http->calls[0][0] === 'https://api.dingtalk.com/v1.0/oauth2/accessToken' && $http->calls[0][1]['allow_redirects'] === false, 'Fixed HTTPS token endpoint without redirects');
    $client->accessToken(); check(count($http->calls) === 2, 'Application token is cached');
    try { $client->postLegacy('/evil', []); throw new RuntimeException('arbitrary endpoint allowed'); } catch (InvalidArgumentException) { check(true, 'Arbitrary endpoint rejected before network'); }
    $http->replies = [['data' => ['errcode' => 40014, 'errmsg' => 'must not leak fake-access-token']]];
    try { $client->postLegacy('/topapi/v2/user/getuserinfo', []); throw new RuntimeException('Business rejection was accepted'); } catch (DingTalkApiException $e) { check($e->getApiCode() === 40014 && !str_contains($e->getMessage(), 'fake-access-token'), 'Explicit API failure remains distinguishable and redacted'); }
    check($cache->data === [], 'Invalid provider token evicts cache');
    $http->replies = [['data' => ['accessToken' => 'replacement-token', 'expireIn' => 7200]], new RuntimeException('https://example.invalid/?access_token=fake-access-token')];
    try { $client->postLegacy('/topapi/v2/user/getuserinfo', []); throw new RuntimeException('Transport failure was accepted'); } catch (DingTalkTransportException $e) { check($e->getPrevious() === null && !str_contains($e->getMessage(), 'fake-access-token'), 'Transport failure must not retain sensitive exception chain'); }
    $http->replies = [['data' => ['errcode' => 0, 'result' => ['name' => 'Without identity']]]];
    try { $client->identity('code'); throw new RuntimeException('missing userid allowed'); } catch (DingTalkTransportException) { check(true, 'Missing verified identity rejected'); }
    echo "PASS {$passed} authentication behavior checks\n";
}

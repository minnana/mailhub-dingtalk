<?php
declare(strict_types=1);
// Isolated behavior tests: no Nextcloud bootstrap, credentials or external requests.
namespace OCP {
    interface IConfig {} interface ISession {} interface IUser {} interface IUserSession {}
    interface IUserManager { public const MAX_PASSWORD_LENGTH = 469; }
}
namespace OCP\Config { interface IUserConfig {} }
namespace OCP\Encryption { interface IManager {} }
namespace OCP\AppFramework\Utility { interface ITimeFactory {} }
namespace OC\Authentication\Token { interface IProvider {} }
namespace OCA\MailhubDingtalk\Service {
    class DingTalkClient {
        public string $uid = 'employee'; public int $calls = 0; public $during = null;
        public function identity(string $code): array {
            ++$this->calls;
            if ($code !== 'fake-code') { throw new \RuntimeException('Rejected fake code'); }
            if ($this->during !== null) { ($this->during)(); }
            return ['user_id' => $this->uid, 'name' => 'Employee'];
        }
    }
    class NcSessionAdapter {
        public array $revoked = []; public bool $fail = false;
        public function revoke(string $uid): void { $this->revoked[] = $uid; if ($this->fail) { throw new \RuntimeException('Fake revoke failure'); } }
    }
}
namespace {
    use OCA\MailhubDingtalk\Service\{DingTalkClient,NcSessionAdapter,Settings,UserProvisioner,WebPasswordNonce,WebPasswordService};
    foreach (['Settings','UserProvisioner','WebPasswordNonce','WebPasswordService'] as $name) { require __DIR__.'/../lib/Service/'.$name.'.php'; }
    putenv('MAILHUB_DINGTALK_CONFIG');
    $checks = 0;
    function check(bool $condition, string $label): void { global $checks; ++$checks; if (!$condition) { throw new RuntimeException('FAILED: '.$label); } }
    function rejects(callable $fn): bool { try { $fn(); return false; } catch (Throwable) { return true; } }
    class TestConfig implements \OCP\IConfig {
        public string $version='34.0.5'; public array $prefs=[];
        public array $values=['enabled'=>true,'corp_id'=>'dingTest','client_id'=>'fake-client','client_secret'=>'fake-secret','allowed_userids'=>['employee']];
        public function getSystemValue($key,$default=null) { return $key==='mailhub_dingtalk' ? $this->values : $default; }
        public function getSystemValueString($key,$default='') { return $key==='version' ? $this->version : $default; }
        public function getAppValue($app,$key,$default='') { return $this->prefs[$key] ?? $default; }
    }
    class TestSession implements \OCP\ISession {
        public array $values=[];
        public function get($key) { return $this->values[$key] ?? null; }
        public function set($key,$value): void { $this->values[$key]=$value; }
        public function remove($key): void { unset($this->values[$key]); }
        public function getId(): string { return 'fake-session-id'; }
    }
    class TestUser implements \OCP\IUser {
        public bool $enabled=true; public bool $canChange=true; public bool $throwAfterWrite=false; public bool $rejectWrite=false;
        public int $writes=0; public ?string $saved=null;
        public function __construct(public string $uid) {}
        public function getUID(): string { return $this->uid; }
        public function getDisplayName(): string { return 'Employee'; }
        public function isEnabled(): bool { return $this->enabled; }
        public function canChangePassword(): bool { return $this->canChange; }
        public function setPassword($password): bool {
            ++$this->writes; if ($this->rejectWrite) { return false; } $this->saved=$password;
            if ($this->throwAfterWrite) { throw new RuntimeException('Fake post-password listener failure'); }
            return true;
        }
    }
    class TestUsers implements \OCP\IUserSession {
        public int $logouts=0; public ?string $impersonator=null;
        public function __construct(public ?TestUser $user) {}
        public function getUser() { return $this->user; }
        public function getImpersonatingUserID() { return $this->impersonator; }
        public function logout(): void { ++$this->logouts; $this->user=null; }
    }
    class TestTokens implements \OC\Authentication\Token\IProvider {
        public bool $revoked=false; public bool $databaseRevoked=false;
        public function __construct(public string $uid) {}
        public function getToken($id) {
            if ($this->revoked) { throw new RuntimeException('Revoked token'); }
            return new class($this->uid) { public function __construct(private string $uid) {} public function getUID() { return $this->uid; } public function getId() { return 7; } };
        }
        public function getTokenById($id) { if ($this->databaseRevoked) { throw new RuntimeException('Revoked in database'); } return $this->getToken('fake-session-id'); }
    }
    class TestProvisioner extends UserProvisioner {
        public function __construct(public ?array $row) {}
        public function findByUid(string $uid): ?array { return $this->row; }
    }
    class TestUserConfig implements \OCP\Config\IUserConfig {
        public array $enabled=[]; public array $deleted=[];
        public function getValuesByUsers($app,$key,$type,$uids): array { return $key==='enabled' ? $this->enabled : $this->deleted; }
    }
    function fixture(): object {
        $f = new stdClass(); $f->uid=UserProvisioner::stableUid('dingTest','employee');
        $f->config=new TestConfig(); $f->settings=new Settings($f->config); $f->session=new TestSession();
        $f->clock=new class implements \OCP\AppFramework\Utility\ITimeFactory { public int $now=1000; public function getTime() { return $this->now; } };
        $f->nonce=new WebPasswordNonce($f->session,$f->clock); $f->user=new TestUser($f->uid); $f->users=new TestUsers($f->user);
        $f->tokens=new TestTokens($f->uid); $f->mapping=new TestProvisioner(['nc_uid'=>$f->uid,'corp_id'=>'dingTest','ding_user_id'=>'employee']);
        $f->client=new DingTalkClient(); $f->sessions=new NcSessionAdapter(); $f->userConfig=new TestUserConfig();
        $f->encryption=new class implements \OCP\Encryption\IManager { public bool $enabled=false; public function isEnabled() { return $this->enabled; } };
        $f->service=new WebPasswordService($f->users,$f->session,$f->tokens,$f->mapping,$f->settings,$f->client,$f->nonce,$f->sessions,$f->config,$f->userConfig,$f->encryption);
        $f->value=$f->nonce->issue($f->uid);
        return $f;
    }
    function change(object $f, string $password='Correct Horse 2026!'): void { $f->service->change(true,$f->value,'fake-code',$password,$password); }

    $f=fixture(); $f->session->set('mailhub_dingtalk.login',['sentinel'=>true]);
    check($f->nonce->consume($f->uid,$f->value),'fresh user-bound nonce accepted');
    check(!$f->nonce->consume($f->uid,$f->value),'nonce replay rejected');
    check($f->session->get('mailhub_dingtalk.login')===['sentinel'=>true],'password nonce does not consume SSO nonce');
    $f=fixture(); check(!$f->nonce->consume('other',$f->value),'nonce cannot transfer to another employee');
    check(!$f->nonce->consume($f->uid,$f->value),'wrong employee burns nonce');
    $f=fixture(); $f->clock->now+=301; check(!$f->nonce->consume($f->uid,$f->value),'expired nonce rejected');
    check(rejects(fn()=>WebPasswordService::validatePassword('short','short')),'short password rejected');
    check(rejects(fn()=>WebPasswordService::validatePassword(str_repeat('x',129),str_repeat('x',129))),'overlong password rejected');
    check(rejects(fn()=>WebPasswordService::validatePassword("Long Password\n", "Long Password\n")),'control characters rejected');
    check(rejects(fn()=>WebPasswordService::validatePassword('Correct Horse 2026!','Different Horse 2026!')),'confirmation mismatch rejected');
    check(!rejects(fn()=>WebPasswordService::validatePassword('Correct Horse 2026!','Correct Horse 2026!')),'long passphrase accepted for native password policy');
    $f=fixture(); check($f->service->account()===['uid'=>$f->uid,'name'=>'Employee'],'page exposes only current existing username and display name');
    change($f);
    check($f->user->writes===1 && $f->user->saved==='Correct Horse 2026!','native user password setter called exactly once');
    check($f->sessions->revoked===[$f->uid] && $f->users->logouts===1,'all old tokens revoked and current session logged out');
    check($f->mapping->row['nc_uid']===$f->uid,'password setup preserves existing account mapping');
    check(rejects(fn()=>change($f)) && $f->user->writes===1,'replay after successful reset cannot write again');

    foreach (['anonymous','impersonated','disabled','deleted','unmapped','wrong_corp','tampered_mapping','removed_allowlist','revoked_token','wrong_token','encryption','unsupported','paused','cannot_change'] as $case) {
        $f=fixture();
        switch ($case) {
            case 'anonymous': $f->users->user=null; break;
            case 'impersonated': $f->users->impersonator='admin'; break;
            case 'disabled': $f->userConfig->enabled[$f->uid]='false'; break;
            case 'deleted': $f->userConfig->deleted[$f->uid]='true'; break;
            case 'unmapped': $f->mapping->row=null; break;
            case 'wrong_corp': $f->mapping->row['corp_id']='dingOther'; break;
            case 'tampered_mapping': $f->mapping->row['ding_user_id']='other'; break;
            case 'removed_allowlist': $f->config->values['allowed_userids']=[]; break;
            case 'revoked_token': $f->tokens->revoked=true; break;
            case 'wrong_token': $f->tokens->uid='other'; break;
            case 'encryption': $f->encryption->enabled=true; break;
            case 'unsupported': $f->config->version='35.0.0'; break;
            case 'paused': $f->config->prefs['login_enabled']='0'; break;
            case 'cannot_change': $f->user->canChange=false; break;
        }
        check(rejects(fn()=>change($f)) && $f->user->writes===0,'reject before password write: '.$case);
    }
    $f=fixture(); check(rejects(fn()=>$f->service->change(false,$f->value,'fake-code','Correct Horse 2026!','Correct Horse 2026!')) && $f->client->calls===0,'HTTP cannot start remote identity verification');
    $f=fixture(); $f->client->uid='other'; check(rejects(fn()=>change($f)) && $f->user->writes===0,'fresh DingTalk identity must match current account');
    $f=fixture(); $f->client->during=function() use ($f): void { $f->tokens->databaseRevoked=true; };
    check(rejects(fn()=>change($f)) && $f->user->writes===0,'cached token cannot hide revocation during provider request');
    foreach (['disable','remove','revoke','encrypt','change_corp'] as $case) {
        $f=fixture(); $f->client->during=function() use ($f,$case): void {
            if ($case==='disable') { $f->userConfig->enabled[$f->uid]='false'; }
            if ($case==='remove') { $f->config->values['allowed_userids']=[]; }
            if ($case==='revoke') { $f->tokens->revoked=true; }
            if ($case==='encrypt') { $f->encryption->enabled=true; }
            if ($case==='change_corp') { $f->config->values['corp_id']='dingOther'; }
        };
        check(rejects(fn()=>change($f)) && $f->user->writes===0,'recheck authorization after provider request: '.$case);
    }
    $f=fixture(); $f->user->throwAfterWrite=true;
    check(rejects(fn()=>change($f)) && $f->user->writes===1 && $f->sessions->revoked===[$f->uid] && $f->users->logouts===1,'post-update failure still revokes old sessions');
    $f=fixture(); $f->sessions->fail=true;
    check(rejects(fn()=>change($f)) && $f->users->logouts===1,'revocation failure still attempts current logout');
    echo "PASS $checks isolated web-password checks; no real accounts or credentials accessed.\n";
}

<?php
declare(strict_types=1);
/** Isolated NC34 integration: real temporary user/hash/tokens, fake DingTalk identity.
 * No real employee, mailbox, remote identity exchange or notification is used.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('OC_CONSOLE', 1);
require (getenv('NEXTCLOUD_ROOT') ?: '/var/www/html') . '/lib/base.php';
// Allows preflight against a staged candidate before the production app is changed.
foreach (['Settings','WebPasswordNonce','WebPasswordService'] as $file) {
    require_once __DIR__ . '/../lib/Service/' . $file . '.php';
}
use OCA\MailhubDingtalk\Service\{Settings,DingTalkClient,UserProvisioner,WebPasswordNonce,WebPasswordService,NcSessionAdapter};
use OCP\Server;
final class PasswordProbeSettings extends Settings {
    public function __construct(private string $corp, private string $ding) {}
    public function corpId(): string { return $this->corp; }
    public function permits(string $id): bool { return $id === $this->ding; }
    public function requireEnabled(): void {}
    public function reload(): void {}
    public function get(string $key, mixed $default = null): mixed { return $key === 'login_enabled' ? true : $default; }
}
final class PasswordProbeDingTalk extends DingTalkClient {
    public function __construct(public string $identityId) {}
    public function identity(string $code): array { return ['user_id'=>$this->identityId,'name'=>'isolated password QA']; }
}
final class PasswordProbeSession extends \OC\Session\Memory {
    public string $tokenId;
    public function getId(): string { return $this->tokenId; }
}
function verifyPasswordProbe(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('FAILED: ' . $label); }
    $GLOBALS['passwordProbeChecks']++;
}
function rejectPasswordProbe(callable $fn, string $label): void {
    $rejected = false;
    try { $fn(); } catch (RuntimeException|InvalidArgumentException $e) { $rejected = true; }
    verifyPasswordProbe($rejected, $label);
}
$GLOBALS['passwordProbeChecks']=0;
$db=Server::get(\OCP\IDBConnection::class);
$users=Server::get(\OCP\IUserManager::class);
$sessionUser=Server::get(\OCP\IUserSession::class);
$tokens=Server::get(\OC\Authentication\Token\IProvider::class);
$config=Server::get(\OCP\IConfig::class);
$tag='mailhub_password_probe_'.bin2hex(random_bytes(12));
$settings=new PasswordProbeSettings($tag,$tag);
$provisioner=new UserProvisioner($db,$users,$settings);
$uid=UserProvisioner::stableUid($tag,$tag);
$created=false;
$old=bin2hex(random_bytes(24)); $password=bin2hex(random_bytes(24));
$session=new PasswordProbeSession();
$nonce=new WebPasswordNonce($session,Server::get(\OCP\AppFramework\Utility\ITimeFactory::class));
$ding=new PasswordProbeDingTalk($tag);
$service=new WebPasswordService($sessionUser,$session,$tokens,$provisioner,$settings,$ding,$nonce,
    Server::get(NcSessionAdapter::class),$config,Server::get(\OCP\Config\IUserConfig::class),Server::get(\OCP\Encryption\IManager::class));
$establish=function($user) use($tokens,$session,$sessionUser,$uid): void {
    $session->tokenId=bin2hex(random_bytes(32));
    $tokens->generateToken($session->tokenId,$uid,$uid,null,'isolated web password QA');
    $sessionUser->setUser($user);
};
ob_start();
try {
    verifyPasswordProbe($users->get($uid)===null && $provisioner->findByUid($uid)===null,'fixture absent');
    $user=$provisioner->provision($tag,'isolated web password QA'); $created=true; $user->setQuota('0 B');
    verifyPasswordProbe($user->setPassword($old),'native initial password');
    $establish($user);
    $tokens->generateToken(bin2hex(random_bytes(32)),$uid,$uid,null,'isolated second session QA');
    verifyPasswordProbe(count($tokens->getTokenByUser($uid))===2,'two real tokens created');
    verifyPasswordProbe($service->account()['uid']===$uid,'personal page uses the existing mapping');
    $crypto=Server::get(\OCP\Security\ICrypto::class);
    $credential='synthetic-mail-credential-'.bin2hex(random_bytes(12));
    $encrypted=$crypto->encrypt($credential);
    $n=$nonce->issue($uid);
    $ding->identityId=$tag.'_other';
    rejectPasswordProbe(fn()=>$service->change(true,$n,'synthetic-code',$password,$password),'different DingTalk identity rejected');
    verifyPasswordProbe($users->checkPassword($uid,$old)!==false,'rejection preserved old password');
    $ding->identityId=$tag;
    rejectPasswordProbe(fn()=>$service->change(true,$n,'synthetic-code',$password,$password),'identity failure consumed nonce');
    $n=$nonce->issue($uid);
    $service->change(true,$n,'synthetic-code',$password,$password);
    verifyPasswordProbe($users->checkPassword($uid,$password)!==false,'new password accepted by native user backend');
    verifyPasswordProbe($users->checkPassword($uid,$old)===false,'old password rejected');
    verifyPasswordProbe($sessionUser->getUser()===null,'session logged out');
    verifyPasswordProbe(count($tokens->getTokenByUser($uid))===0,'all previous tokens revoked');
    verifyPasswordProbe($provisioner->findByUid($uid)['nc_uid']===$uid,'same mapped account retained');
    verifyPasswordProbe($crypto->decrypt($encrypted)===$credential,'instance-encrypted credential survives password change');
    $establish($user);
    rejectPasswordProbe(fn()=>$service->change(true,$n,'synthetic-code',$old,$old),'success nonce cannot replay');
    $n=$nonce->issue($uid);$user->setEnabled(false);
    rejectPasswordProbe(fn()=>$service->change(true,$n,'synthetic-code',$old,$old),'disabled employee cannot change password');
    $user->setEnabled(true);
} finally {
    $sessionUser->logout();
    if ($created) {
        $mapping=$provisioner->findByUid($uid);
        if (!$mapping || $mapping['corp_id']!==$tag || $mapping['ding_user_id']!==$tag) { throw new RuntimeException('Unexpected QA mapping: cleanup stopped'); }
        foreach($tokens->getTokenByUser($uid) as $t){$tokens->invalidateTokenById($uid,$t->getId());}
        $fixture=$users->get($uid);
        if ($fixture && !$fixture->delete()) { throw new RuntimeException('QA user cleanup failed'); }
        $q=$db->getQueryBuilder();$q->delete('mailhub_dt_users')->where($q->expr()->eq('nc_uid',$q->createNamedParameter($uid)))->andWhere($q->expr()->eq('corp_id',$q->createNamedParameter($tag)))->executeStatement();
    }
    ob_end_clean();
}
verifyPasswordProbe($users->get($uid)===null && $provisioner->findByUid($uid)===null,'temporary user and mapping removed');
echo 'PASS '.$GLOBALS['passwordProbeChecks']." native web password integration checks; QA account removed; no real DingTalk or mailbox requests.\n";

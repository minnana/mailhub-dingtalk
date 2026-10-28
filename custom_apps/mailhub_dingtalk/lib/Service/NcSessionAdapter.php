<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;

use OC\Authentication\Token\IProvider;
use OC\User\Session;
use OCP\Authentication\Token\IToken;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use OCP\User\Events\BeforeUserLoggedInEvent;
use OCP\User\Events\UserLoggedInEvent;

/** Version-scoped adapter following Nextcloud's own user_oidc login implementation.
 * IUserSession has no public passwordless-complete-login API. Do not replace this
 * with setUser alone or write session/token database rows directly.
 */
class NcSessionAdapter {
    public function __construct(private IUserSession $users, private ISession $session, private IRequest $request,
        private IEventDispatcher $events, private IProvider $tokens, private IConfig $config) {}
    public function login(IUser $user): void {
        $major = (int)explode('.', $this->config->getSystemValueString('version', '0'))[0];
        if ($major !== 34 || !$this->users instanceof Session) { throw new \RuntimeException('Unsupported Nextcloud session implementation'); }
        if (!$user->isEnabled()) { throw new \RuntimeException('Employee account disabled'); }
        try {
            $uid = $user->getUID();
            $this->events->dispatchTyped(new BeforeUserLoggedInEvent($uid, null));
            // PostLoginEvent's password is a string even for external authentication.
            // The persisted browser token below deliberately has a null password.
            $this->users->completeLogin($user, ['loginName' => $uid, 'password' => '']);
            if (!$this->users->createSessionToken($this->request, $uid, $uid, null, IToken::DO_NOT_REMEMBER)) {
                throw new \RuntimeException('Unable to create browser session');
            }
            $token = $this->tokens->getToken($this->session->getId());
            $scope = $token->getScopeAsArray();
            $scope[IToken::SCOPE_SKIP_PASSWORD_VALIDATION] = true;
            $token->setScope($scope);
            $this->tokens->updateToken($token);
            $this->events->dispatchTyped(new UserLoggedInEvent($user, $uid, null, false));
        } catch (\Throwable) {
            $this->users->logout();
            throw new \RuntimeException('Unable to establish a Nextcloud session');
        }
    }
    public function revoke(string $uid): void {
        foreach ($this->tokens->getTokenByUser($uid) as $token) { $this->tokens->invalidateTokenById($uid, $token->getId()); }
    }
}

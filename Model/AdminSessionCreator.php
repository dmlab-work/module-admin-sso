<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Model;

use DmLab\AdminSso\Model\Security\AdminSessionTracker;
use DmLab\AdminSso\Model\Session\IdpSessionStorage;
use DmLab\AdminSso\Model\TwoFactorAuth\SessionGranter as TwoFactorSessionGranter;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\User;

/**
 * Establishes an authenticated admin backend session for a provisioned user,
 * without a password — the identity was already verified by the OIDC callback.
 *
 * Mirrors what {@see \Magento\Backend\Model\Auth::login()} does after credential
 * validation: it puts the user on the auth storage, runs `processLogin`
 * (regenerates the session id, loads the ACL), records the login, fires the
 * `backend_auth_user_login_success` event so core listeners react as they would for
 * a native login, and registers the session with Magento_Security (account sharing).
 */
class AdminSessionCreator
{
    /**
     * @param AuthSession $authSession
     * @param EventManager $eventManager
     * @param UserResource $userResource
     * @param TwoFactorSessionGranter $twoFactorSessionGranter
     * @param AdminSessionTracker $adminSessionTracker
     * @param IdpSessionStorage $idpSessionStorage
     */
    public function __construct(
        private readonly AuthSession $authSession,
        private readonly EventManager $eventManager,
        private readonly UserResource $userResource,
        private readonly TwoFactorSessionGranter $twoFactorSessionGranter,
        private readonly AdminSessionTracker $adminSessionTracker,
        private readonly IdpSessionStorage $idpSessionStorage
    ) {
    }

    /**
     * Log the given admin user into the current backend session.
     *
     * @param User $user persisted admin user to authenticate
     * @param array<string,mixed> $claims validated ID-token claims; decide whether core 2FA is satisfied
     * @param string $idToken raw ID token, kept for IdP logout; empty keeps none
     * @param string $providerCode provider preset that issued the ID token
     */
    public function create(User $user, array $claims = [], string $idToken = '', string $providerCode = ''): void
    {
        $this->authSession->setUser($user);
        $this->authSession->processLogin();
        if ($idToken !== '') {
            $this->idpSessionStorage->save($idToken, $providerCode);
        }

        $this->twoFactorSessionGranter->grant($claims);

        $this->userResource->recordLogin($user);

        $this->eventManager->dispatch(
            'backend_auth_user_login_success',
            ['user' => $user]
        );

        $this->adminSessionTracker->processLogin();
    }
}

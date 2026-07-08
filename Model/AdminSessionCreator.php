<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Model;

use MageDevGroup\AdminSso\Model\TwoFactorAuth\SessionGranter as TwoFactorSessionGranter;
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
 * (regenerates the session id, loads the ACL), records the login, and fires the
 * `backend_auth_user_login_success` event so core listeners (2FA, notifications)
 * react as they would for a native login.
 */
class AdminSessionCreator
{
    /**
     * @param AuthSession $authSession
     * @param EventManager $eventManager
     * @param UserResource $userResource
     * @param TwoFactorSessionGranter $twoFactorSessionGranter
     */
    public function __construct(
        private readonly AuthSession $authSession,
        private readonly EventManager $eventManager,
        private readonly UserResource $userResource,
        private readonly TwoFactorSessionGranter $twoFactorSessionGranter
    ) {
    }

    /**
     * Log the given admin user into the current backend session.
     *
     * @param User $user persisted admin user to authenticate
     */
    public function create(User $user): void
    {
        $this->authSession->setUser($user);
        $this->authSession->processLogin();

        // The IdP already authenticated (and MFA'd) the user — satisfy core 2FA
        // for this SSO session so the admin is not challenged a second time.
        $this->twoFactorSessionGranter->grant();

        $this->userResource->recordLogin($user);

        $this->eventManager->dispatch(
            'backend_auth_user_login_success',
            ['user' => $user]
        );
    }
}

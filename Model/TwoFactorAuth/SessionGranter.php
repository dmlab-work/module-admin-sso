<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Model\TwoFactorAuth;

use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\TwoFactorAuth\Api\TfaSessionInterface;

/**
 * Marks the current admin session as having passed Magento core 2FA.
 *
 * The IdP already performed strong authentication (and its own MFA), so an SSO
 * login must not trigger a second 2FA challenge. Core 2FA gates every backend
 * request in {@see \Magento\TwoFactorAuth\Observer\ControllerActionPredispatch}
 * on {@see TfaSessionInterface::isGranted()}; granting it here satisfies that
 * check for the SSO session only. Native (and break-glass) logins never reach
 * this code, so they stay subject to the normal 2FA flow.
 *
 * `Magento_TwoFactorAuth` is an optional dependency: it is resolved lazily via
 * the object manager and only when the module is enabled, so this core works
 * whether or not 2FA is installed. Referencing {@see TfaSessionInterface} by
 * `::class` is a compile-time string and does not require the class to exist.
 */
class SessionGranter
{
    /** Module that provides the 2FA session contract, when installed. */
    private const TWO_FACTOR_AUTH_MODULE = 'Magento_TwoFactorAuth';

    /**
     * @param ModuleManager $moduleManager
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * Grant the current session's 2FA when the 2FA module is enabled; no-op otherwise.
     */
    public function grant(): void
    {
        if (!$this->moduleManager->isEnabled(self::TWO_FACTOR_AUTH_MODULE)) {
            return;
        }

        /** @var TfaSessionInterface $tfaSession */
        $tfaSession = $this->objectManager->get(TfaSessionInterface::class);
        $tfaSession->grantAccess();
    }
}

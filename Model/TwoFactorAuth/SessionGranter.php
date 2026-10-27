<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Model\TwoFactorAuth;

use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\TwoFactorAuth\Api\TfaSessionInterface;

/**
 * Marks the current admin session as having passed Magento core 2FA when the IdP
 * proved MFA for this login ({@see IdpMfaPolicy}); otherwise core 2FA challenges as usual.
 *
 * Core 2FA gates every backend request in
 * {@see \Magento\TwoFactorAuth\Observer\ControllerActionPredispatch} on
 * {@see TfaSessionInterface::isGranted()}; granting it here satisfies that check for
 * the SSO session only. Native (and break-glass) logins never reach this code.
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
     * @param IdpMfaPolicy $mfaPolicy
     */
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly ObjectManagerInterface $objectManager,
        private readonly IdpMfaPolicy $mfaPolicy
    ) {
    }

    /**
     * Grant the current session's 2FA when the 2FA module is enabled and the IdP proved MFA.
     *
     * @param array<string,mixed> $claims validated ID-token claims of this login
     */
    public function grant(array $claims): void
    {
        if (!$this->moduleManager->isEnabled(self::TWO_FACTOR_AUTH_MODULE)
            || !$this->mfaPolicy->isSatisfied($claims)
        ) {
            return;
        }

        /** @var TfaSessionInterface $tfaSession */
        $tfaSession = $this->objectManager->get(TfaSessionInterface::class);
        $tfaSession->grantAccess();
    }
}

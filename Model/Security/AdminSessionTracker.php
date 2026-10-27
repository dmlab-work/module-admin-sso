<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Model\Security;

use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Security\Model\AdminSessionsManager;

/**
 * Registers an SSO login with Magento_Security's admin session tracking.
 *
 * Native logins get this from {@see \Magento\Security\Model\Plugin\Auth::afterLogin()} on
 * `Backend\Model\Auth::login()`, which SSO bypasses. Without it the session row is created
 * lazily on the next request and other sessions are logged out regardless of the
 * Admin Account Sharing setting. `Magento_Security` is optional, so it is resolved lazily.
 */
class AdminSessionTracker
{
    /** Module that provides admin session tracking, when enabled. */
    private const SECURITY_MODULE = 'Magento_Security';

    /**
     * @param ModuleManager $moduleManager
     * @param ObjectManagerInterface $objectManager
     * @param MessageManager $messageManager
     */
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly ObjectManagerInterface $objectManager,
        private readonly MessageManager $messageManager
    ) {
    }

    /**
     * Record the new admin session and apply the account-sharing policy; no-op without Magento_Security.
     */
    public function processLogin(): void
    {
        if (!$this->moduleManager->isEnabled(self::SECURITY_MODULE)) {
            return;
        }

        /** @var AdminSessionsManager $sessionsManager */
        $sessionsManager = $this->objectManager->get(AdminSessionsManager::class);
        $sessionsManager->processLogin();
        if ($sessionsManager->getCurrentSession()->isOtherSessionsTerminated()) {
            $this->messageManager->addWarningMessage(
                __('All other open sessions for this account were terminated.')
            );
        }
    }
}

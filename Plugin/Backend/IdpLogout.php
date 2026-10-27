<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Plugin\Backend;

use DmLab\AdminSso\Model\Oidc\LogoutUrlResolver;
use DmLab\AdminSso\Model\Session\IdpSessionStorage;
use Magento\Backend\Controller\Adminhtml\Auth\Logout;
use Magento\Framework\Controller\Result\Redirect;

/**
 * After the core admin logout, sends an SSO-established session on to the IdP's end-session
 * endpoint (OIDC RP-Initiated Logout) instead of the login page.
 */
class IdpLogout
{
    /**
     * @param IdpSessionStorage $idpSessionStorage
     * @param LogoutUrlResolver $logoutUrlResolver
     */
    public function __construct(
        private readonly IdpSessionStorage $idpSessionStorage,
        private readonly LogoutUrlResolver $logoutUrlResolver
    ) {
    }

    /**
     * Run the local logout, then redirect to the IdP when it applies.
     *
     * @param Logout $subject
     * @param callable $proceed
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(Logout $subject, callable $proceed)
    {
        // Read before proceeding: the core logout destroys the session holding the token.
        $idToken = $this->idpSessionStorage->getIdToken();
        $providerCode = $this->idpSessionStorage->getProviderCode();

        $result = $proceed();
        if ($idToken === null || !$result instanceof Redirect) {
            return $result;
        }

        $logoutUrl = $this->logoutUrlResolver->resolve($idToken, (string)$providerCode);
        if ($logoutUrl !== null) {
            $result->setUrl($logoutUrl);
        }

        return $result;
    }
}

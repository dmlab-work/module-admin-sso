<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Plugin\Backend;

use MageDevGroup\AdminSso\Model\Config;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\AuthenticationException;

/**
 * Disables native username/password admin login while SSO is enforced.
 *
 * SSO logins never reach {@see Auth::login()} — they establish the backend
 * session directly via {@see \MageDevGroup\AdminSso\Model\AdminSessionCreator} —
 * so this plugin only ever sees native credential logins. When enforce is on it
 * rejects them, unless the break-glass toggle is enabled and the request carries
 * the break-glass param: that guarded local-admin path keeps a lockout always
 * recoverable if the IdP is misconfigured.
 */
class EnforceSso
{
    /** Request param a local admin adds to the login URL to use break-glass. */
    public const BREAK_GLASS_PARAM = 'break_glass';

    /**
     * @param Config $config
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly Config $config,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * Reject the native login before credentials are checked when SSO is enforced.
     *
     * @param Auth $subject
     * @param string $username
     * @param string $password
     * @return array{0: string, 1: string}
     * @throws AuthenticationException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeLogin(Auth $subject, $username, $password): array
    {
        if ($this->isNativeLoginBlocked()) {
            throw new AuthenticationException(
                __('Admin sign-in requires SSO. Use the "Sign in with SSO" button on the login page.')
            );
        }

        return [$username, $password];
    }

    /**
     * Whether the current native login attempt must be rejected.
     */
    private function isNativeLoginBlocked(): bool
    {
        if (!$this->config->isEnabled() || !$this->config->isEnforceSso()) {
            return false;
        }

        return !$this->isBreakGlassEngaged();
    }

    /**
     * Whether break-glass is both allowed by config and requested on this call.
     */
    private function isBreakGlassEngaged(): bool
    {
        return $this->config->isBreakGlassEnabled()
            && (bool)$this->request->getParam(self::BREAK_GLASS_PARAM);
    }
}

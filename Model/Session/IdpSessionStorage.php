<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Model\Session;

use Magento\Backend\Model\Auth\Session as AuthSession;

/**
 * Keeps the raw ID token of an SSO login in the admin auth session, for `id_token_hint` on logout.
 *
 * Its presence also marks the session as SSO-established; it is destroyed with the session.
 */
class IdpSessionStorage
{
    private const SESSION_KEY = 'dmlab_admin_sso_idp_session';

    /**
     * @param AuthSession $authSession
     */
    public function __construct(
        private readonly AuthSession $authSession
    ) {
    }

    /**
     * Remember the ID token and the provider that issued it.
     *
     * @param string $idToken
     * @param string $providerCode
     */
    public function save(string $idToken, string $providerCode): void
    {
        $this->authSession->setData(self::SESSION_KEY, [
            'id_token' => $idToken,
            'provider' => $providerCode,
        ]);
    }

    /**
     * ID token of the current SSO login, or null when the session was not established via SSO.
     */
    public function getIdToken(): ?string
    {
        return $this->read('id_token');
    }

    /**
     * Provider code of the current SSO login, or null.
     */
    public function getProviderCode(): ?string
    {
        return $this->read('provider');
    }

    /**
     * Read one non-empty string field of the stored entry.
     *
     * @param string $field
     */
    private function read(string $field): ?string
    {
        $entry = $this->authSession->getData(self::SESSION_KEY);
        $value = is_array($entry) ? ($entry[$field] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Typed reader over the module's admin configuration.
 *
 * Wraps {@see ScopeConfigInterface} so the rest of the module never touches raw
 * config paths, and decrypts the OIDC client secret (stored encrypted via the
 * Magento `Encrypted` backend model). Provider-neutral: no IdP-specific fields —
 * the concrete IdP comes from the active preset, resolved by
 * {@see ActiveProviderResolver}.
 */
class Config
{
    /** Whether admin SSO is enabled. */
    public const XML_PATH_ENABLED = 'dmlab_admin_sso/general/enabled';

    /** OIDC client (application) id issued by the IdP. */
    public const XML_PATH_CLIENT_ID = 'dmlab_admin_sso/general/client_id';

    /** OIDC client secret, stored encrypted. */
    public const XML_PATH_CLIENT_SECRET = 'dmlab_admin_sso/general/client_secret';

    /** Whether the native admin login form is disabled (SSO enforced). */
    public const XML_PATH_ENFORCE_SSO = 'dmlab_admin_sso/general/enforce_sso';

    /** Whether the break-glass local-admin path stays available under enforce. */
    public const XML_PATH_BREAK_GLASS = 'dmlab_admin_sso/general/break_glass';

    /** Admin usernames allowed native login under break-glass, newline/comma-separated. */
    public const XML_PATH_BREAK_GLASS_USERNAMES = 'dmlab_admin_sso/general/break_glass_usernames';

    /** IdP-group → ACL-role rules, one `group=role_id` per line. */
    public const XML_PATH_GROUP_ROLE_MAP = 'dmlab_admin_sso/general/group_role_map';

    /** ACL role id assigned when no IdP group matches a mapping rule. */
    public const XML_PATH_DEFAULT_ROLE = 'dmlab_admin_sso/general/default_role';

    /** Whether IdP-asserted MFA (`amr`/`acr`) satisfies Magento core 2FA. */
    public const XML_PATH_TRUST_IDP_MFA = 'dmlab_admin_sso/general/trust_idp_mfa';

    /** `acr` values accepted as MFA evidence, newline/comma-separated. */
    public const XML_PATH_TRUSTED_ACR_VALUES = 'dmlab_admin_sso/general/trusted_acr_values';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Whether admin SSO is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * Configured OIDC client id, or null when unset.
     */
    public function getClientId(): ?string
    {
        return $this->readNonEmptyString(self::XML_PATH_CLIENT_ID);
    }

    /**
     * Decrypted OIDC client secret, or null when unset.
     */
    public function getClientSecret(): ?string
    {
        $encrypted = $this->scopeConfig->getValue(self::XML_PATH_CLIENT_SECRET);
        if (!is_string($encrypted) || $encrypted === '') {
            return null;
        }

        $decrypted = $this->encryptor->decrypt($encrypted);

        return $decrypted === '' ? null : $decrypted;
    }

    /**
     * Whether the native admin login form is disabled (SSO enforced).
     */
    public function isEnforceSso(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENFORCE_SSO);
    }

    /**
     * Whether the break-glass local-admin path stays available under enforce.
     */
    public function isBreakGlassEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_BREAK_GLASS);
    }

    /**
     * Break-glass allowlist: trimmed, lowercased, unique admin usernames.
     *
     * @return string[]
     */
    public function getBreakGlassUsernames(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_BREAK_GLASS_USERNAMES);
        if (!is_string($raw)) {
            return [];
        }

        $usernames = [];
        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $username) {
            $username = mb_strtolower(trim($username));
            if ($username !== '') {
                $usernames[$username] = $username;
            }
        }

        return array_values($usernames);
    }

    /**
     * Parsed IdP-group → ACL-role-id map.
     *
     * Reads the `group=role_id` lines in configured order (= priority); blank lines and
     * `#` comments are ignored, and a repeated group keeps its first line.
     *
     * @return array<string,string> group name → ACL role id, in rule order
     */
    public function getGroupRoleMap(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_GROUP_ROLE_MAP);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $map = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$group, $roleId] = explode('=', $line, 2);
            $group = trim($group);
            $roleId = trim($roleId);
            if ($group !== '' && $roleId !== '' && !array_key_exists($group, $map)) {
                $map[$group] = $roleId;
            }
        }

        return $map;
    }

    /**
     * Fallback ACL role id for identities that match no group rule.
     *
     * Null when unset, in which case SSO login is refused for an unmapped identity.
     */
    public function getDefaultRoleId(): ?string
    {
        return $this->readNonEmptyString(self::XML_PATH_DEFAULT_ROLE);
    }

    /**
     * Whether IdP-asserted MFA may satisfy Magento core 2FA.
     */
    public function isTrustIdpMfa(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_TRUST_IDP_MFA);
    }

    /**
     * `acr` values accepted as MFA evidence: trimmed, unique, case kept (acr values are URIs/opaque).
     *
     * @return string[]
     */
    public function getTrustedAcrValues(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_TRUSTED_ACR_VALUES);
        if (!is_string($raw)) {
            return [];
        }

        $values = [];
        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $value) {
            $value = trim($value);
            if ($value !== '') {
                $values[$value] = $value;
            }
        }

        return array_values($values);
    }

    /**
     * Read a config value as a trimmed non-empty string, or null.
     *
     * @param string $path
     */
    private function readNonEmptyString(string $path): ?string
    {
        $value = $this->scopeConfig->getValue($path);
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}

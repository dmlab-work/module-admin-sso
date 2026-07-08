<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Model;

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
    public const XML_PATH_ENABLED = 'magedevgroup_admin_sso/general/enabled';

    /** OIDC client (application) id issued by the IdP. */
    public const XML_PATH_CLIENT_ID = 'magedevgroup_admin_sso/general/client_id';

    /** OIDC client secret, stored encrypted. */
    public const XML_PATH_CLIENT_SECRET = 'magedevgroup_admin_sso/general/client_secret';

    /** Whether the native admin login form is disabled (SSO enforced). */
    public const XML_PATH_ENFORCE_SSO = 'magedevgroup_admin_sso/general/enforce_sso';

    /** Whether the break-glass local-admin path stays available under enforce. */
    public const XML_PATH_BREAK_GLASS = 'magedevgroup_admin_sso/general/break_glass';

    /** IdP-group → ACL-role rules, one `group=role_id` per line. */
    public const XML_PATH_GROUP_ROLE_MAP = 'magedevgroup_admin_sso/general/group_role_map';

    /** ACL role id assigned when no IdP group matches a mapping rule. */
    public const XML_PATH_DEFAULT_ROLE = 'magedevgroup_admin_sso/general/default_role';

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
     * Parsed IdP-group → ACL-role-id map.
     *
     * Reads the `group=role_id` lines; blank lines and `#` comments are ignored,
     * later entries win on duplicate groups. The actual mapping is applied by the
     * role assigner (a later task); this only exposes the configured rules.
     *
     * @return array<string,string> group name → ACL role id
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
            if ($group !== '' && $roleId !== '') {
                $map[$group] = $roleId;
            }
        }

        return $map;
    }

    /**
     * ACL role id used as the fallback when no IdP group matches a rule, or null
     * when unset (in which case an unmapped identity gets no role — deny).
     */
    public function getDefaultRoleId(): ?string
    {
        return $this->readNonEmptyString(self::XML_PATH_DEFAULT_ROLE);
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

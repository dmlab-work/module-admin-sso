<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Model\TwoFactorAuth;

use DmLab\AdminSso\Model\Config;

/**
 * Decides whether the IdP proved multi-factor authentication for this login, so
 * Magento core 2FA may be skipped.
 *
 * Evidence comes from the validated ID token: `amr` (RFC 8176) listing `mfa`/`mca`, or a
 * possession/biometric factor alongside another method; or an `acr` value the admin
 * configured as MFA-strength. No evidence, or "Trust IdP MFA" off → Magento 2FA applies.
 */
class IdpMfaPolicy
{
    /** `amr` values that assert multiple factors on their own. */
    private const MULTI_FACTOR_METHODS = ['mfa', 'mca'];

    /** `amr` possession/biometric methods; count as MFA together with another method. */
    private const SECOND_FACTOR_METHODS = [
        'otp', 'hwk', 'swk', 'sms', 'tel', 'sc', 'fpt', 'face', 'iris', 'retina', 'vbm',
    ];

    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Whether the ID-token claims prove MFA and the admin trusts the IdP's MFA.
     *
     * @param array<string,mixed> $claims validated ID-token claims
     */
    public function isSatisfied(array $claims): bool
    {
        if (!$this->config->isTrustIdpMfa()) {
            return false;
        }

        return $this->hasMfaAmr($claims['amr'] ?? null) || $this->hasTrustedAcr($claims['acr'] ?? null);
    }

    /**
     * Whether `amr` proves more than one factor.
     *
     * @param mixed $amr
     */
    private function hasMfaAmr(mixed $amr): bool
    {
        $methods = [];
        foreach (is_array($amr) ? $amr : [$amr] as $method) {
            if (is_string($method) && trim($method) !== '') {
                $methods[strtolower(trim($method))] = true;
            }
        }
        $methods = array_keys($methods);

        if (array_intersect($methods, self::MULTI_FACTOR_METHODS) !== []) {
            return true;
        }

        return count($methods) >= 2 && array_intersect($methods, self::SECOND_FACTOR_METHODS) !== [];
    }

    /**
     * Whether `acr` is one of the configured MFA-strength values.
     *
     * @param mixed $acr
     */
    private function hasTrustedAcr(mixed $acr): bool
    {
        return is_string($acr) && $acr !== '' && in_array($acr, $this->config->getTrustedAcrValues(), true);
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Model\TwoFactorAuth;

use DmLab\AdminSso\Model\Config;
use DmLab\AdminSso\Model\TwoFactorAuth\IdpMfaPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IdpMfaPolicyTest extends TestCase
{
    private function policy(bool $trust = true, array $acrValues = []): IdpMfaPolicy
    {
        $config = $this->createStub(Config::class);
        $config->method('isTrustIdpMfa')->willReturn($trust);
        $config->method('getTrustedAcrValues')->willReturn($acrValues);

        return new IdpMfaPolicy($config);
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:bool}>
     */
    public static function amrCases(): array
    {
        return [
            'okta mfa' => [['amr' => ['pwd', 'mfa', 'otp']], true],
            'entra mfa' => [['amr' => ['pwd', 'mfa']], true],
            'multiple-channel' => [['amr' => ['mca']], true],
            'password + otp' => [['amr' => ['pwd', 'otp']], true],
            'password + hardware key' => [['amr' => ['pwd', 'hwk']], true],
            'case-insensitive' => [['amr' => ['PWD', 'MFA']], true],
            'non-compliant string amr' => [['amr' => 'mfa'], true],
            'password only' => [['amr' => ['pwd']], false],
            'single possession factor' => [['amr' => ['sms']], false],
            'two knowledge factors' => [['amr' => ['pwd', 'kba']], false],
            'no amr (Google)' => [[], false],
            'garbage amr' => [['amr' => [['mfa'], 1, '']], false],
        ];
    }

    #[DataProvider('amrCases')]
    public function testAmrEvidence(array $claims, bool $expected): void
    {
        self::assertSame($expected, $this->policy()->isSatisfied($claims));
    }

    public function testTrustedAcrIsEvidence(): void
    {
        $mfaAcr = 'http://schemas.openid.net/pape/policies/2007/06/multi-factor';
        $policy = $this->policy(true, [$mfaAcr]);

        self::assertTrue($policy->isSatisfied(['acr' => $mfaAcr]));
        self::assertFalse($policy->isSatisfied(['acr' => 'urn:mace:incommon:iap:silver']));
    }

    public function testUntrustedIdpNeverSatisfies(): void
    {
        $policy = $this->policy(false, ['mfa-acr']);

        self::assertFalse($policy->isSatisfied(['amr' => ['mfa'], 'acr' => 'mfa-acr']));
    }
}

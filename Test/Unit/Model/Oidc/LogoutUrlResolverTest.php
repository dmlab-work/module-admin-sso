<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Model\Oidc;

use DmLab\AdminSso\Model\ActiveProviderResolver;
use DmLab\AdminSso\Model\Config;
use DmLab\AdminSso\Model\Oidc\LogoutUrlResolver;
use DmLab\SsoCore\Api\ProviderPresetInterface;
use DmLab\SsoCore\Exception\DiscoveryException;
use DmLab\SsoCore\Model\Oidc\DiscoveryClient;
use DmLab\SsoCore\Model\Oidc\LogoutUrlBuilder;
use DmLab\SsoCore\Model\Oidc\ProviderMetadata;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LogoutUrlResolverTest extends TestCase
{
    private const LOGIN_URL = 'https://magento.loc/admin/admin/auth/login/';
    private const END_SESSION = 'https://idp.example/logout';

    /** @var array<string,mixed> */
    private array $config = ['enabled' => true, 'idp_logout' => true, 'client_id' => 'cid'];

    private function resolver(
        DiscoveryClient $discoveryClient,
        ?string $activeCode = 'okta',
        ?LoggerInterface $logger = null
    ): LogoutUrlResolver {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($this->config['enabled']);
        $config->method('isIdpLogoutEnabled')->willReturn($this->config['idp_logout']);
        $config->method('getClientId')->willReturn($this->config['client_id']);

        $preset = null;
        if ($activeCode !== null) {
            $preset = $this->createStub(ProviderPresetInterface::class);
            $preset->method('getCode')->willReturn($activeCode);
            $preset->method('buildDiscoveryUrl')->willReturn('https://idp.example/.well-known/openid-configuration');
        }
        $providers = $this->createStub(ActiveProviderResolver::class);
        $providers->method('getActive')->willReturn($preset);

        $backendUrl = $this->createStub(BackendUrlInterface::class);
        $backendUrl->method('getUrl')->willReturnMap([
            ['adminhtml/auth/login', ['_nosecret' => true], self::LOGIN_URL],
        ]);

        return new LogoutUrlResolver(
            $config,
            $providers,
            $discoveryClient,
            new LogoutUrlBuilder(),
            $backendUrl,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    private function metadata(?string $endSession): ProviderMetadata
    {
        return new ProviderMetadata(
            'https://idp.example',
            'https://idp.example/authorize',
            'https://idp.example/token',
            'https://idp.example/jwks',
            [],
            [],
            false,
            $endSession
        );
    }

    public function testBuildsEndSessionUrl(): void
    {
        $discoveryClient = $this->createMock(DiscoveryClient::class);
        $discoveryClient->expects(self::once())
            ->method('discover')
            ->with('https://idp.example/.well-known/openid-configuration', true)
            ->willReturn($this->metadata(self::END_SESSION));

        $url = $this->resolver($discoveryClient)->resolve('raw-id-token', 'okta');

        self::assertSame(
            self::END_SESSION . '?id_token_hint=raw-id-token&client_id=cid&post_logout_redirect_uri='
            . rawurlencode(self::LOGIN_URL),
            $url
        );
    }

    public function testNullWhenIdpDoesNotAdvertiseEndSession(): void
    {
        $discoveryClient = $this->createStub(DiscoveryClient::class);
        $discoveryClient->method('discover')->willReturn($this->metadata(null));

        self::assertNull($this->resolver($discoveryClient)->resolve('raw-id-token', 'okta'));
    }

    public function testNullAndLoggedWhenDiscoveryFails(): void
    {
        $discoveryClient = $this->createStub(DiscoveryClient::class);
        $discoveryClient->method('discover')->willThrowException(new DiscoveryException('down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        self::assertNull($this->resolver($discoveryClient, 'okta', $logger)->resolve('raw-id-token', 'okta'));
    }

    /**
     * @return array<string, array{array<string,mixed>, ?string, string, string}>
     */
    public static function notApplicable(): array
    {
        return [
            'sso disabled' => [['enabled' => false], 'okta', 'tok', 'okta'],
            'idp logout off' => [['idp_logout' => false], 'okta', 'tok', 'okta'],
            'no client id' => [['client_id' => null], 'okta', 'tok', 'okta'],
            'no active provider' => [[], null, 'tok', 'okta'],
            'provider switched' => [[], 'azure', 'tok', 'okta'],
            'no id token' => [[], 'okta', '', 'okta'],
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @param string|null $activeCode
     * @param string $idToken
     * @param string $providerCode
     */
    #[DataProvider('notApplicable')]
    public function testNullWithoutDiscoveryWhenNotApplicable(
        array $config,
        ?string $activeCode,
        string $idToken,
        string $providerCode
    ): void {
        $this->config = $config + $this->config;
        $discoveryClient = $this->createMock(DiscoveryClient::class);
        $discoveryClient->expects(self::never())->method('discover');

        self::assertNull($this->resolver($discoveryClient, $activeCode)->resolve($idToken, $providerCode));
    }
}

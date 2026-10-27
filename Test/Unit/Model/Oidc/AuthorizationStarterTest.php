<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Model\Oidc;

use DmLab\AdminSso\Model\ActiveProviderResolver;
use DmLab\AdminSso\Model\Config;
use DmLab\AdminSso\Model\Oidc\AuthorizationStarter;
use DmLab\SsoCore\Api\AuthorizationParamsProviderInterface;
use DmLab\SsoCore\Api\AuthorizationStateStorageInterface;
use DmLab\SsoCore\Api\DiscoveryIssuerPolicyInterface;
use DmLab\SsoCore\Api\ProviderPresetInterface;
use DmLab\SsoCore\Model\Oidc\AuthorizationRequest;
use DmLab\SsoCore\Model\Oidc\AuthorizationRequestFactory;
use DmLab\SsoCore\Model\Oidc\DiscoveryClient;
use DmLab\SsoCore\Model\Oidc\ProviderMetadata;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class AuthorizationStarterTest extends TestCase
{
    private const CALLBACK_URL = 'https://magento.loc/admin/adminsso/sso/callback';
    private const AUTH_URL = 'https://idp.example/authorize?client_id=cid&state=abc';

    /** @var Config&Stub */
    private $config;

    /** @var ActiveProviderResolver&Stub */
    private $resolver;

    /** @var DiscoveryClient&MockObject */
    private $discoveryClient;

    /** @var BackendUrlInterface&Stub */
    private $backendUrl;

    /** @var AuthorizationRequestFactory&MockObject */
    private $requestFactory;

    /** @var AuthorizationStateStorageInterface&MockObject */
    private $stateStorage;

    /** @var AuthorizationStarter */
    private AuthorizationStarter $starter;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->resolver = $this->createStub(ActiveProviderResolver::class);
        $this->discoveryClient = $this->createMock(DiscoveryClient::class);
        $this->backendUrl = $this->createStub(BackendUrlInterface::class);
        $this->requestFactory = $this->createMock(AuthorizationRequestFactory::class);
        $this->stateStorage = $this->createMock(AuthorizationStateStorageInterface::class);

        $this->starter = new AuthorizationStarter(
            $this->config,
            $this->resolver,
            $this->discoveryClient,
            $this->requestFactory,
            $this->stateStorage,
            $this->backendUrl
        );
    }

    private function preset(): ProviderPresetInterface
    {
        $preset = $this->createStub(ProviderPresetInterface::class);
        $preset->method('buildDiscoveryUrl')->willReturn('https://idp.example/.well-known/openid-configuration');
        $preset->method('getDefaultScopes')->willReturn(['openid', 'email', 'groups']);

        return $preset;
    }

    private function metadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            'https://idp.example',
            'https://idp.example/authorize',
            'https://idp.example/token',
            'https://idp.example/jwks'
        );
    }

    private function givenActive(ProviderPresetInterface $preset): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getClientId')->willReturn('cid');
        $this->resolver->method('getActive')->willReturn($preset);
        $this->backendUrl->method('getUrl')->willReturn(self::CALLBACK_URL);
    }

    public function testStartBuildsAuthUrlAndPersistsState(): void
    {
        $this->givenActive($this->preset());
        $this->discoveryClient->expects(self::once())
            ->method('discover')
            ->with('https://idp.example/.well-known/openid-configuration', true)
            ->willReturn($this->metadata());

        $request = new AuthorizationRequest(self::AUTH_URL, 'abc', 'nnn', 'vvv');
        $this->requestFactory->expects(self::once())
            ->method('create')
            ->with(
                'https://idp.example/authorize',
                'cid',
                self::CALLBACK_URL,
                ['openid', 'email', 'groups'],
                [],
                'https://idp.example'
            )
            ->willReturn($request);

        $this->stateStorage->expects(self::once())
            ->method('save')
            ->with($request);

        self::assertSame(self::AUTH_URL, $this->starter->start());
    }

    public function testStartPassesPresetAuthorizationParams(): void
    {
        $preset = $this->createMockForIntersectionOfInterfaces(
            [ProviderPresetInterface::class, AuthorizationParamsProviderInterface::class]
        );
        $preset->method('buildDiscoveryUrl')->willReturn('https://idp.example/.well-known/openid-configuration');
        $preset->method('getDefaultScopes')->willReturn(['openid']);
        $preset->expects(self::once())
            ->method('getAuthorizationParams')
            ->with(['client_id' => 'cid'])
            ->willReturn(['hd' => 'example.com']);

        $this->givenActive($preset);
        $this->discoveryClient->expects(self::once())->method('discover')->willReturn($this->metadata());

        $request = new AuthorizationRequest(self::AUTH_URL, 'abc', 'nnn', 'vvv');
        $this->requestFactory->expects(self::once())
            ->method('create')
            ->with(
                'https://idp.example/authorize',
                'cid',
                self::CALLBACK_URL,
                ['openid'],
                ['hd' => 'example.com'],
                'https://idp.example'
            )
            ->willReturn($request);
        $this->stateStorage->expects(self::once())->method('save')->with($request);

        self::assertSame(self::AUTH_URL, $this->starter->start());
    }

    public function testStartPropagatesReservedParamRejection(): void
    {
        $preset = $this->createStubForIntersectionOfInterfaces(
            [ProviderPresetInterface::class, AuthorizationParamsProviderInterface::class]
        );
        $preset->method('buildDiscoveryUrl')->willReturn('https://idp.example/.well-known/openid-configuration');
        $preset->method('getDefaultScopes')->willReturn(['openid']);
        $preset->method('getAuthorizationParams')->willReturn(['state' => 'forged']);

        $this->givenActive($preset);
        $this->discoveryClient->expects(self::once())->method('discover')->willReturn($this->metadata());
        $this->requestFactory->expects(self::once())
            ->method('create')
            ->willThrowException(new \InvalidArgumentException('reserved'));
        $this->stateStorage->expects(self::never())->method('save');

        $this->expectException(\InvalidArgumentException::class);

        $this->starter->start();
    }

    public function testStartHonoursIssuerPolicyOptOut(): void
    {
        $preset = $this->createMockForIntersectionOfInterfaces(
            [ProviderPresetInterface::class, DiscoveryIssuerPolicyInterface::class]
        );
        $preset->method('buildDiscoveryUrl')->willReturn('https://idp.example/.well-known/openid-configuration');
        $preset->method('getDefaultScopes')->willReturn(['openid']);
        $preset->expects(self::once())
            ->method('requiresIssuerMatch')
            ->with(['client_id' => 'cid'])
            ->willReturn(false);

        $this->givenActive($preset);
        $this->discoveryClient->expects(self::once())
            ->method('discover')
            ->with('https://idp.example/.well-known/openid-configuration', false)
            ->willReturn($this->metadata());
        $this->requestFactory->expects(self::once())
            ->method('create')
            ->willReturn(new AuthorizationRequest(self::AUTH_URL, 'abc', 'nnn', 'vvv'));
        $this->stateStorage->expects(self::once())->method('save');

        self::assertSame(self::AUTH_URL, $this->starter->start());
    }

    public function testStartThrowsWhenDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->discoveryClient->expects(self::never())->method('discover');
        $this->requestFactory->expects(self::never())->method('create');
        $this->stateStorage->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Admin SSO is disabled.');

        $this->starter->start();
    }

    public function testStartThrowsWhenNoProviderActive(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->resolver->method('getActive')->willReturn(null);
        $this->discoveryClient->expects(self::never())->method('discover');
        $this->requestFactory->expects(self::never())->method('create');
        $this->stateStorage->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No admin SSO provider is configured.');

        $this->starter->start();
    }

    public function testStartThrowsWhenClientIdMissing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getClientId')->willReturn(null);
        $this->resolver->method('getActive')->willReturn($this->preset());
        $this->discoveryClient->expects(self::never())->method('discover');
        $this->requestFactory->expects(self::never())->method('create');
        $this->stateStorage->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The admin SSO client ID is not configured.');

        $this->starter->start();
    }
}

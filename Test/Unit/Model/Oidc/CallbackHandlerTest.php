<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Model\Oidc;

use DmLab\AdminSso\Model\ActiveProviderResolver;
use DmLab\AdminSso\Model\AuthorizationState;
use DmLab\AdminSso\Model\Config;
use DmLab\AdminSso\Model\Oidc\CallbackHandler;
use DmLab\SsoCore\Api\AuthorizationStateStorageInterface;
use DmLab\SsoCore\Api\DiscoveryIssuerPolicyInterface;
use DmLab\SsoCore\Api\ProviderPresetInterface;
use DmLab\SsoCore\Exception\AuthorizationResponseException;
use DmLab\SsoCore\Model\Data\Identity;
use DmLab\SsoCore\Model\Oidc\AuthorizationResponseValidator;
use DmLab\SsoCore\Model\Oidc\DiscoveryClient;
use DmLab\SsoCore\Model\Oidc\IdentityFactory;
use DmLab\SsoCore\Model\Oidc\IdTokenValidator;
use DmLab\SsoCore\Model\Oidc\ProviderMetadata;
use DmLab\SsoCore\Model\Oidc\TokenClient;
use DmLab\SsoCore\Model\Oidc\TokenResponse;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CallbackHandlerTest extends TestCase
{
    private const CALLBACK_URL = 'https://magento.loc/admin/adminsso/sso/callback';
    private const CODE = 'auth-code-abc';
    private const STATE = 'state-token-xyz';
    private const NONCE = 'nonce-123';
    private const VERIFIER = 'pkce-verifier-456';

    /** @var Config&Stub */
    private $config;

    /** @var ActiveProviderResolver&Stub */
    private $resolver;

    /** @var DiscoveryClient&MockObject */
    private $discoveryClient;

    /** @var TokenClient&MockObject */
    private $tokenClient;

    /** @var IdTokenValidator&MockObject */
    private $idTokenValidator;

    /** @var IdentityFactory&MockObject */
    private $identityFactory;

    /** @var AuthorizationStateStorageInterface&MockObject */
    private $stateStorage;

    /** @var BackendUrlInterface&Stub */
    private $backendUrl;

    /** @var AuthorizationResponseValidator&MockObject */
    private $responseValidator;

    /** @var CallbackHandler */
    private CallbackHandler $handler;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->resolver = $this->createStub(ActiveProviderResolver::class);
        $this->discoveryClient = $this->createMock(DiscoveryClient::class);
        $this->tokenClient = $this->createMock(TokenClient::class);
        $this->idTokenValidator = $this->createMock(IdTokenValidator::class);
        $this->identityFactory = $this->createMock(IdentityFactory::class);
        $this->stateStorage = $this->createMock(AuthorizationStateStorageInterface::class);
        $this->backendUrl = $this->createStub(BackendUrlInterface::class);
        $this->responseValidator = $this->createMock(AuthorizationResponseValidator::class);

        $this->handler = new CallbackHandler(
            $this->config,
            $this->resolver,
            $this->discoveryClient,
            $this->tokenClient,
            $this->idTokenValidator,
            $this->identityFactory,
            $this->stateStorage,
            $this->backendUrl,
            $this->responseValidator
        );
    }

    private function preset(): ProviderPresetInterface
    {
        $preset = $this->createStub(ProviderPresetInterface::class);
        $preset->method('buildDiscoveryUrl')->willReturn('https://idp.example/.well-known/openid-configuration');

        return $preset;
    }

    private function metadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            'https://idp.example',
            'https://idp.example/authorize',
            'https://idp.example/token',
            'https://idp.example/jwks',
            ['client_secret_basic', 'client_secret_post']
        );
    }

    /**
     * Assert the OIDC exchange never begins — used by the guard-clause tests where
     * the handler must bail out before touching the IdP.
     */
    private function assertExchangeNotStarted(): void
    {
        $this->discoveryClient->expects(self::never())->method('discover');
        $this->tokenClient->expects(self::never())->method('exchangeCode');
        $this->idTokenValidator->expects(self::never())->method('validateForProvider');
        $this->identityFactory->expects(self::never())->method('create');
        $this->responseValidator->expects(self::never())->method('validateIssuer');
    }

    public function testHandleReturnsNormalizedIdentity(): void
    {
        $preset = $this->preset();
        $preset->method('getCode')->willReturn('okta');
        $metadata = $this->metadata();
        $authState = new AuthorizationState(self::STATE, self::NONCE, self::VERIFIER, 'https://idp.example');
        $claims = ['sub' => 'user-1', 'iss' => 'https://idp.example', 'nonce' => self::NONCE];
        $identity = new Identity('user-1', 'user@example.com', 'User One', ['admins']);

        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getClientId')->willReturn('cid');
        $this->config->method('getClientSecret')->willReturn('secret');
        $this->resolver->method('getActive')->willReturn($preset);
        $this->backendUrl->method('getUrl')->willReturn(self::CALLBACK_URL);

        $this->stateStorage->expects(self::once())
            ->method('consume')
            ->with(self::STATE)
            ->willReturn($authState);

        $this->discoveryClient->expects(self::once())
            ->method('discover')
            ->with('https://idp.example/.well-known/openid-configuration', true)
            ->willReturn($metadata);

        $this->responseValidator->expects(self::once())
            ->method('validateIssuer')
            ->with('https://idp.example', $authState, $metadata);

        $this->tokenClient->expects(self::once())
            ->method('exchangeCode')
            ->with(
                'https://idp.example/token',
                self::CODE,
                self::CALLBACK_URL,
                'cid',
                self::VERIFIER,
                'secret',
                ['client_secret_basic', 'client_secret_post']
            )
            ->willReturn(new TokenResponse('the-id-token'));

        $this->idTokenValidator->expects(self::once())
            ->method('validateForProvider')
            ->with('the-id-token', $metadata, 'cid', self::NONCE)
            ->willReturn($claims);

        $this->identityFactory->expects(self::once())
            ->method('create')
            ->with($claims, $preset)
            ->willReturn($identity);

        $result = $this->handler->handle(self::CODE, self::STATE, 'https://idp.example');
        self::assertSame($identity, $result->getIdentity());
        self::assertSame($claims, $result->getClaims());
        self::assertSame('the-id-token', $result->getIdToken());
        self::assertSame('okta', $result->getProviderCode());
    }

    public function testIssuerMismatchStopsBeforeCodeExchange(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getClientId')->willReturn('cid');
        $this->resolver->method('getActive')->willReturn($this->preset());
        $this->stateStorage->expects(self::once())->method('consume')
            ->willReturn(new AuthorizationState(self::STATE, self::NONCE, self::VERIFIER, 'https://idp.example'));
        $this->discoveryClient->expects(self::once())->method('discover')->willReturn($this->metadata());

        $this->responseValidator->expects(self::once())
            ->method('validateIssuer')
            ->with('https://evil.example')
            ->willThrowException(new AuthorizationResponseException('iss mismatch'));
        $this->tokenClient->expects(self::never())->method('exchangeCode');
        $this->idTokenValidator->expects(self::never())->method('validateForProvider');
        $this->identityFactory->expects(self::never())->method('create');

        $this->expectException(AuthorizationResponseException::class);

        $this->handler->handle(self::CODE, self::STATE, 'https://evil.example');
    }

    public function testHonoursIssuerPolicyOptOut(): void
    {
        $preset = $this->createMockForIntersectionOfInterfaces(
            [ProviderPresetInterface::class, DiscoveryIssuerPolicyInterface::class]
        );
        $preset->method('buildDiscoveryUrl')->willReturn('https://idp.example/.well-known/openid-configuration');
        $preset->expects(self::once())
            ->method('requiresIssuerMatch')
            ->with(['client_id' => 'cid'])
            ->willReturn(false);
        $identity = new Identity('user-1', 'user@example.com', 'User One', ['admins']);

        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getClientId')->willReturn('cid');
        $this->resolver->method('getActive')->willReturn($preset);
        $this->backendUrl->method('getUrl')->willReturn(self::CALLBACK_URL);
        $this->stateStorage->expects(self::once())
            ->method('consume')
            ->willReturn(new AuthorizationState(self::STATE, self::NONCE, self::VERIFIER));

        $this->discoveryClient->expects(self::once())
            ->method('discover')
            ->with('https://idp.example/.well-known/openid-configuration', false)
            ->willReturn($this->metadata());
        $this->responseValidator->expects(self::once())->method('validateIssuer')->with(null);
        $this->tokenClient->expects(self::once())
            ->method('exchangeCode')
            ->willReturn(new TokenResponse('the-id-token'));
        $this->idTokenValidator->expects(self::once())
            ->method('validateForProvider')
            ->willReturn(['sub' => 'user-1', 'iss' => 'https://idp.example']);
        $this->identityFactory->expects(self::once())->method('create')->willReturn($identity);

        self::assertSame($identity, $this->handler->handle(self::CODE, self::STATE)->getIdentity());
    }

    public function testThrowsWhenDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->stateStorage->expects(self::never())->method('consume');
        $this->assertExchangeNotStarted();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Admin SSO is disabled.');

        $this->handler->handle(self::CODE, self::STATE);
    }

    public function testThrowsWhenNoProviderActive(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->resolver->method('getActive')->willReturn(null);
        $this->stateStorage->expects(self::never())->method('consume');
        $this->assertExchangeNotStarted();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No admin SSO provider is configured.');

        $this->handler->handle(self::CODE, self::STATE);
    }

    public function testThrowsWhenClientIdMissing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getClientId')->willReturn(null);
        $this->resolver->method('getActive')->willReturn($this->preset());
        $this->stateStorage->expects(self::never())->method('consume');
        $this->assertExchangeNotStarted();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The admin SSO client ID is not configured.');

        $this->handler->handle(self::CODE, self::STATE);
    }

    public function testThrowsWhenStateUnknownOrReplayed(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getClientId')->willReturn('cid');
        $this->resolver->method('getActive')->willReturn($this->preset());

        $this->stateStorage->expects(self::once())
            ->method('consume')
            ->with(self::STATE)
            ->willReturn(null);

        // No exchange once the state is rejected (expired/forged/replayed).
        $this->assertExchangeNotStarted();

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The SSO sign-in session is invalid or has expired. Please try again.');

        $this->handler->handle(self::CODE, self::STATE);
    }
}

<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Model\Oidc;

use MageDevGroup\AdminSso\Model\ActiveProviderResolver;
use MageDevGroup\AdminSso\Model\Config;
use MageDevGroup\SsoCore\Api\AuthorizationStateStorageInterface;
use MageDevGroup\SsoCore\Model\Oidc\AuthorizationRequestFactory;
use MageDevGroup\SsoCore\Model\Oidc\DiscoveryClient;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Orchestrates the start of the admin OIDC login: resolves the active provider
 * preset + config, discovers the IdP's authorization endpoint (via sso-core),
 * builds an auth-code + PKCE authorization request, persists its one-time state
 * for the callback, and returns the IdP URL to redirect the browser to.
 *
 * Provider-neutral: the concrete IdP arrives only through the active preset, so
 * this stays free of any provider-specific code (open/closed).
 */
class AuthorizationStarter
{
    /** Admin route the IdP calls back to (frontName/controller/action). */
    private const CALLBACK_ROUTE = 'adminsso/sso/callback';

    /**
     * @param Config $config
     * @param ActiveProviderResolver $activeProviderResolver
     * @param DiscoveryClient $discoveryClient
     * @param AuthorizationRequestFactory $authorizationRequestFactory
     * @param AuthorizationStateStorageInterface $stateStorage
     * @param BackendUrlInterface $backendUrl
     */
    public function __construct(
        private readonly Config $config,
        private readonly ActiveProviderResolver $activeProviderResolver,
        private readonly DiscoveryClient $discoveryClient,
        private readonly AuthorizationRequestFactory $authorizationRequestFactory,
        private readonly AuthorizationStateStorageInterface $stateStorage,
        private readonly BackendUrlInterface $backendUrl
    ) {
    }

    /**
     * Build the IdP authorization URL and persist its one-time state.
     *
     * @throws LocalizedException when SSO is disabled, no provider is active, or
     *         the client id is not configured.
     */
    public function start(): string
    {
        if (!$this->config->isEnabled()) {
            throw new LocalizedException(__('Admin SSO is disabled.'));
        }

        $preset = $this->activeProviderResolver->getActive();
        if ($preset === null) {
            throw new LocalizedException(__('No admin SSO provider is configured.'));
        }

        $clientId = $this->config->getClientId();
        if ($clientId === null) {
            throw new LocalizedException(__('The admin SSO client ID is not configured.'));
        }

        $metadata = $this->discoveryClient->discover(
            $preset->buildDiscoveryUrl($this->presetConfig())
        );

        $request = $this->authorizationRequestFactory->create(
            $metadata->getAuthorizationEndpoint(),
            $clientId,
            $this->callbackUrl(),
            $preset->getDefaultScopes()
        );

        $this->stateStorage->save($request);

        return $request->getUrl();
    }

    /**
     * Config array handed to the preset for building its discovery URL. The core
     * stays provider-neutral, so it forwards only the generic fields it owns; a
     * preset needing IdP-specific values reads them from its own config.
     *
     * @return array<string,mixed>
     */
    private function presetConfig(): array
    {
        return [
            'client_id' => $this->config->getClientId(),
        ];
    }

    /**
     * Absolute admin callback URL registered with the IdP as the redirect URI.
     * Built without the backend secret key so it stays stable pre-login and
     * matches the value registered at the IdP.
     */
    private function callbackUrl(): string
    {
        return $this->backendUrl->getUrl(self::CALLBACK_ROUTE, ['_nosecret' => true]);
    }
}

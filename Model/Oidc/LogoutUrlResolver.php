<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Model\Oidc;

use DmLab\AdminSso\Model\ActiveProviderResolver;
use DmLab\AdminSso\Model\Config;
use DmLab\SsoCore\Api\DiscoveryIssuerPolicyInterface;
use DmLab\SsoCore\Api\ProviderPresetInterface;
use DmLab\SsoCore\Model\Oidc\DiscoveryClient;
use DmLab\SsoCore\Model\Oidc\LogoutUrlBuilder;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the IdP end-session URL (OIDC RP-Initiated Logout) for an SSO admin logout.
 *
 * Returns null whenever IdP logout does not apply or fails, so the caller falls back to a local logout.
 */
class LogoutUrlResolver
{
    /** Post-logout landing page; must be registered at the IdP. */
    private const POST_LOGOUT_ROUTE = 'adminhtml/auth/login';

    /**
     * @param Config $config
     * @param ActiveProviderResolver $activeProviderResolver
     * @param DiscoveryClient $discoveryClient
     * @param LogoutUrlBuilder $logoutUrlBuilder
     * @param BackendUrlInterface $backendUrl
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly ActiveProviderResolver $activeProviderResolver,
        private readonly DiscoveryClient $discoveryClient,
        private readonly LogoutUrlBuilder $logoutUrlBuilder,
        private readonly BackendUrlInterface $backendUrl,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * End-session URL for the given SSO login, or null to log out locally only.
     *
     * @param string $idToken raw ID token of the SSO login (`id_token_hint`)
     * @param string $providerCode provider that issued it; must still be the active one
     */
    public function resolve(string $idToken, string $providerCode): ?string
    {
        if (!$this->config->isEnabled() || !$this->config->isIdpLogoutEnabled() || $idToken === '') {
            return null;
        }

        $preset = $this->activeProviderResolver->getActive();
        $clientId = $this->config->getClientId();
        if ($preset === null || $preset->getCode() !== $providerCode || $clientId === null) {
            return null;
        }

        try {
            $endpoint = $this->discoveryClient->discover(
                $preset->buildDiscoveryUrl($this->presetConfig()),
                $this->requiresIssuerMatch($preset)
            )->getEndSessionEndpoint();
            if ($endpoint === null) {
                return null;
            }

            return $this->logoutUrlBuilder->build(
                $endpoint,
                $idToken,
                $clientId,
                $this->backendUrl->getUrl(self::POST_LOGOUT_ROUTE, ['_nosecret' => true])
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Admin SSO: IdP logout skipped: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Mirrors {@see AuthorizationStarter::presetConfig()} so logout resolves the same IdP.
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
     * Mirrors {@see AuthorizationStarter::requiresIssuerMatch()}.
     *
     * @param ProviderPresetInterface $preset
     */
    private function requiresIssuerMatch(ProviderPresetInterface $preset): bool
    {
        return !$preset instanceof DiscoveryIssuerPolicyInterface
            || $preset->requiresIssuerMatch($this->presetConfig());
    }
}

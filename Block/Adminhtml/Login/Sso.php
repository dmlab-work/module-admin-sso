<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Block\Adminhtml\Login;

use MageDevGroup\AdminSso\Model\ActiveProviderResolver;
use MageDevGroup\AdminSso\Model\Config;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * "Sign in with SSO" button injected into the admin login page.
 *
 * Provider-neutral: the branding (label, icon) comes from the active preset a
 * plugin registered, resolved via {@see ActiveProviderResolver}. The button is
 * shown only when the module is enabled and a provider is selected; otherwise it
 * renders nothing, so a fresh install or a removed plugin leaves the native login
 * form untouched.
 *
 * The start URL is built through the backend URL model so it points at the admin
 * area regardless of the block base class.
 */
class Sso extends Template
{
    /** Admin route to the start-auth controller (frontName/controller/action). */
    private const START_AUTH_ROUTE = 'adminsso/sso/start';

    /**
     * @param Context $context
     * @param Config $config
     * @param ActiveProviderResolver $activeProviderResolver
     * @param BackendUrlInterface $backendUrl
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ActiveProviderResolver $activeProviderResolver,
        private readonly BackendUrlInterface $backendUrl,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Whether the SSO button should be shown: enabled and a provider selected.
     */
    public function isAvailable(): bool
    {
        return $this->config->isEnabled() && $this->activeProviderResolver->getActive() !== null;
    }

    /**
     * Active preset's login-button label, or empty when no provider is active.
     */
    public function getButtonLabel(): string
    {
        $preset = $this->activeProviderResolver->getActive();

        return $preset === null ? '' : $preset->getButtonLabel();
    }

    /**
     * Active preset's login-button icon URL, or null when none is active/shipped.
     */
    public function getButtonIconUrl(): ?string
    {
        $preset = $this->activeProviderResolver->getActive();

        return $preset === null ? null : $preset->getButtonIconUrl();
    }

    /**
     * URL of the start-auth controller the button links to.
     */
    public function getStartUrl(): string
    {
        return $this->backendUrl->getUrl(self::START_AUTH_ROUTE);
    }

    /**
     * Render nothing unless the button is available (module off / no provider).
     */
    protected function _toHtml(): string
    {
        if (!$this->isAvailable()) {
            return '';
        }

        return parent::_toHtml();
    }
}

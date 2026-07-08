<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Test\Unit\Block\Adminhtml\Login;

use MageDevGroup\AdminSso\Block\Adminhtml\Login\Sso;
use MageDevGroup\AdminSso\Model\ActiveProviderResolver;
use MageDevGroup\AdminSso\Model\Config;
use MageDevGroup\SsoCore\Api\ProviderPresetInterface;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\TestCase;

class SsoTest extends TestCase
{
    private function preset(string $buttonLabel, ?string $iconUrl): ProviderPresetInterface
    {
        $preset = $this->createStub(ProviderPresetInterface::class);
        $preset->method('getButtonLabel')->willReturn($buttonLabel);
        $preset->method('getButtonIconUrl')->willReturn($iconUrl);

        return $preset;
    }

    private function block(
        bool $enabled,
        ?ProviderPresetInterface $active,
        ?BackendUrlInterface $backendUrl = null
    ): Sso {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        $resolver = $this->createStub(ActiveProviderResolver::class);
        $resolver->method('getActive')->willReturn($active);

        return (new ObjectManager($this))->getObject(Sso::class, [
            'context' => $this->createStub(Context::class),
            'config' => $config,
            'activeProviderResolver' => $resolver,
            'backendUrl' => $backendUrl ?? $this->createStub(BackendUrlInterface::class),
        ]);
    }

    public function testAvailableWhenEnabledAndProviderActive(): void
    {
        $block = $this->block(true, $this->preset('Sign in with Okta', 'https://cdn/okta.svg'));

        self::assertTrue($block->isAvailable());
        self::assertSame('Sign in with Okta', $block->getButtonLabel());
        self::assertSame('https://cdn/okta.svg', $block->getButtonIconUrl());
    }

    public function testHiddenWhenModuleDisabled(): void
    {
        $block = $this->block(false, $this->preset('Sign in with Okta', null));

        self::assertFalse($block->isAvailable());
    }

    public function testHiddenWhenNoProviderActive(): void
    {
        $block = $this->block(true, null);

        self::assertFalse($block->isAvailable());
        self::assertSame('', $block->getButtonLabel());
        self::assertNull($block->getButtonIconUrl());
    }

    public function testStartUrlBuiltFromRoute(): void
    {
        $backendUrl = $this->createMock(BackendUrlInterface::class);
        $backendUrl->expects(self::once())
            ->method('getUrl')
            ->with('adminsso/sso/start')
            ->willReturn('https://magento.loc/admin/adminsso/sso/start');

        $block = $this->block(true, $this->preset('Sign in with Okta', null), $backendUrl);

        self::assertSame('https://magento.loc/admin/adminsso/sso/start', $block->getStartUrl());
    }

    public function testRendersNothingWhenNotAvailable(): void
    {
        $block = $this->block(false, null);

        $toHtml = new \ReflectionMethod($block, '_toHtml');

        self::assertSame('', $toHtml->invoke($block));
    }
}

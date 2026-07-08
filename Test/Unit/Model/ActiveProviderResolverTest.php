<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Test\Unit\Model;

use MageDevGroup\AdminSso\Model\ActiveProviderResolver;
use MageDevGroup\AdminSso\Model\PresetRegistry;
use MageDevGroup\SsoCore\Api\ProviderPresetInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ActiveProviderResolverTest extends TestCase
{
    private function preset(string $code): ProviderPresetInterface
    {
        $preset = $this->createStub(ProviderPresetInterface::class);
        $preset->method('getCode')->willReturn($code);

        return $preset;
    }

    private function resolver(?string $configValue, array $presets): ActiveProviderResolver
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(ActiveProviderResolver::XML_PATH_ACTIVE_PROVIDER)
            ->willReturn($configValue);

        return new ActiveProviderResolver($scopeConfig, new PresetRegistry($presets));
    }

    public function testGetActiveReturnsSelectedPreset(): void
    {
        $okta = $this->preset('okta');
        $resolver = $this->resolver('okta', [$okta, $this->preset('azure')]);

        self::assertSame('okta', $resolver->getActiveCode());
        self::assertSame($okta, $resolver->getActive());
    }

    public function testGetActiveReturnsNullWhenNothingSelected(): void
    {
        $resolver = $this->resolver(null, [$this->preset('okta')]);

        self::assertNull($resolver->getActiveCode());
        self::assertNull($resolver->getActive());
    }

    public function testGetActiveCodeTrimsAndTreatsBlankAsUnset(): void
    {
        $resolver = $this->resolver('   ', [$this->preset('okta')]);

        self::assertNull($resolver->getActiveCode());
        self::assertNull($resolver->getActive());
    }

    public function testGetActiveReturnsNullWhenSelectedCodeNotRegistered(): void
    {
        $resolver = $this->resolver('azure', [$this->preset('okta')]);

        self::assertSame('azure', $resolver->getActiveCode());
        self::assertNull($resolver->getActive());
    }
}

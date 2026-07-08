<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Test\Unit\Model;

use MageDevGroup\AdminSso\Model\PresetRegistry;
use MageDevGroup\SsoCore\Api\ProviderPresetInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

class PresetRegistryTest extends TestCase
{
    private function preset(string $code): ProviderPresetInterface
    {
        $preset = $this->createStub(ProviderPresetInterface::class);
        $preset->method('getCode')->willReturn($code);

        return $preset;
    }

    public function testGetAllIndexesRegisteredPresetsByCode(): void
    {
        $okta = $this->preset('okta');
        $azure = $this->preset('azure');

        $registry = new PresetRegistry([$okta, $azure]);

        self::assertSame(
            ['okta' => $okta, 'azure' => $azure],
            $registry->getAll()
        );
    }

    public function testGetAllIsEmptyWhenNoPresetsRegistered(): void
    {
        self::assertSame([], (new PresetRegistry())->getAll());
    }

    public function testGetReturnsRegisteredPreset(): void
    {
        $okta = $this->preset('okta');
        $registry = new PresetRegistry([$okta]);

        self::assertTrue($registry->has('okta'));
        self::assertSame($okta, $registry->get('okta'));
    }

    public function testGetThrowsForUnregisteredCode(): void
    {
        $registry = new PresetRegistry([$this->preset('okta')]);

        self::assertFalse($registry->has('azure'));
        $this->expectException(NoSuchEntityException::class);
        $registry->get('azure');
    }

    public function testConstructorRejectsNonPreset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentional wrong type for the guard */
        new PresetRegistry([new \stdClass()]);
    }
}

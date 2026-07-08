<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Test\Unit\Model\TwoFactorAuth;

use MageDevGroup\AdminSso\Model\TwoFactorAuth\SessionGranter;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\TwoFactorAuth\Api\TfaSessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SessionGranterTest extends TestCase
{
    private const MODULE = 'Magento_TwoFactorAuth';

    /** @var ModuleManager&MockObject */
    private $moduleManager;

    /** @var ObjectManagerInterface&MockObject */
    private $objectManager;

    /** @var SessionGranter */
    private SessionGranter $granter;

    protected function setUp(): void
    {
        $this->moduleManager = $this->createMock(ModuleManager::class);
        $this->objectManager = $this->createMock(ObjectManagerInterface::class);

        $this->granter = new SessionGranter($this->moduleManager, $this->objectManager);
    }

    public function testGrantsTwoFactorWhenModuleEnabled(): void
    {
        $tfaSession = $this->createMock(TfaSessionInterface::class);

        $this->moduleManager->method('isEnabled')->with(self::MODULE)->willReturn(true);
        $this->objectManager->expects(self::once())
            ->method('get')
            ->with(TfaSessionInterface::class)
            ->willReturn($tfaSession);
        $tfaSession->expects(self::once())->method('grantAccess');

        $this->granter->grant();
    }

    public function testNoOpWhenModuleDisabled(): void
    {
        $this->moduleManager->method('isEnabled')->with(self::MODULE)->willReturn(false);
        // 2FA not installed/enabled: never touch the object manager, never grant.
        $this->objectManager->expects(self::never())->method('get');

        $this->granter->grant();
    }
}

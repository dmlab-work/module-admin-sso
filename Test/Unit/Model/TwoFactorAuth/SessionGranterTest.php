<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Model\TwoFactorAuth;

use DmLab\AdminSso\Model\TwoFactorAuth\IdpMfaPolicy;
use DmLab\AdminSso\Model\TwoFactorAuth\SessionGranter;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\TwoFactorAuth\Api\TfaSessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SessionGranterTest extends TestCase
{
    private const MODULE = 'Magento_TwoFactorAuth';
    private const CLAIMS = ['amr' => ['mfa']];

    /** @var ModuleManager&Stub */
    private $moduleManager;

    /** @var ObjectManagerInterface&MockObject */
    private $objectManager;

    /** @var IdpMfaPolicy&MockObject */
    private $mfaPolicy;

    /** @var SessionGranter */
    private SessionGranter $granter;

    protected function setUp(): void
    {
        $this->moduleManager = $this->createStub(ModuleManager::class);
        $this->objectManager = $this->createMock(ObjectManagerInterface::class);
        $this->mfaPolicy = $this->createMock(IdpMfaPolicy::class);

        $this->granter = new SessionGranter($this->moduleManager, $this->objectManager, $this->mfaPolicy);
    }

    public function testGrantsTwoFactorWhenModuleEnabledAndIdpProvedMfa(): void
    {
        $tfaSession = $this->createMock(TfaSessionInterface::class);

        $this->moduleManager->method('isEnabled')->willReturnMap([[self::MODULE, true]]);
        $this->mfaPolicy->expects(self::once())->method('isSatisfied')->with(self::CLAIMS)->willReturn(true);
        $this->objectManager->expects(self::once())
            ->method('get')
            ->with(TfaSessionInterface::class)
            ->willReturn($tfaSession);
        $tfaSession->expects(self::once())->method('grantAccess');

        $this->granter->grant(self::CLAIMS);
    }

    public function testKeepsMagentoTwoFactorWithoutIdpMfaEvidence(): void
    {
        $this->moduleManager->method('isEnabled')->willReturnMap([[self::MODULE, true]]);
        $this->mfaPolicy->expects(self::once())->method('isSatisfied')->with(['amr' => ['pwd']])->willReturn(false);
        $this->objectManager->expects(self::never())->method('get');

        $this->granter->grant(['amr' => ['pwd']]);
    }

    public function testNoOpWhenModuleDisabled(): void
    {
        $this->moduleManager->method('isEnabled')->willReturnMap([[self::MODULE, false]]);
        // 2FA not installed/enabled: never touch the object manager, never grant.
        $this->mfaPolicy->expects(self::never())->method('isSatisfied');
        $this->objectManager->expects(self::never())->method('get');

        $this->granter->grant(self::CLAIMS);
    }
}

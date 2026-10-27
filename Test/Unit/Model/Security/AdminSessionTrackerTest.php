<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Model\Security;

use DmLab\AdminSso\Model\Security\AdminSessionTracker;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Security\Model\AdminSessionInfo;
use Magento\Security\Model\AdminSessionsManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminSessionTrackerTest extends TestCase
{
    /** @var ModuleManager&\PHPUnit\Framework\MockObject\Stub */
    private $moduleManager;

    /** @var ObjectManagerInterface&MockObject */
    private $objectManager;

    /** @var MessageManager&MockObject */
    private $messageManager;

    /** @var AdminSessionTracker */
    private AdminSessionTracker $tracker;

    protected function setUp(): void
    {
        $this->moduleManager = $this->createStub(ModuleManager::class);
        $this->objectManager = $this->createMock(ObjectManagerInterface::class);
        $this->messageManager = $this->createMock(MessageManager::class);

        $this->tracker = new AdminSessionTracker($this->moduleManager, $this->objectManager, $this->messageManager);
    }

    private function sessionsManager(bool $othersTerminated): AdminSessionsManager
    {
        $session = $this->createStub(AdminSessionInfo::class);
        $session->method('isOtherSessionsTerminated')->willReturn($othersTerminated);

        $manager = $this->createMock(AdminSessionsManager::class);
        $manager->expects(self::once())->method('processLogin')->willReturnSelf();
        $manager->method('getCurrentSession')->willReturn($session);

        return $manager;
    }

    public function testProcessesLoginThroughSessionsManager(): void
    {
        $this->moduleManager->method('isEnabled')->willReturn(true);
        $this->objectManager->expects(self::once())
            ->method('get')
            ->with(AdminSessionsManager::class)
            ->willReturn($this->sessionsManager(false));
        $this->messageManager->expects(self::never())->method('addWarningMessage');

        $this->tracker->processLogin();
    }

    public function testWarnsWhenOtherSessionsWereTerminated(): void
    {
        $this->moduleManager->method('isEnabled')->willReturn(true);
        $this->objectManager->expects(self::once())->method('get')->willReturn($this->sessionsManager(true));
        $this->messageManager->expects(self::once())
            ->method('addWarningMessage')
            ->with('All other open sessions for this account were terminated.');

        $this->tracker->processLogin();
    }

    public function testNoOpWhenSecurityModuleDisabled(): void
    {
        $this->moduleManager->method('isEnabled')->willReturn(false);
        $this->objectManager->expects(self::never())->method('get');
        $this->messageManager->expects(self::never())->method('addWarningMessage');

        $this->tracker->processLogin();
    }
}

<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Test\Unit\Controller\Adminhtml\Sso;

use MageDevGroup\AdminSso\Controller\Adminhtml\Sso\Start;
use MageDevGroup\AdminSso\Model\Oidc\AuthorizationStarter;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StartTest extends TestCase
{
    /** @var Redirect&MockObject */
    private $redirect;

    /** @var ManagerInterface&MockObject */
    private $messageManager;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var AuthorizationStarter&Stub */
    private $starter;

    /** @var Start */
    private Start $controller;

    protected function setUp(): void
    {
        $this->redirect = $this->createMock(Redirect::class);

        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->starter = $this->createStub(AuthorizationStarter::class);

        $context = $this->createStub(Context::class);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($this->messageManager);

        $this->controller = new Start($context, $this->starter, $this->logger);
    }

    public function testRedirectsToIdpUrl(): void
    {
        $this->starter->method('start')->willReturn('https://idp.example/authorize?state=abc');

        $this->redirect->expects(self::once())
            ->method('setUrl')
            ->with('https://idp.example/authorize?state=abc')
            ->willReturnSelf();
        $this->redirect->expects(self::never())->method('setPath');
        $this->messageManager->expects(self::never())->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testRedirectsBackToLoginOnLocalizedError(): void
    {
        $this->starter->method('start')
            ->willThrowException(new LocalizedException(__('Admin SSO is disabled.')));

        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('Admin SSO is disabled.');
        $this->redirect->expects(self::never())->method('setUrl');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('adminhtml/auth/login')
            ->willReturnSelf();
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testRedirectsBackToLoginAndLogsOnUnexpectedError(): void
    {
        $error = new \RuntimeException('discovery down');
        $this->starter->method('start')->willThrowException($error);

        $this->logger->expects(self::once())->method('critical')->with($error);
        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->redirect->expects(self::never())->method('setUrl');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('adminhtml/auth/login')
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->controller->execute());
    }
}

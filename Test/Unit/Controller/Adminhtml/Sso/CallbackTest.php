<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Test\Unit\Controller\Adminhtml\Sso;

use MageDevGroup\AdminSso\Controller\Adminhtml\Sso\Callback;
use MageDevGroup\AdminSso\Model\AdminSessionCreator;
use MageDevGroup\AdminSso\Model\Oidc\CallbackHandler;
use MageDevGroup\AdminSso\Model\RoleAssigner;
use MageDevGroup\AdminSso\Model\UserProvisioner;
use MageDevGroup\SsoCore\Model\Data\Identity;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CallbackTest extends TestCase
{
    /** @var Redirect&MockObject */
    private $redirect;

    /** @var RequestInterface&Stub */
    private $request;

    /** @var ManagerInterface&MockObject */
    private $messageManager;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var CallbackHandler&MockObject */
    private $handler;

    /** @var UserProvisioner&MockObject */
    private $userProvisioner;

    /** @var RoleAssigner&MockObject */
    private $roleAssigner;

    /** @var AdminSessionCreator&MockObject */
    private $sessionCreator;

    /** @var BackendUrlInterface&Stub */
    private $backendUrl;

    /** @var Callback */
    private Callback $controller;

    protected function setUp(): void
    {
        $this->redirect = $this->createMock(Redirect::class);

        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $this->request = $this->createStub(RequestInterface::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = $this->createMock(CallbackHandler::class);
        $this->userProvisioner = $this->createMock(UserProvisioner::class);
        $this->roleAssigner = $this->createMock(RoleAssigner::class);
        $this->sessionCreator = $this->createMock(AdminSessionCreator::class);
        $this->backendUrl = $this->createStub(BackendUrlInterface::class);
        $this->backendUrl->method('getStartupPageUrl')->willReturn('adminhtml/dashboard');

        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnArgument(0);

        $context = $this->createStub(Context::class);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getRequest')->willReturn($this->request);

        $this->controller = new Callback(
            $context,
            $this->handler,
            $this->userProvisioner,
            $this->roleAssigner,
            $this->sessionCreator,
            $this->backendUrl,
            $escaper,
            $this->logger
        );
    }

    /**
     * @param array<string,string> $params
     */
    private function withParams(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn(string $name) => $params[$name] ?? null
        );
    }

    /**
     * A failure path ends on the admin login page without provisioning.
     */
    private function expectLoginRedirect(): void
    {
        $this->redirect->expects(self::once())->method('setPath')->with('adminhtml/auth/login');
        $this->userProvisioner->expects(self::never())->method('provision');
        $this->roleAssigner->expects(self::never())->method('assign');
        $this->sessionCreator->expects(self::never())->method('create');
    }

    public function testEstablishesSessionAndRedirectsToStartupOnSuccess(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);

        $identity = new Identity('user-1', 'user@example.com', 'User One', ['admins']);
        $user = $this->createStub(User::class);

        $this->handler->expects(self::once())
            ->method('handle')
            ->with('the-code', 'the-state')
            ->willReturn($identity);
        $this->userProvisioner->expects(self::once())
            ->method('provision')
            ->with($identity)
            ->willReturn($user);
        $this->roleAssigner->expects(self::once())->method('assign')->with($user, $identity);
        $this->sessionCreator->expects(self::once())->method('create')->with($user);

        $this->redirect->expects(self::once())->method('setPath')->with('adminhtml/dashboard');
        $this->messageManager->expects(self::never())->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testRedirectsToLoginOnIdpError(): void
    {
        $this->withParams(['error' => 'access_denied', 'error_description' => 'User denied access']);
        $this->expectLoginRedirect();

        $this->handler->expects(self::never())->method('handle');
        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The identity provider rejected the sign-in: User denied access');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testRedirectsToLoginOnIdpErrorWithoutDescription(): void
    {
        $this->withParams(['error' => 'server_error']);
        $this->expectLoginRedirect();

        $this->handler->expects(self::never())->method('handle');
        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The identity provider rejected the sign-in: server_error');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testRedirectsToLoginOnIncompleteResponse(): void
    {
        $this->withParams(['code' => '', 'state' => 'the-state']);
        $this->expectLoginRedirect();

        $this->handler->expects(self::never())->method('handle');
        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The SSO sign-in response was incomplete. Please try again.');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testShowsLocalizedErrorFromHandler(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);
        $this->expectLoginRedirect();

        $this->handler->expects(self::once())
            ->method('handle')
            ->willThrowException(new LocalizedException(
                __('The SSO sign-in session is invalid or has expired. Please try again.')
            ));

        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The SSO sign-in session is invalid or has expired. Please try again.');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testShowsErrorWhenProvisioningFails(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);

        $identity = new Identity('user-1', null, null, []);
        $this->handler->expects(self::once())->method('handle')->willReturn($identity);
        $this->userProvisioner->expects(self::once())
            ->method('provision')
            ->with($identity)
            ->willThrowException(new LocalizedException(
                __('The identity provider did not release an email address required to create an admin user.')
            ));
        $this->roleAssigner->expects(self::never())->method('assign');
        $this->sessionCreator->expects(self::never())->method('create');

        $this->redirect->expects(self::once())->method('setPath')->with('adminhtml/auth/login');
        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The identity provider did not release an email address required to create an admin user.');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testLogsAndShowsGenericOnUnexpectedError(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);
        $this->expectLoginRedirect();

        $error = new \RuntimeException('token endpoint down');
        $this->handler->expects(self::once())->method('handle')->willThrowException($error);

        $this->logger->expects(self::once())->method('critical')->with($error);
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('Could not complete SSO sign-in. Please try again or contact your administrator.');

        self::assertSame($this->redirect, $this->controller->execute());
    }
}

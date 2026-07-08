<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Test\Unit\Model;

use MageDevGroup\AdminSso\Model\AdminSessionCreator;
use MageDevGroup\AdminSso\Model\TwoFactorAuth\SessionGranter as TwoFactorSessionGranter;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminSessionCreatorTest extends TestCase
{
    /** @var AuthSession&MockObject */
    private $authSession;

    /** @var EventManager&MockObject */
    private $eventManager;

    /** @var UserResource&MockObject */
    private $userResource;

    /** @var TwoFactorSessionGranter&MockObject */
    private $twoFactorSessionGranter;

    /** @var AdminSessionCreator */
    private AdminSessionCreator $creator;

    protected function setUp(): void
    {
        // `setUser` is a magic setter routed through SessionManager::__call, so the
        // call is asserted on `__call`; `processLogin` is a real method.
        $this->authSession = $this->createMock(AuthSession::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->userResource = $this->createMock(UserResource::class);
        $this->twoFactorSessionGranter = $this->createMock(TwoFactorSessionGranter::class);

        $this->creator = new AdminSessionCreator(
            $this->authSession,
            $this->eventManager,
            $this->userResource,
            $this->twoFactorSessionGranter
        );
    }

    public function testCreateEstablishesAuthenticatedSession(): void
    {
        $user = $this->createStub(User::class);

        $this->authSession->expects(self::once())
            ->method('__call')
            ->with('setUser', [$user]);
        $this->authSession->expects(self::once())->method('processLogin');
        $this->userResource->expects(self::once())->method('recordLogin')->with($user);
        $this->eventManager->expects(self::once())
            ->method('dispatch')
            ->with('backend_auth_user_login_success', ['user' => $user]);
        // The SSO session satisfies core 2FA so the admin is not double-prompted.
        $this->twoFactorSessionGranter->expects(self::once())->method('grant');

        $this->creator->create($user);
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Model;

use DmLab\AdminSso\Model\AdminSessionCreator;
use DmLab\AdminSso\Model\Security\AdminSessionTracker;
use DmLab\AdminSso\Model\Session\IdpSessionStorage;
use DmLab\AdminSso\Model\TwoFactorAuth\SessionGranter as TwoFactorSessionGranter;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\User;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
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

    /** @var AdminSessionTracker&MockObject */
    private $adminSessionTracker;

    /** @var IdpSessionStorage&MockObject */
    private $idpSessionStorage;

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
        $this->adminSessionTracker = $this->createMock(AdminSessionTracker::class);
        $this->idpSessionStorage = $this->createMock(IdpSessionStorage::class);

        $this->creator = new AdminSessionCreator(
            $this->authSession,
            $this->eventManager,
            $this->userResource,
            $this->twoFactorSessionGranter,
            $this->adminSessionTracker,
            $this->idpSessionStorage
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
        // Magento_Security session tracking runs as on a native login (Auth::login plugin).
        $this->adminSessionTracker->expects(self::once())->method('processLogin');
        $this->idpSessionStorage->expects(self::never())->method('save');

        $this->creator->create($user);
    }

    // Only the save ordering is under test here; the rest of the login is covered above.
    #[AllowMockObjectsWithoutExpectations]
    public function testCreateKeepsIdTokenForIdpLogout(): void
    {
        $order = [];
        $this->authSession->method('processLogin')->willReturnCallback(function () use (&$order) {
            $order[] = 'processLogin';
            return $this->authSession;
        });
        $this->idpSessionStorage->expects(self::once())
            ->method('save')
            ->with('raw-id-token', 'okta')
            ->willReturnCallback(function () use (&$order): void {
                $order[] = 'save';
            });

        $this->creator->create($this->createStub(User::class), [], 'raw-id-token', 'okta');

        // Saved after processLogin so it lands in the authenticated session.
        self::assertSame(['processLogin', 'save'], $order);
    }
}

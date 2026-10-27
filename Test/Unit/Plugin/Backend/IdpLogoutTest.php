<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Plugin\Backend;

use DmLab\AdminSso\Model\Oidc\LogoutUrlResolver;
use DmLab\AdminSso\Model\Session\IdpSessionStorage;
use DmLab\AdminSso\Plugin\Backend\IdpLogout;
use Magento\Backend\Controller\Adminhtml\Auth\Logout;
use Magento\Backend\Model\View\Result\Redirect;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class IdpLogoutTest extends TestCase
{
    /** @var IdpSessionStorage&Stub */
    private $storage;

    /** @var Redirect&MockObject */
    private $redirect;

    protected function setUp(): void
    {
        $this->storage = $this->createStub(IdpSessionStorage::class);
        $this->redirect = $this->createMock(Redirect::class);
    }

    /**
     * @param LogoutUrlResolver $resolver
     * @param string[] $order
     */
    private function execute(LogoutUrlResolver $resolver, array &$order = []): mixed
    {
        $proceed = function () use (&$order) {
            $order[] = 'logout';
            return $this->redirect;
        };

        return (new IdpLogout($this->storage, $resolver))
            ->aroundExecute($this->createStub(Logout::class), $proceed);
    }

    public function testRedirectsToIdpAfterLocalLogout(): void
    {
        $order = [];
        $this->storage->method('getIdToken')->willReturnCallback(function () use (&$order) {
            $order[] = 'read';
            return 'raw-id-token';
        });
        $this->storage->method('getProviderCode')->willReturn('okta');
        $resolver = $this->createMock(LogoutUrlResolver::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with('raw-id-token', 'okta')
            ->willReturnCallback(function () use (&$order) {
                $order[] = 'resolve';
                return 'https://idp.example/logout?id_token_hint=raw-id-token';
            });
        $this->redirect->expects(self::once())
            ->method('setUrl')
            ->with('https://idp.example/logout?id_token_hint=raw-id-token');

        self::assertSame($this->redirect, $this->execute($resolver, $order));
        // The token is read before the core logout destroys the session.
        self::assertSame(['read', 'logout', 'resolve'], $order);
    }

    public function testKeepsCoreRedirectWhenResolverDeclines(): void
    {
        $this->storage->method('getIdToken')->willReturn('raw-id-token');
        $this->storage->method('getProviderCode')->willReturn('okta');
        $resolver = $this->createStub(LogoutUrlResolver::class);
        $resolver->method('resolve')->willReturn(null);
        $this->redirect->expects(self::never())->method('setUrl');

        self::assertSame($this->redirect, $this->execute($resolver));
    }

    public function testNonSsoSessionLogsOutLocally(): void
    {
        $this->storage->method('getIdToken')->willReturn(null);
        $resolver = $this->createMock(LogoutUrlResolver::class);
        $resolver->expects(self::never())->method('resolve');
        $this->redirect->expects(self::never())->method('setUrl');

        self::assertSame($this->redirect, $this->execute($resolver));
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Plugin\Backend;

use DmLab\AdminSso\Plugin\Backend\OpenSsoActions;
use Magento\Backend\App\AbstractAction;
use Magento\Backend\App\Action\Plugin\Authentication;
use Magento\Backend\Model\Auth;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Interception\Code\InterfaceValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OpenSsoActionsTest extends TestCase
{
    #[DataProvider('ssoActionProvider')]
    public function testOpensSsoActionsOnOwnRouteWithoutTheCorePlugin(string $action): void
    {
        $storage = $this->createMock(AuthSession::class);
        $storage->expects(self::once())->method('refreshAcl');
        $auth = $this->createStub(Auth::class);
        $auth->method('getAuthStorage')->willReturn($storage);

        $request = $this->createMock(Http::class);
        $request->method('getRouteName')->willReturn('adminsso');
        $request->method('getActionName')->willReturn($action);
        $request->expects(self::once())->method('setDispatched')->with(true);

        $core = static fn () => self::fail('The core authentication plugin must be skipped for SSO actions.');
        $dispatch = static fn ($passed) => $passed === $request ? 'dispatched' : null;

        self::assertSame(
            'dispatched',
            (new OpenSsoActions($auth))->aroundAroundDispatch(
                $this->createStub(Authentication::class),
                $core,
                $this->createStub(AbstractAction::class),
                $dispatch,
                $request
            )
        );
    }

    /**
     * A same-named action on a foreign route, or a non-SSO action on this route, stays
     * behind the core plugin (which forwards a logged-out user to the login page).
     */
    #[DataProvider('notOpenedProvider')]
    public function testDelegatesEverythingElseToTheCorePlugin(string $route, string $action): void
    {
        $auth = $this->createMock(Auth::class);
        $auth->expects(self::never())->method('getAuthStorage');

        $request = $this->createMock(Http::class);
        $request->method('getRouteName')->willReturn($route);
        $request->method('getActionName')->willReturn($action);
        $request->expects(self::never())->method('setDispatched');

        $subject = $this->createStub(AbstractAction::class);
        $dispatch = static fn () => self::fail('The action must not be dispatched directly.');
        $core = static function ($passedSubject, $passedDispatch, $passedRequest) use ($subject, $dispatch, $request) {
            self::assertSame([$subject, $dispatch, $request], [$passedSubject, $passedDispatch, $passedRequest]);

            return 'core';
        };

        self::assertSame(
            'core',
            (new OpenSsoActions($auth))->aroundAroundDispatch(
                $this->createStub(Authentication::class),
                $core,
                $subject,
                $dispatch,
                $request
            )
        );
    }

    /** Signature check Magento runs at setup:di:compile for every plugin. */
    public function testPluginSignatureMatchesTheCorePlugin(): void
    {
        $this->expectNotToPerformAssertions();

        (new InterfaceValidator())->validate(OpenSsoActions::class, Authentication::class);
    }

    public function testDeclaredAsPluginNotPreference(): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->load(dirname(__DIR__, 4) . '/etc/adminhtml/di.xml'));
        $xpath = new \DOMXPath($dom);

        self::assertSame(0, $xpath->query('//preference[@for="' . Authentication::class . '"]')->length);
        self::assertSame(
            1,
            $xpath->query(
                '/config/type[@name="' . Authentication::class . '"]/plugin[@type="' . OpenSsoActions::class . '"]'
            )->length
        );
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function ssoActionProvider(): array
    {
        return ['start' => ['start'], 'callback' => ['callback']];
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function notOpenedProvider(): array
    {
        return [
            'same-named action on a foreign route' => ['catalog', 'start'],
            'non-SSO action on the SSO route' => ['adminsso', 'index'],
        ];
    }
}

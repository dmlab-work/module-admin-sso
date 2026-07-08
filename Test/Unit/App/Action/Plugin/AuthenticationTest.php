<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Test\Unit\App\Action\Plugin;

use MageDevGroup\AdminSso\App\Action\Plugin\Authentication;
use Magento\Backend\App\AbstractAction;
use Magento\Backend\App\BackendAppList;
use Magento\Backend\Model\Auth;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuthenticationTest extends TestCase
{
    /**
     * The SSO start/callback actions dispatch before login when they are on this
     * module's own route.
     */
    #[DataProvider('ssoActionProvider')]
    public function testOpensSsoActionsOnOwnRoute(string $action): void
    {
        $storage = $this->createMock(AuthSession::class);
        $storage->expects(self::once())->method('refreshAcl');

        $auth = $this->createStub(Auth::class);
        $auth->method('getAuthStorage')->willReturn($storage);

        $request = $this->createMock(Http::class);
        $request->method('getRouteName')->willReturn('adminsso');
        $request->method('getActionName')->willReturn($action);
        $request->expects(self::once())->method('setDispatched')->with(true);

        $proceeded = false;
        $proceed = function ($passed) use (&$proceeded, $request) {
            self::assertSame($request, $passed);
            $proceeded = true;

            return 'dispatched';
        };

        $plugin = $this->plugin($auth);

        self::assertSame('dispatched', $plugin->aroundDispatch($this->subject(), $proceed, $request));
        self::assertTrue($proceeded);
    }

    /**
     * A same-named action on a foreign route — or any non-SSO action on this
     * route — is NOT opened: the core plugin forwards the logged-out user to the
     * admin login instead of dispatching it.
     */
    #[DataProvider('notOpenedProvider')]
    public function testDoesNotOpenActionOutsideTheSsoSeam(string $route, string $action): void
    {
        $storage = $this->createStub(AuthSession::class);

        $auth = $this->createStub(Auth::class);
        $auth->method('getUser')->willReturn(null);
        $auth->method('isLoggedIn')->willReturn(false);
        $auth->method('getAuthStorage')->willReturn($storage);

        $request = $this->createMock(Http::class);
        $request->method('getRouteName')->willReturn($route);
        $request->method('getActionName')->willReturn($action);
        $request->method('getPost')->willReturn(null);
        $request->method('getParam')->willReturn(null);
        $request->method('isForwarded')->willReturn(false);
        $request->method('setForwarded')->willReturnSelf();
        $request->method('setRouteName')->willReturnSelf();
        $request->method('setControllerName')->willReturnSelf();
        $request->method('setDispatched')->willReturnSelf();
        // The give-away that the core (not the SSO shortcut) handled the request:
        // a logged-out user is forwarded to the admin login action.
        $request->expects(self::once())->method('setActionName')->with('login')->willReturnSelf();

        $plugin = $this->plugin($auth);
        $plugin->aroundDispatch($this->subject(), static fn ($r) => null, $request);
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

    private function subject(): AbstractAction
    {
        return $this->createStub(AbstractAction::class);
    }

    /**
     * @param Auth&MockObject $auth
     */
    private function plugin(Auth $auth): Authentication
    {
        return new Authentication(
            $auth,
            $this->createStub(UrlInterface::class),
            $this->createStub(ResponseInterface::class),
            $this->createStub(ActionFlag::class),
            $this->createStub(ManagerInterface::class),
            $this->createStub(UrlInterface::class),
            $this->createStub(RedirectFactory::class),
            $this->createStub(BackendAppList::class),
            $this->createStub(Validator::class)
        );
    }
}

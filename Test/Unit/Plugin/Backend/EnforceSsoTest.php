<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Test\Unit\Plugin\Backend;

use MageDevGroup\AdminSso\Model\Config;
use MageDevGroup\AdminSso\Plugin\Backend\EnforceSso;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\AuthenticationException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class EnforceSsoTest extends TestCase
{
    /** @var Config&Stub */
    private $config;

    /** @var RequestInterface&Stub */
    private $request;

    /** @var Auth&Stub */
    private $auth;

    /** @var EnforceSso */
    private EnforceSso $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->request = $this->createStub(RequestInterface::class);
        $this->auth = $this->createStub(Auth::class);

        $this->plugin = new EnforceSso($this->config, $this->request);
    }

    public function testBlocksNativeLoginWhenEnforced(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isEnforceSso')->willReturn(true);
        $this->config->method('isBreakGlassEnabled')->willReturn(false);

        $this->expectException(AuthenticationException::class);
        $this->plugin->beforeLogin($this->auth, 'admin', 'secret');
    }

    public function testAllowsBreakGlassLoginWhenToggledOnAndParamPresent(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isEnforceSso')->willReturn(true);
        $this->config->method('isBreakGlassEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnCallback(
            function ($name) {
                self::assertSame(EnforceSso::BREAK_GLASS_PARAM, $name);
                return '1';
            }
        );

        self::assertSame(
            ['admin', 'secret'],
            $this->plugin->beforeLogin($this->auth, 'admin', 'secret')
        );
    }

    public function testBlocksWhenBreakGlassParamMissing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isEnforceSso')->willReturn(true);
        $this->config->method('isBreakGlassEnabled')->willReturn(true);
        $this->request->method('getParam')->willReturnCallback(
            function ($name) {
                self::assertSame(EnforceSso::BREAK_GLASS_PARAM, $name);
                return null;
            }
        );

        $this->expectException(AuthenticationException::class);
        $this->plugin->beforeLogin($this->auth, 'admin', 'secret');
    }

    public function testBlocksWhenBreakGlassToggledOffEvenWithParam(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isEnforceSso')->willReturn(true);
        $this->config->method('isBreakGlassEnabled')->willReturn(false);
        $this->request->method('getParam')->willReturn('1');

        $this->expectException(AuthenticationException::class);
        $this->plugin->beforeLogin($this->auth, 'admin', 'secret');
    }

    public function testNativeLoginUntouchedWhenModuleDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        self::assertSame(
            ['admin', 'secret'],
            $this->plugin->beforeLogin($this->auth, 'admin', 'secret')
        );
    }

    public function testNativeLoginUntouchedWhenNotEnforced(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isEnforceSso')->willReturn(false);

        self::assertSame(
            ['admin', 'secret'],
            $this->plugin->beforeLogin($this->auth, 'admin', 'secret')
        );
    }
}

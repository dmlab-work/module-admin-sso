<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Plugin\Backend;

use DmLab\AdminSso\Model\Config;
use DmLab\AdminSso\Model\UserProvisioner;
use DmLab\AdminSso\Plugin\Backend\EnforceSso;
use Magento\Framework\Exception\AuthenticationException;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class EnforceSsoTest extends TestCase
{
    /** @var Config&Stub */
    private $config;

    /** @var User&Stub */
    private $user;

    /** @var UserResource&Stub */
    private $userResource;

    /** @var EnforceSso */
    private EnforceSso $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->user = $this->createStub(User::class);
        $this->userResource = $this->createStub(UserResource::class);

        $this->plugin = new EnforceSso($this->config, $this->userResource);
    }

    public function testBlocksNativeLoginWhenEnforced(): void
    {
        $this->enforce(breakGlass: false, allowlist: []);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Admin sign-in requires SSO.');
        $this->plugin->beforeAuthenticate($this->user, 'admin', 'secret');
    }

    public function testAllowsAllowlistedUserWhenBreakGlassOn(): void
    {
        $this->enforce(breakGlass: true, allowlist: ['admin', 'ops']);

        self::assertSame(
            ['admin', 'secret'],
            $this->plugin->beforeAuthenticate($this->user, 'admin', 'secret')
        );
    }

    public function testAllowlistMatchIsCaseInsensitiveAndTrimmed(): void
    {
        $this->enforce(breakGlass: true, allowlist: ['admin']);

        self::assertSame(
            [' Admin ', 'secret'],
            $this->plugin->beforeAuthenticate($this->user, ' Admin ', 'secret')
        );
    }

    public function testBlocksUserNotOnAllowlist(): void
    {
        $this->enforce(breakGlass: true, allowlist: ['ops']);

        $this->expectException(AuthenticationException::class);
        $this->plugin->beforeAuthenticate($this->user, 'admin', 'secret');
    }

    public function testBlocksListedUserWhenBreakGlassOff(): void
    {
        $this->enforce(breakGlass: false, allowlist: ['admin']);

        $this->expectException(AuthenticationException::class);
        $this->plugin->beforeAuthenticate($this->user, 'admin', 'secret');
    }

    public function testEmptyAllowlistBlocksEveryone(): void
    {
        $this->enforce(breakGlass: true, allowlist: []);

        $this->expectException(AuthenticationException::class);
        $this->plugin->beforeAuthenticate($this->user, 'admin', 'secret');
    }

    public function testNativeLoginUntouchedWhenModuleDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->config->method('isEnforceSso')->willReturn(true);

        self::assertSame(
            ['admin', 'secret'],
            $this->plugin->beforeAuthenticate($this->user, 'admin', 'secret')
        );
    }

    public function testLocalAdminLoginUntouchedWhenNotEnforced(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isEnforceSso')->willReturn(false);
        $this->userResource->method('loadByUsername')
            ->willReturn(['username' => 'admin', UserProvisioner::SUBJECT_FIELD => null]);

        self::assertSame(
            ['admin', 'secret'],
            $this->plugin->beforeAuthenticate($this->user, 'admin', 'secret')
        );
    }

    public function testBlocksSsoLinkedAdminWhenNotEnforced(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isEnforceSso')->willReturn(false);
        $this->userResource->method('loadByUsername')
            ->willReturnMap([['jane', ['username' => 'jane', UserProvisioner::SUBJECT_FIELD => 'sub-1']]]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Admin sign-in requires SSO.');
        $this->plugin->beforeAuthenticate($this->user, 'jane', 'secret');
    }

    public function testAllowsAllowlistedSsoLinkedAdminWhenNotEnforced(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isEnforceSso')->willReturn(false);
        $this->config->method('isBreakGlassEnabled')->willReturn(true);
        $this->config->method('getBreakGlassUsernames')->willReturn(['jane']);
        $this->userResource->method('loadByUsername')
            ->willReturn(['username' => 'jane', UserProvisioner::SUBJECT_FIELD => 'sub-1']);

        self::assertSame(['jane', 'secret'], $this->plugin->beforeAuthenticate($this->user, 'jane', 'secret'));
    }

    public function testSsoLinkedAdminUntouchedWhenModuleDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->userResource->method('loadByUsername')
            ->willReturn(['username' => 'jane', UserProvisioner::SUBJECT_FIELD => 'sub-1']);

        self::assertSame(['jane', 'secret'], $this->plugin->beforeAuthenticate($this->user, 'jane', 'secret'));
    }

    public function testUnknownUsernameFallsThroughToCoreWhenNotEnforced(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isEnforceSso')->willReturn(false);
        $this->userResource->method('loadByUsername')->willReturn(false);

        self::assertSame(['ghost', 'secret'], $this->plugin->beforeAuthenticate($this->user, 'ghost', 'secret'));
    }

    /**
     * @param bool $breakGlass
     * @param string[] $allowlist
     */
    private function enforce(bool $breakGlass, array $allowlist): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isEnforceSso')->willReturn(true);
        $this->config->method('isBreakGlassEnabled')->willReturn($breakGlass);
        $this->config->method('getBreakGlassUsernames')->willReturn($allowlist);
    }
}

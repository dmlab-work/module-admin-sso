<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Test\Unit\Model;

use MageDevGroup\AdminSso\Model\Config;
use MageDevGroup\AdminSso\Model\RoleAssigner;
use MageDevGroup\SsoCore\Model\Data\Identity;
use MageDevGroup\SsoCore\Model\Mapping\MappingEngine;
use Magento\Authorization\Model\Role;
use Magento\Authorization\Model\RoleFactory;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class RoleAssignerTest extends TestCase
{
    /** @var Config&Stub */
    private $config;

    /** @var UserResource&MockObject */
    private $userResource;

    /** @var RoleFactory&Stub */
    private $roleFactory;

    /** @var RoleAssigner */
    private RoleAssigner $assigner;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->userResource = $this->createMock(UserResource::class);
        $this->roleFactory = $this->createStub(RoleFactory::class);

        // Real mapping engine — the resolution logic is what we're exercising.
        $this->assigner = new RoleAssigner(
            $this->config,
            new MappingEngine(),
            $this->userResource,
            $this->roleFactory
        );
    }

    /**
     * Stub the role-existence probe: when true the resolved role loads (id set),
     * when false it does not (missing/typo'd role id). Call only in tests that
     * reach the probe (a role resolves and differs from the current one).
     *
     * @param bool $exists
     */
    private function resolvedRoleExists(bool $exists): void
    {
        $role = $this->createStub(Role::class);
        $role->method('load')->willReturnSelf();
        $role->method('getId')->willReturn($exists ? '5' : null);
        $this->roleFactory->method('create')->willReturn($role);
    }

    /**
     * Admin user whose currently assigned ACL role has the given id (null = none).
     *
     * @param string|null $currentRoleId
     * @return User&MockObject
     */
    private function user(?string $currentRoleId): User
    {
        $role = $this->createStub(Role::class);
        $role->method('getId')->willReturn($currentRoleId);

        $user = $this->createMock(User::class);
        $user->method('getRole')->willReturn($role);

        return $user;
    }

    /**
     * @param array<string,string> $map
     */
    private function withRules(array $map, ?string $default = null): void
    {
        $this->config->method('getGroupRoleMap')->willReturn($map);
        $this->config->method('getDefaultRoleId')->willReturn($default);
    }

    public function testAssignsMappedRoleWhenGroupMatches(): void
    {
        $this->withRules(['admins' => '5']);
        $this->resolvedRoleExists(true);
        $user = $this->user('2');

        $user->expects(self::once())->method('setData')->with('role_id', 5);
        $this->userResource->expects(self::once())->method('save')->with($user);

        $this->assigner->assign($user, new Identity('sub', 'u@example.com', 'U', ['admins']));
    }

    public function testFirstResolvedRoleWinsWithMultipleGroups(): void
    {
        // Magento admin users hold a single role; the first resolved rule wins.
        $this->withRules(['a' => '10', 'b' => '20']);
        $this->resolvedRoleExists(true);
        $user = $this->user('2');

        $user->expects(self::once())->method('setData')->with('role_id', 10);
        $this->userResource->expects(self::once())->method('save')->with($user);

        $this->assigner->assign($user, new Identity('sub', 'u@example.com', 'U', ['a', 'b']));
    }

    public function testFallsBackToDefaultRoleWhenNoGroupMatches(): void
    {
        $this->withRules(['admins' => '5'], '1');
        $this->resolvedRoleExists(true);
        $user = $this->user(null);

        $user->expects(self::once())->method('setData')->with('role_id', 1);
        $this->userResource->expects(self::once())->method('save')->with($user);

        $this->assigner->assign($user, new Identity('sub', 'u@example.com', 'U', ['engineering']));
    }

    public function testRevokesRoleWhenNoGroupMatchesAndNoDefault(): void
    {
        $this->withRules(['admins' => '5']);
        $user = $this->user('2');

        // No role resolves → the current role is stripped (deny): saving role_id 0
        // clears the authorization_role link so IdP group removal revokes access.
        $user->expects(self::once())->method('setData')->with('role_id', 0);
        $this->userResource->expects(self::once())->method('save')->with($user);

        $this->assigner->assign($user, new Identity('sub', 'u@example.com', 'U', ['engineering']));
    }

    public function testNoOpWhenNoRoleResolvesAndUserAlreadyHasNone(): void
    {
        // Nothing to strip: a fresh JIT user (or already-denied admin) holds no
        // role, so denial must not issue a needless write.
        $this->withRules(['admins' => '5']);
        $user = $this->user(null);

        $user->expects(self::never())->method('setData');
        $this->userResource->expects(self::never())->method('save');

        $this->assigner->assign($user, new Identity('sub', 'u@example.com', 'U', []));
    }

    public function testDeniesWhenResolvedRoleDoesNotExist(): void
    {
        // A typo'd / deleted role id in the config must not be assigned: saving
        // it would clear the current authorization_role link and grant nothing
        // (self-lockout under Enforce-SSO). Keep the current role instead.
        $this->withRules(['admins' => '999']);
        $this->resolvedRoleExists(false);
        $user = $this->user('2');

        $user->expects(self::never())->method('setData');
        $this->userResource->expects(self::never())->method('save');

        $this->assigner->assign($user, new Identity('sub', 'u@example.com', 'U', ['admins']));
    }

    public function testNoOpWhenUserAlreadyOnMappedRole(): void
    {
        // Refresh on re-login is idempotent: same role → no needless write.
        $this->withRules(['admins' => '5']);
        $user = $this->user('5');

        $user->expects(self::never())->method('setData');
        $this->userResource->expects(self::never())->method('save');

        $this->assigner->assign($user, new Identity('sub', 'u@example.com', 'U', ['admins']));
    }
}

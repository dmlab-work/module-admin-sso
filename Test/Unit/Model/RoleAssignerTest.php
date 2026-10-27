<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Model;

use DmLab\AdminSso\Model\Config;
use DmLab\AdminSso\Model\RoleAssigner;
use DmLab\SsoCore\Model\Data\Identity;
use DmLab\SsoCore\Model\Mapping\MappingEngine;
use Magento\Authorization\Model\Role;
use Magento\Authorization\Model\RoleFactory;
use Magento\Framework\Exception\LocalizedException;
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
     * Stub the role-existence probe: only the given role ids load (getId set);
     * any other id is a missing/typo'd role.
     *
     * @param string ...$existingIds
     */
    private function existingRoles(string ...$existingIds): void
    {
        $role = $this->createStub(Role::class);
        $loaded = null;
        $role->method('load')->willReturnCallback(
            static function ($id) use ($role, &$loaded): Role {
                $loaded = (string)$id;
                return $role;
            }
        );
        $role->method('getId')->willReturnCallback(
            static function () use (&$loaded, $existingIds): ?string {
                return in_array($loaded, $existingIds, true) ? $loaded : null;
            }
        );
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

    public function testResolvesMappedRoleWhenGroupMatches(): void
    {
        $this->withRules(['admins' => '5']);
        $this->existingRoles('5');
        // Resolution is read-only.
        $this->userResource->expects(self::never())->method('save');

        self::assertSame('5', $this->assigner->resolve(new Identity('sub', 'u@example.com', 'U', ['admins'])));
    }

    public function testFirstResolvedRoleWinsWithMultipleGroups(): void
    {
        // Magento admin users hold a single role; the first resolved rule wins.
        $this->withRules(['a' => '10', 'b' => '20']);
        $this->existingRoles('10', '20');
        // Resolution is read-only.
        $this->userResource->expects(self::never())->method('save');

        self::assertSame('10', $this->assigner->resolve(new Identity('sub', 'u@example.com', 'U', ['a', 'b'])));
    }

    public function testFallsBackToDefaultRoleWhenNoGroupMatches(): void
    {
        $this->withRules(['admins' => '5'], '1');
        $this->existingRoles('1');
        // Resolution is read-only.
        $this->userResource->expects(self::never())->method('save');

        self::assertSame('1', $this->assigner->resolve(new Identity('sub', 'u@example.com', 'U', ['engineering'])));
    }

    public function testRefusesWhenNoGroupMatchesAndNoDefault(): void
    {
        $this->withRules(['admins' => '5']);
        $this->userResource->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'No admin role is mapped for your identity provider groups. Contact your administrator.'
        );

        $this->assigner->resolve(new Identity('sub', 'u@example.com', 'U', ['engineering']));
    }

    public function testRefusesWhenGroupsAreIncompleteEvenWithDefault(): void
    {
        // Entra groups overage: the groups claim was replaced by a distributed-claim reference.
        $this->withRules(['admins' => '5'], '1');
        $this->existingRoles('1', '5');
        $this->userResource->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Your identity provider sent too many groups to determine your admin role.');

        $this->assigner->resolve(new Identity('sub', 'u@example.com', 'U', [], false, 'https://idp', ['groups'], true));
    }

    public function testRefusesWhenResolvedRoleDoesNotExist(): void
    {
        // A typo'd / deleted role id must not be assigned (it would strip the link).
        $this->withRules(['admins' => '999']);
        $this->existingRoles('5');
        $this->userResource->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'No admin role is mapped for your identity provider groups. Contact your administrator.'
        );

        $this->assigner->resolve(new Identity('sub', 'u@example.com', 'U', ['admins']));
    }

    public function testRefusesWhenMappedRoleIsMissingEvenWithValidDefault(): void
    {
        // A matched rule wins over the default, so a deleted mapped role refuses rather than falls back.
        $this->withRules(['admins' => '999'], '1');
        $this->existingRoles('1');
        $this->userResource->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);

        $this->assigner->resolve(new Identity('sub', 'u@example.com', 'U', ['admins']));
    }

    public function testAssignsRoleWhenDifferentFromCurrent(): void
    {
        $user = $this->user('2');

        $user->expects(self::once())->method('setData')->with('role_id', 5);
        $this->userResource->expects(self::once())->method('save')->with($user);

        $this->assigner->assign($user, '5');
    }

    public function testAssignsRoleToUserWithoutOne(): void
    {
        $user = $this->user(null);

        $user->expects(self::once())->method('setData')->with('role_id', 1);
        $this->userResource->expects(self::once())->method('save')->with($user);

        $this->assigner->assign($user, '1');
    }

    public function testNoOpWhenUserAlreadyOnRole(): void
    {
        // Refresh on re-login is idempotent: same role → no needless write.
        $user = $this->user('5');

        $user->expects(self::never())->method('setData');
        $this->userResource->expects(self::never())->method('save');

        $this->assigner->assign($user, '5');
    }
}

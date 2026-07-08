<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Model;

use MageDevGroup\SsoCore\Api\Data\IdentityInterface;
use MageDevGroup\SsoCore\Model\Mapping\MappingEngine;
use Magento\Authorization\Model\RoleFactory;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\User;

/**
 * Maps an identity's IdP groups onto a Magento admin ACL role and keeps the admin
 * user on it.
 *
 * The rules come from admin config ({@see Config::getGroupRoleMap()}); sso-core's
 * {@see MappingEngine} resolves the groups against them, falling back to the
 * configured default role when nothing matches. A Magento admin user holds a
 * single role, so the first resolved role wins. When neither a rule nor a default
 * applies the user's ACL role is stripped (deny) — removing a user from their IdP
 * groups revokes their admin access at the next login.
 *
 * Runs on every SSO login — on JIT create it grants the initial role, on
 * subsequent logins it refreshes it so IdP group changes take effect. Saving with
 * a `role_id` set makes the user resource replace the `authorization_role` link
 * (see Magento\User\Model\ResourceModel\User::_afterSave).
 *
 * Provider-neutral: groups arrive already normalized on the identity by sso-core.
 */
class RoleAssigner
{
    /**
     * @param Config $config
     * @param MappingEngine $mappingEngine
     * @param UserResource $userResource
     * @param RoleFactory $roleFactory
     */
    public function __construct(
        private readonly Config $config,
        private readonly MappingEngine $mappingEngine,
        private readonly UserResource $userResource,
        private readonly RoleFactory $roleFactory
    ) {
    }

    /**
     * Resolve and apply the ACL role for the identity onto the admin user.
     *
     * A no-op when the resolved role already matches the user's current one. When
     * no role resolves at all the current role is stripped (deny); a user that
     * already holds none is left untouched.
     *
     * @param User $user persisted admin user to assign the role to
     * @param IdentityInterface $identity verified identity from sso-core
     */
    public function assign(User $user, IdentityInterface $identity): void
    {
        $roleId = $this->resolveRoleId($identity);
        $currentRoleId = (string)$user->getRole()->getId();

        if ($roleId === null) {
            // No rule matched and no default configured → deny. Strip the current
            // ACL role so removing a user from their IdP groups revokes admin
            // access on the next SSO login (a fresh JIT user has none to strip).
            if ($currentRoleId !== '') {
                $this->revokeRole($user);
            }
            return;
        }

        if ($currentRoleId === $roleId) {
            return;
        }

        // A configured role id that no longer exists must not be assigned: the
        // user resource clears the current authorization_role link before it
        // re-creates one, and _createUserRole() no-ops for a missing role — so a
        // typo'd map/default would strip a working admin's role (self-lockout
        // under Enforce-SSO). Keep the current role instead (deny).
        if (!$this->roleExists($roleId)) {
            return;
        }

        // `role_id` drives the user resource to replace the authorization_role
        // link on save (see the class docblock).
        $user->setData('role_id', (int)$roleId);
        $this->userResource->save($user);
    }

    /**
     * Strip the admin user's ACL role.
     *
     * Saving with `role_id` 0 makes the user resource clear the
     * `authorization_role` link and create no replacement (see
     * ResourceModel\User::_afterSave / _createUserRole), leaving the admin with no
     * backend access.
     *
     * @param User $user
     */
    private function revokeRole(User $user): void
    {
        $user->setData('role_id', 0);
        $this->userResource->save($user);
    }

    /**
     * The single ACL role id for the identity's groups.
     *
     * Null when no group maps and no default role is configured.
     *
     * @param IdentityInterface $identity
     */
    private function resolveRoleId(IdentityInterface $identity): ?string
    {
        $roles = $this->mappingEngine->resolve(
            $identity->getGroups(),
            $this->config->getGroupRoleMap(),
            $this->config->getDefaultRoleId()
        );

        return $roles[0] ?? null;
    }

    /**
     * Whether an admin ACL role with the given id exists.
     *
     * @param string $roleId
     */
    private function roleExists(string $roleId): bool
    {
        return (bool)$this->roleFactory->create()->load($roleId)->getId();
    }
}

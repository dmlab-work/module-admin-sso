<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Model;

use DmLab\SsoCore\Api\Data\IdentityInterface;
use DmLab\SsoCore\Model\Mapping\MappingEngine;
use Magento\Authorization\Model\RoleFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\User;

/**
 * Maps an identity's IdP groups onto a Magento admin ACL role and keeps the admin
 * user on it.
 *
 * The rules come from admin config ({@see Config::getGroupRoleMap()}); sso-core's
 * {@see MappingEngine} resolves the groups against them, falling back to the
 * configured default role when nothing matches. Incomplete groups (an IdP overage,
 * sent as a distributed claim) refuse the login. A Magento admin user holds a
 * single role, so the first resolved role wins. When neither a rule nor a default
 * applies the SSO login is refused — the stored role is never demoted
 * (deprovisioning is SCIM's job).
 *
 * The role is resolved before provisioning so a refused login creates no admin,
 * then assigned on every SSO login so IdP group changes take effect. Saving with
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
     * The single existing ACL role id for the identity's groups.
     *
     * @param IdentityInterface $identity verified identity from sso-core
     * @return string
     * @throws LocalizedException when the groups are incomplete (IdP overage), no rule or default
     *         applies, or the role does not exist
     */
    public function resolve(IdentityInterface $identity): string
    {
        // Incomplete groups must not fall back to the default role or drop a mapped one.
        if ($identity->hasDistributedGroups()) {
            throw new LocalizedException(
                __(
                    'Your identity provider sent too many groups to determine your admin role. '
                    . 'Ask your administrator to limit the groups sent to this application.'
                )
            );
        }

        $roles = $this->mappingEngine->resolve(
            $identity->getGroups(),
            $this->config->getGroupRoleMap(),
            $this->config->getDefaultRoleId()
        );
        $roleId = $roles[0] ?? null;

        // A typo'd / deleted role id must not be assigned: the user resource would
        // clear the current authorization_role link and grant nothing.
        if ($roleId === null || !$this->roleExists($roleId)) {
            throw new LocalizedException(
                __('No admin role is mapped for your identity provider groups. Contact your administrator.')
            );
        }

        return $roleId;
    }

    /**
     * Put the admin user on the given role; a no-op when already on it.
     *
     * @param User $user persisted admin user
     * @param string $roleId role id from {@see resolve()}
     */
    public function assign(User $user, string $roleId): void
    {
        if ((string)$user->getRole()->getId() === $roleId) {
            return;
        }

        $user->setData('role_id', (int)$roleId);
        $this->userResource->save($user);
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

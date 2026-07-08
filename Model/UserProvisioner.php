<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Model;

use MageDevGroup\SsoCore\Api\Data\IdentityInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;

/**
 * Just-in-time provisioning of Magento admin users from a verified OIDC identity.
 *
 * Resolution order mirrors the suite contract: match by the stored IdP subject
 * (`sub`) first — the stable re-login key — then fall back to email, adopting an
 * existing local admin and linking it to the subject on first SSO login. When
 * neither matches, a fresh admin user is created (JIT).
 *
 * Provider-neutral: the identity is already normalized by sso-core, so no
 * IdP-specific claim handling lives here. Role assignment is a later task; this
 * only ensures the `admin_user` row exists and carries the subject link.
 *
 * A matched (existing) admin must be active: SSO must not bypass the deactivation
 * that native login enforces ({@see \Magento\User\Model\User::verifyIdentity}).
 */
class UserProvisioner
{
    /** admin_user column holding the IdP subject link (see etc/db_schema.xml). */
    public const SUBJECT_FIELD = 'magedevgroup_sso_subject_id';

    /** Lastname used when the IdP releases no name / only a single-word name. */
    private const DEFAULT_LAST_NAME = 'SSO';

    /** admin_user.firstname / lastname column length. */
    private const NAME_MAX_LENGTH = 32;

    /** admin_user.username column length. */
    private const USERNAME_MAX_LENGTH = 40;

    /**
     * @param UserFactory $userFactory
     * @param UserResource $userResource
     * @param UserCollectionFactory $userCollectionFactory
     * @param Random $random
     */
    public function __construct(
        private readonly UserFactory $userFactory,
        private readonly UserResource $userResource,
        private readonly UserCollectionFactory $userCollectionFactory,
        private readonly Random $random
    ) {
    }

    /**
     * Return the admin user for the given identity, creating one if needed.
     *
     * @param IdentityInterface $identity verified identity from sso-core
     * @return User the linked or newly created admin user (persisted)
     * @throws LocalizedException when a matched admin is disabled, or when a new
     *         user must be created but the IdP released no email to key it on
     */
    public function provision(IdentityInterface $identity): User
    {
        $bySubject = $this->loadByField(self::SUBJECT_FIELD, $identity->getSubjectId());
        if ($bySubject !== null) {
            $this->assertActive($bySubject);
            return $bySubject;
        }

        $email = $this->normalizeEmail($identity->getEmail());
        if ($email !== null) {
            $byEmail = $this->loadByField('email', $email);
            if ($byEmail !== null) {
                $this->assertActive($byEmail);
                $this->assertAdoptable($byEmail);
                return $this->linkSubject($byEmail, $identity->getSubjectId());
            }
        }

        return $this->createUser($identity, $email);
    }

    /**
     * Load a single admin user matching a column value, or null when none.
     *
     * @param string $field
     * @param string $value
     */
    private function loadByField(string $field, string $value): ?User
    {
        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToFilter($field, $value);
        $collection->setPageSize(1);

        /** @var User $user */
        $user = $collection->getFirstItem();

        return $user->getId() ? $user : null;
    }

    /**
     * Refuse a disabled admin account. Native login rejects inactive users in
     * {@see \Magento\User\Model\User::verifyIdentity}; the SSO path must too, or
     * deactivating an admin would not lock them out.
     *
     * @param User $user matched existing admin
     * @throws LocalizedException when the account is inactive
     */
    private function assertActive(User $user): void
    {
        if ((int)$user->getData('is_active') !== 1) {
            throw new LocalizedException(
                __('This admin account is disabled. Contact your administrator.')
            );
        }
    }

    /**
     * Guard email-based adoption against account takeover: only an admin that
     * carries no IdP subject link may be adopted by email. A non-empty link here
     * means the email belongs to an admin already bound to a *different* subject —
     * the matching subject would have been resolved by {@see self::provision()}'s
     * subject lookup — so a second identity that merely shares the email must not
     * rebind or hijack it. Email is not a stable identifier; the subject is.
     *
     * @param User $user admin matched by email
     * @throws LocalizedException when the account is already linked to another subject
     */
    private function assertAdoptable(User $user): void
    {
        if ((string)$user->getData(self::SUBJECT_FIELD) !== '') {
            throw new LocalizedException(
                __('An admin account with this email is already linked to a different identity.')
            );
        }
    }

    /**
     * Adopt an existing local admin by storing the IdP subject on it.
     *
     * Future logins then match by subject rather than email.
     *
     * @param User $user
     * @param string $subjectId
     */
    private function linkSubject(User $user, string $subjectId): User
    {
        $user->setData(self::SUBJECT_FIELD, $subjectId);
        $this->userResource->save($user);

        return $user;
    }

    /**
     * Create and persist a fresh admin user from the identity.
     *
     * @param IdentityInterface $identity
     * @param string|null $email already-normalized email, or null
     * @throws LocalizedException when no email is available to key the new user on
     */
    private function createUser(IdentityInterface $identity, ?string $email): User
    {
        if ($email === null) {
            throw new LocalizedException(
                __('The identity provider did not release an email address required to create an admin user.')
            );
        }

        $username = $this->deriveUsername($email, $identity->getSubjectId());
        [$firstName, $lastName] = $this->splitName($identity->getName(), $username);

        $user = $this->userFactory->create();
        $user->setData([
            'username' => $username,
            'firstname' => $firstName,
            'lastname' => $lastName,
            'email' => $email,
            'password' => $this->generatePassword(),
            'is_active' => 1,
            'interface_locale' => 'en_US',
            self::SUBJECT_FIELD => $identity->getSubjectId(),
        ]);
        $this->userResource->save($user);

        return $user;
    }

    /**
     * Trimmed, lower-cased email, or null when the IdP released none.
     *
     * @param string|null $email
     */
    private function normalizeEmail(?string $email): ?string
    {
        $email = strtolower(trim((string)$email));

        return $email === '' ? null : $email;
    }

    /**
     * Derive a unique username from the email local part, falling back to the
     * subject. The username is only a label (login is via SSO), so a collision
     * with an unrelated local admin must not block onboarding.
     *
     * @param string $email
     * @param string $subjectId
     */
    private function deriveUsername(string $email, string $subjectId): string
    {
        $local = strstr($email, '@', true);
        $candidate = trim(is_string($local) ? $local : $email);
        if ($candidate === '') {
            $candidate = $subjectId;
        }

        return $this->uniqueUsername(
            mb_substr($candidate, 0, self::USERNAME_MAX_LENGTH),
            $subjectId
        );
    }

    /**
     * Return a username not already taken by another admin. On collision a
     * numeric suffix is appended (kept within the column length); if every
     * probe collides, the globally unique subject id is used.
     *
     * @param string $base
     * @param string $subjectId
     */
    private function uniqueUsername(string $base, string $subjectId): string
    {
        if ($this->loadByField('username', $base) === null) {
            return $base;
        }

        for ($i = 2; $i <= 99; $i++) {
            $suffix = (string)$i;
            $candidate = mb_substr($base, 0, self::USERNAME_MAX_LENGTH - mb_strlen($suffix)) . $suffix;
            if ($this->loadByField('username', $candidate) === null) {
                return $candidate;
            }
        }

        return mb_substr($subjectId, 0, self::USERNAME_MAX_LENGTH);
    }

    /**
     * Split a display name into first/last, guaranteeing both are non-empty
     * (Magento requires both) and within the column length.
     *
     * @param string|null $name
     * @param string $fallbackFirst used when the IdP released no name
     * @return array{0:string,1:string}
     */
    private function splitName(?string $name, string $fallbackFirst): array
    {
        $name = trim((string)$name);
        if ($name === '') {
            return [
                mb_substr($fallbackFirst, 0, self::NAME_MAX_LENGTH),
                self::DEFAULT_LAST_NAME,
            ];
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];
        $first = (string)array_shift($parts);
        $last = $parts === [] ? self::DEFAULT_LAST_NAME : implode(' ', $parts);

        return [
            mb_substr($first, 0, self::NAME_MAX_LENGTH),
            mb_substr($last, 0, self::NAME_MAX_LENGTH),
        ];
    }

    /**
     * Random password satisfying Magento's admin rules (letters + digits). Never
     * used for login — SSO is the only entry — but a valid value is required to
     * persist the user.
     */
    private function generatePassword(): string
    {
        return $this->random->getRandomString(32) . 'a1';
    }
}

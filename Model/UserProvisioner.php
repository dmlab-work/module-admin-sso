<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Model;

use DmLab\SsoCore\Api\Data\IdentityInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;

/**
 * Just-in-time provisioning of Magento admin users from a verified OIDC identity.
 *
 * Resolution order mirrors the suite contract: match by the stored IdP issuer +
 * subject (`iss`, `sub`) first — `sub` is unique only per issuer — then fall back to
 * email, adopting an existing local admin and linking it to the identity on first
 * SSO login. When neither matches, a fresh admin user is created (JIT). Email
 * adoption requires an IdP-verified email; an unverified match is refused, never linked.
 *
 * A link stored before the issuer was recorded (empty issuer) is bound to the issuer
 * of the first login presenting its subject, then requires an exact match.
 *
 * Provider-neutral: the identity is already normalized by sso-core, so no
 * IdP-specific claim handling lives here. Role assignment is a later task; this
 * only ensures the `admin_user` row exists and carries the subject link.
 *
 * A matched (existing) admin must be active and not locked: SSO must not bypass the
 * deactivation and lockout that native login enforces ({@see \Magento\User\Model\User::verifyIdentity},
 * {@see \Magento\User\Observer\Backend\AuthObserver}).
 */
class UserProvisioner
{
    /** admin_user column holding the IdP subject link (see etc/db_schema.xml). */
    public const SUBJECT_FIELD = 'dmlab_sso_subject_id';

    /** admin_user column holding the issuer the subject belongs to. */
    public const ISSUER_FIELD = 'dmlab_sso_issuer';

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
     * @throws LocalizedException when the identity has no issuer, a matched admin is disabled, an email
     *         match is unverified, or a new user must be created but the IdP released no email
     */
    public function provision(IdentityInterface $identity): User
    {
        if ($identity->getIssuer() === '') {
            throw new LocalizedException(__('The identity provider did not identify its issuer.'));
        }

        $bySubject = $this->loadBySubject($identity);
        if ($bySubject !== null) {
            $this->assertActive($bySubject);
            if ((string)$bySubject->getData(self::ISSUER_FIELD) === '') {
                $this->linkIdentity($bySubject, $identity);
            }
            return $bySubject;
        }

        $email = $this->normalizeEmail($identity->getEmail());
        if ($email !== null) {
            $byEmail = $this->loadByField('email', $email);
            if ($byEmail !== null) {
                $this->assertEmailVerified($identity);
                $this->assertActive($byEmail);
                $this->assertAdoptable($byEmail);
                return $this->linkIdentity($byEmail, $identity);
            }
        }

        return $this->createUser($identity, $email);
    }

    /**
     * Admin linked to the identity's issuer + subject, else a pre-issuer link with the same subject.
     *
     * @param IdentityInterface $identity
     */
    private function loadBySubject(IdentityInterface $identity): ?User
    {
        $collection = $this->userCollectionFactory->create();
        $collection->addFieldToFilter(self::SUBJECT_FIELD, $identity->getSubjectId());
        $collection->addFieldToFilter(
            self::ISSUER_FIELD,
            [['eq' => $identity->getIssuer()], ['null' => true], ['eq' => '']]
        );
        // An exact issuer sorts before ''/NULL, so it wins over a pre-issuer link.
        $collection->setOrder(self::ISSUER_FIELD, 'DESC');
        $collection->setPageSize(1);

        /** @var User $user */
        $user = $collection->getFirstItem();

        return $user->getId() ? $user : null;
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
     * Refuse a disabled or locked admin account. Native login rejects inactive users in
     * {@see \Magento\User\Model\User::verifyIdentity} and locked ones (`lock_expires` in the
     * future, set by failed logins or an admin "Lock") in the backend AuthObserver; SSO must too.
     *
     * @param User $user matched existing admin
     * @throws LocalizedException when the account is inactive or locked
     */
    private function assertActive(User $user): void
    {
        if ((int)$user->getData('is_active') !== 1) {
            throw new LocalizedException(
                __('This admin account is disabled. Contact your administrator.')
            );
        }

        $lockExpires = (string)$user->getData('lock_expires');
        if ($lockExpires !== '' && new \DateTime($lockExpires) > new \DateTime()) {
            throw new LocalizedException(
                __('This admin account is temporarily locked. Please try again later.')
            );
        }
    }

    /**
     * Refuse adoption by an email the IdP did not verify.
     *
     * An unverified address could be claimed by anyone, so it must not grant an existing admin account.
     *
     * @param IdentityInterface $identity
     * @throws LocalizedException when the IdP did not verify the email
     */
    private function assertEmailVerified(IdentityInterface $identity): void
    {
        if (!$identity->isEmailVerified()) {
            throw new LocalizedException(
                __(
                    'An admin account with this email exists, '
                    . 'but your identity provider did not verify the email address.'
                )
            );
        }
    }

    /**
     * Guard email-based adoption against account takeover: only an admin that
     * carries no IdP subject link may be adopted by email. A non-empty link here
     * means the email belongs to an admin already bound to a *different* identity
     * (another subject, or the same subject at another issuer) — so a second identity
     * that merely shares the email must not rebind or hijack it. Email is not a
     * stable identifier; issuer + subject is.
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
     * Bind an admin to the identity by storing its issuer + subject.
     *
     * Future logins then match by issuer + subject rather than email.
     *
     * @param User $user
     * @param IdentityInterface $identity
     */
    private function linkIdentity(User $user, IdentityInterface $identity): User
    {
        $user->setData(self::SUBJECT_FIELD, $identity->getSubjectId());
        $user->setData(self::ISSUER_FIELD, $identity->getIssuer());
        $this->userResource->save($user);

        return $user;
    }

    /**
     * Create and persist a fresh admin user from the identity.
     *
     * @param IdentityInterface $identity
     * @param string|null $email already-normalized email, or null
     * @return User the persisted user, freshly loaded
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
            self::ISSUER_FIELD => $identity->getIssuer(),
        ]);
        $this->userResource->save($user);

        // The saved instance keeps isObjectNew()=true (the user resource never resets it), so any later
        // save (role assignment, ACL refresh) would treat it as new and demand a password. Return a clean load.
        $created = $this->userFactory->create();
        $this->userResource->load($created, $user->getId());

        return $created;
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

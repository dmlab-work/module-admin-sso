<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Model;

use DmLab\AdminSso\Model\UserProvisioner;
use DmLab\SsoCore\Api\Data\IdentityInterface;
use DmLab\SsoCore\Model\Data\Identity;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\ResourceModel\User\Collection;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserProvisionerTest extends TestCase
{
    private const ISSUER = 'https://idp.example';

    /** @var UserFactory&MockObject */
    private $userFactory;

    /** @var UserResource&MockObject */
    private $userResource;

    /** @var UserCollectionFactory&MockObject */
    private $collectionFactory;

    /** @var Random&\PHPUnit\Framework\MockObject\Stub */
    private $random;

    /** @var UserProvisioner */
    private UserProvisioner $provisioner;

    protected function setUp(): void
    {
        $this->userFactory = $this->createMock(UserFactory::class);
        $this->userResource = $this->createMock(UserResource::class);
        $this->collectionFactory = $this->createMock(UserCollectionFactory::class);
        $this->random = $this->createStub(Random::class);

        $this->provisioner = new UserProvisioner(
            $this->userFactory,
            $this->userResource,
            $this->collectionFactory,
            $this->random
        );
    }

    /**
     * Collection whose single-item lookup yields the given admin user.
     *
     * @param User $firstItem
     * @return Collection&\PHPUnit\Framework\MockObject\Stub
     */
    private function collectionReturning(User $firstItem)
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($firstItem);

        return $collection;
    }

    /** A "not found" result: getFirstItem returns a user with no id. */
    private function emptyUser(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(null);

        return $user;
    }

    /**
     * An existing, enabled admin user with the given id.
     *
     * @return User&\PHPUnit\Framework\MockObject\Stub
     */
    private function activeUser(int $id)
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getData')->willReturnCallback(
            static fn ($field) => match ($field) {
                'is_active' => 1,
                UserProvisioner::ISSUER_FIELD => self::ISSUER,
                default => null,
            }
        );

        return $user;
    }

    public function testReturnsExistingUserMatchedBySubject(): void
    {
        $existing = $this->activeUser(5);

        // Only the subject lookup runs; email lookup and any write are skipped.
        $this->collectionFactory->expects(self::once())
            ->method('create')
            ->willReturn($this->collectionReturning($existing));
        $this->userResource->expects(self::never())->method('save');
        $this->userFactory->expects(self::never())->method('create');

        $identity = new Identity('sub-1', 'user@example.com', 'User One', ['admins'], false, self::ISSUER);

        self::assertSame($existing, $this->provisioner->provision($identity));
    }

    public function testLinksExistingUserMatchedByEmail(): void
    {
        $existing = $this->createMock(User::class);
        $existing->method('getId')->willReturn(7);
        $existing->method('getData')->willReturnCallback(
            static fn ($field) => $field === 'is_active' ? 1 : null
        );
        // Email collision policy: adopt the local admin and stamp the issuer + subject on it.
        $linked = [];
        $existing->expects(self::exactly(2))
            ->method('setData')
            ->willReturnCallback(function ($key, $value) use (&$linked, $existing) {
                $linked[$key] = $value;
                return $existing;
            });

        $this->collectionFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                $this->collectionReturning($this->emptyUser()),
                $this->collectionReturning($existing)
            );
        $this->userResource->expects(self::once())->method('save')->with($existing);
        $this->userFactory->expects(self::never())->method('create');

        $identity = new Identity('sub-1', 'User@Example.com', 'User One', [], true, self::ISSUER);

        self::assertSame($existing, $this->provisioner->provision($identity));
        self::assertSame(
            [UserProvisioner::SUBJECT_FIELD => 'sub-1', UserProvisioner::ISSUER_FIELD => self::ISSUER],
            $linked
        );
    }

    public function testCreatesNewUserWhenNoneMatch(): void
    {
        // Subject, email, and username-uniqueness lookups all miss.
        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));

        $newUser = $this->createMock(User::class);
        $newUser->method('getId')->willReturn(42);
        $loadedUser = $this->createStub(User::class);
        $this->userFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($newUser, $loadedUser);
        $this->random->method('getRandomString')->willReturn('RANDOM');

        $newUser->expects(self::once())
            ->method('setData')
            ->with([
                'username' => 'jane.doe',
                'firstname' => 'Jane',
                'lastname' => 'Doe',
                'email' => 'jane.doe@example.com',
                'password' => 'RANDOMa1',
                'is_active' => 1,
                'interface_locale' => 'en_US',
                UserProvisioner::SUBJECT_FIELD => 'sub-9',
                UserProvisioner::ISSUER_FIELD => self::ISSUER,
            ]);
        $this->userResource->expects(self::once())->method('save')->with($newUser);
        $this->userResource->expects(self::once())->method('load')->with($loadedUser, 42);

        $identity = new Identity('sub-9', 'Jane.Doe@Example.com', 'Jane Doe', ['staff'], false, self::ISSUER);

        self::assertSame($loadedUser, $this->provisioner->provision($identity));
    }

    /**
     * The just-saved instance stays isObjectNew()=true, so later saves of it (role assignment,
     * ACL refresh on the next request) would demand a password; a fresh load must be returned.
     */
    public function testCreatedUserIsReturnedFreshlyLoadedNotAsTheSavedInstance(): void
    {
        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->random->method('getRandomString')->willReturn('RANDOM');

        $savedUser = $this->createStub(User::class);
        $savedUser->method('getId')->willReturn(42);
        $savedUser->method('isObjectNew')->willReturn(true);
        $loadedUser = $this->createStub(User::class);
        $this->userFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($savedUser, $loadedUser);

        $loadedId = null;
        $this->userResource->expects(self::once())->method('save')->with($savedUser);
        $this->userResource->expects(self::once())
            ->method('load')
            ->willReturnCallback(function ($object, $id) use ($loadedUser, &$loadedId) {
                self::assertSame($loadedUser, $object);
                $loadedId = $id;
                return $this->userResource;
            });

        $result = $this->provisioner->provision(
            new Identity('sub-9', 'jane@example.com', 'Jane Doe', [], false, self::ISSUER)
        );

        self::assertSame($loadedUser, $result);
        self::assertNotSame($savedUser, $result);
        self::assertSame(42, $loadedId);
    }

    /** A derived username already taken by an unrelated admin is suffixed, not rejected. */
    public function testCreatesNewUserWithDisambiguatedUsernameOnCollision(): void
    {
        $this->collectionFactory->expects(self::exactly(4))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                $this->collectionReturning($this->emptyUser()),   // subject miss
                $this->collectionReturning($this->emptyUser()),   // email miss
                $this->collectionReturning($this->activeUser(3)), // username 'jane.doe' taken
                $this->collectionReturning($this->emptyUser())    // username 'jane.doe2' free
            );

        $newUser = $this->createStub(User::class);
        $this->userFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($newUser, $this->createStub(User::class));
        $this->userResource->expects(self::once())->method('save')->with($newUser);
        $this->random->method('getRandomString')->willReturn('RANDOM');

        $captured = [];
        $newUser->method('setData')->willReturnCallback(
            function ($data) use (&$captured, $newUser) {
                $captured = $data;
                return $newUser;
            }
        );

        $this->provisioner->provision(
            new Identity('sub-9', 'Jane.Doe@Example.com', 'Jane Doe', [], false, self::ISSUER)
        );

        self::assertSame('jane.doe2', $captured['username']);
    }

    public function testCreatesNewUserWithFallbackLastNameForSingleWordName(): void
    {
        // Subject, email, and username-uniqueness lookups all miss.
        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));

        $newUser = $this->createStub(User::class);
        $this->userFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($newUser, $this->createStub(User::class));
        $this->random->method('getRandomString')->willReturn('RANDOM');

        $captured = null;
        $newUser->method('setData')->willReturnCallback(
            function ($data) use (&$captured, $newUser) {
                $captured = $data;
                return $newUser;
            }
        );
        $this->userResource->expects(self::once())->method('save')->with($newUser);

        $identity = new Identity('sub-3', 'root@example.com', 'Madonna', [], false, self::ISSUER);
        $this->provisioner->provision($identity);

        self::assertSame('Madonna', $captured['firstname']);
        self::assertSame('SSO', $captured['lastname']);
        self::assertSame('root', $captured['username']);
    }

    public function testThrowsWhenCreatingWithoutEmail(): void
    {
        // Subject misses and there is no email to fall back on → cannot create.
        $this->collectionFactory->expects(self::once())
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));
        $this->userFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::never())->method('save');

        $identity = new Identity('sub-x', null, 'No Email', [], false, self::ISSUER);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'The identity provider did not release an email address required to create an admin user.'
        );

        $this->provisioner->provision($identity);
    }

    /** A link stored before issuers were recorded is bound to the first matching login's issuer. */
    public function testBindsIssuerOnPreIssuerSubjectLink(): void
    {
        $legacy = $this->createMock(User::class);
        $legacy->method('getId')->willReturn(5);
        $legacy->method('getData')->willReturnCallback(
            static fn ($field) => match ($field) {
                'is_active' => 1,
                UserProvisioner::SUBJECT_FIELD => 'sub-1',
                default => null,
            }
        );
        $linked = [];
        $legacy->expects(self::exactly(2))
            ->method('setData')
            ->willReturnCallback(function ($key, $value) use (&$linked, $legacy) {
                $linked[$key] = $value;
                return $legacy;
            });

        $this->collectionFactory->expects(self::once())
            ->method('create')
            ->willReturn($this->collectionReturning($legacy));
        $this->userResource->expects(self::once())->method('save')->with($legacy);
        $this->userFactory->expects(self::never())->method('create');

        $identity = new Identity('sub-1', 'user@example.com', 'User One', [], false, self::ISSUER);

        self::assertSame($legacy, $this->provisioner->provision($identity));
        self::assertSame(self::ISSUER, $linked[UserProvisioner::ISSUER_FIELD]);
    }

    /** A disabled pre-issuer link is refused before the issuer is bound. */
    public function testDoesNotBindIssuerOnDisabledPreIssuerLink(): void
    {
        $legacy = $this->createMock(User::class);
        $legacy->method('getId')->willReturn(5);
        $legacy->method('getData')->willReturnCallback(
            static fn ($field) => $field === 'is_active' ? 0 : null
        );
        $legacy->expects(self::never())->method('setData');

        $this->collectionFactory->expects(self::once())
            ->method('create')
            ->willReturn($this->collectionReturning($legacy));
        $this->userResource->expects(self::never())->method('save');
        $this->userFactory->expects(self::never())->method('create');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This admin account is disabled. Contact your administrator.');

        $this->provisioner->provision(new Identity('sub-1', 'user@example.com', 'U', [], false, self::ISSUER));
    }

    public function testRefusesIdentityWithoutIssuer(): void
    {
        $this->collectionFactory->expects(self::never())->method('create');
        $this->userResource->expects(self::never())->method('save');
        $this->userFactory->expects(self::never())->method('create');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The identity provider did not identify its issuer.');

        $this->provisioner->provision(new Identity('sub-1', 'user@example.com', 'User One', []));
    }

    /** A matched-by-subject admin who was deactivated must be refused. */
    public function testRejectsDisabledUserMatchedBySubject(): void
    {
        $disabled = $this->createStub(User::class);
        $disabled->method('getId')->willReturn(5);
        $disabled->method('getData')->willReturnCallback(
            static fn ($field) => $field === 'is_active' ? 0 : null
        );

        $this->collectionFactory->expects(self::once())
            ->method('create')
            ->willReturn($this->collectionReturning($disabled));
        $this->userResource->expects(self::never())->method('save');
        $this->userFactory->expects(self::never())->method('create');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This admin account is disabled. Contact your administrator.');

        $this->provisioner->provision(new Identity('sub-1', 'user@example.com', 'User One', [], false, self::ISSUER));
    }

    /** A matched-by-email admin who was deactivated must be refused before the subject is linked. */
    public function testRejectsDisabledUserMatchedByEmailWithoutLinking(): void
    {
        $disabled = $this->createMock(User::class);
        $disabled->method('getId')->willReturn(7);
        $disabled->method('getData')->willReturnCallback(
            static fn ($field) => $field === 'is_active' ? 0 : null
        );
        $disabled->expects(self::never())->method('setData');

        $this->collectionFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                $this->collectionReturning($this->emptyUser()),
                $this->collectionReturning($disabled)
            );
        $this->userResource->expects(self::never())->method('save');
        $this->userFactory->expects(self::never())->method('create');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This admin account is disabled. Contact your administrator.');

        $this->provisioner->provision(new Identity('sub-1', 'user@example.com', 'User One', [], true, self::ISSUER));
    }

    /**
     * An email match that is already linked to a *different* IdP subject must not
     * be rebound: a second identity sharing the email cannot hijack the account.
     */
    public function testRejectsEmailMatchAlreadyLinkedToAnotherSubject(): void
    {
        $linked = $this->createMock(User::class);
        $linked->method('getId')->willReturn(7);
        $linked->method('getData')->willReturnCallback(
            static fn ($field) => match ($field) {
                'is_active' => 1,
                UserProvisioner::SUBJECT_FIELD => 'sub-OTHER',
                default => null,
            }
        );
        // Adoption is refused before any subject is written.
        $linked->expects(self::never())->method('setData');

        $this->collectionFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                $this->collectionReturning($this->emptyUser()),
                $this->collectionReturning($linked)
            );
        $this->userResource->expects(self::never())->method('save');
        $this->userFactory->expects(self::never())->method('create');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'An admin account with this email is already linked to a different identity.'
        );

        $this->provisioner->provision(new Identity('sub-NEW', 'user@example.com', 'User One', [], true, self::ISSUER));
    }

    /** Subject lookup runs first with the subject column; email lookup uses the normalized email. */
    public function testLooksUpBySubjectThenEmailWithCorrectFields(): void
    {
        $filters = [];
        $makeCollection = function (User $firstItem) use (&$filters) {
            $collection = $this->createStub(Collection::class);
            $collection->method('addFieldToFilter')->willReturnCallback(
                function ($field, $value) use (&$filters, $collection) {
                    $filters[] = [$field, $value];
                    return $collection;
                }
            );
            $collection->method('setPageSize')->willReturnSelf();
            $collection->method('getFirstItem')->willReturn($firstItem);

            return $collection;
        };

        $this->collectionFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                $makeCollection($this->emptyUser()),
                $makeCollection($this->activeUser(7))
            );
        // Email match adopts the local admin (linkSubject), which persists it.
        $this->userResource->expects(self::once())->method('save');
        $this->userFactory->expects(self::never())->method('create');

        // Mixed-case email on the identity must be lower-cased for the lookup.
        $this->provisioner->provision(new Identity('sub-1', 'User@Example.COM', 'User One', [], true, self::ISSUER));

        self::assertSame([UserProvisioner::SUBJECT_FIELD, 'sub-1'], $filters[0]);
        // Exact issuer, or a link stored before issuers were recorded.
        self::assertSame(
            [UserProvisioner::ISSUER_FIELD, [['eq' => self::ISSUER], ['null' => true], ['eq' => '']]],
            $filters[1]
        );
        self::assertSame(['email', 'user@example.com'], $filters[2]);
    }

    /** Names/username longer than the admin_user columns are truncated to their limits. */
    public function testTruncatesLongNameAndUsernameToColumnLimits(): void
    {
        $captured = $this->captureCreatedUserData(
            new Identity(
                'sub-9',
                str_repeat('x', 50) . '@example.com',
                str_repeat('a', 40) . ' ' . str_repeat('b', 40),
                [],
                false,
                self::ISSUER
            )
        );

        self::assertSame(40, mb_strlen($captured['username']));
        self::assertSame(32, mb_strlen($captured['firstname']));
        self::assertSame(32, mb_strlen($captured['lastname']));
    }

    /** Multibyte names are cut on character boundaries, never mid-sequence. */
    public function testTruncatesMultibyteNameOnCharacterBoundary(): void
    {
        // '中' is 3 bytes in UTF-8; a byte-based cut at 32 would split the 11th char.
        $captured = $this->captureCreatedUserData(
            new Identity('sub-9', 'user@example.com', str_repeat('中', 40), [], false, self::ISSUER)
        );

        self::assertSame(32, mb_strlen($captured['firstname']));
        self::assertTrue(mb_check_encoding($captured['firstname'], 'UTF-8'));
    }

    /** With an empty email local part the username falls back to the subject id. */
    public function testUsernameFallsBackToSubjectWhenEmailLocalPartEmpty(): void
    {
        $captured = $this->captureCreatedUserData(
            new Identity('sub-42', '@example.com', 'Jane Doe', [], false, self::ISSUER)
        );

        self::assertSame('sub-42', $captured['username']);
    }

    /**
     * Identity mock with the given verified flag.
     *
     * @return IdentityInterface&\PHPUnit\Framework\MockObject\Stub
     */
    private function identity(string $subject, ?string $email, bool $verified)
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('getSubjectId')->willReturn($subject);
        $identity->method('getIssuer')->willReturn(self::ISSUER);
        $identity->method('getEmail')->willReturn($email);
        $identity->method('getName')->willReturn('User One');
        $identity->method('getGroups')->willReturn([]);
        $identity->method('isEmailVerified')->willReturn($verified);

        return $identity;
    }

    public function testVerifiedEmailMatchIsAdoptedAndLinked(): void
    {
        $existing = $this->createMock(User::class);
        $existing->method('getId')->willReturn(7);
        $existing->method('getData')->willReturnCallback(
            static fn ($field) => $field === 'is_active' ? 1 : null
        );
        $existing->expects(self::exactly(2))->method('setData')->willReturnSelf();

        $this->collectionFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                $this->collectionReturning($this->emptyUser()),
                $this->collectionReturning($existing)
            );
        $this->userResource->expects(self::once())->method('save')->with($existing);
        $this->userFactory->expects(self::never())->method('create');

        self::assertSame(
            $existing,
            $this->provisioner->provision($this->identity('sub-1', 'user@example.com', true))
        );
    }

    public function testUnverifiedEmailMatchIsRefusedWithoutLinking(): void
    {
        $existing = $this->createMock(User::class);
        $existing->method('getId')->willReturn(7);
        $existing->method('getData')->willReturnCallback(
            static fn ($field) => $field === 'is_active' ? 1 : null
        );
        $existing->expects(self::never())->method('setData');

        $this->collectionFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                $this->collectionReturning($this->emptyUser()),
                $this->collectionReturning($existing)
            );
        $this->userResource->expects(self::never())->method('save');
        $this->userFactory->expects(self::never())->method('create');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'An admin account with this email exists, but your identity provider did not verify the email address.'
        );

        $this->provisioner->provision($this->identity('sub-1', 'user@example.com', false));
    }

    public function testUnverifiedEmailWithoutMatchStillCreatesUser(): void
    {
        $captured = $this->captureCreatedUserData($this->identity('sub-9', 'new@example.com', false));

        self::assertSame('new@example.com', $captured['email']);
        self::assertSame('sub-9', $captured[UserProvisioner::SUBJECT_FIELD]);
    }

    public function testSubjectMatchIgnoresVerifiedFlag(): void
    {
        $existing = $this->activeUser(5);

        $this->collectionFactory->expects(self::once())
            ->method('create')
            ->willReturn($this->collectionReturning($existing));
        $this->userResource->expects(self::never())->method('save');
        $this->userFactory->expects(self::never())->method('create');

        self::assertSame(
            $existing,
            $this->provisioner->provision($this->identity('sub-1', 'user@example.com', false))
        );
    }

    /**
     * Drive the create path (both lookups miss) and return the data set on the new user.
     *
     * @return array<string,mixed>
     */
    private function captureCreatedUserData(IdentityInterface $identity): array
    {
        // Subject, email, and username-uniqueness lookups all miss.
        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));

        $newUser = $this->createStub(User::class);
        $this->userFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($newUser, $this->createStub(User::class));
        $this->userResource->expects(self::once())->method('save')->with($newUser);
        $this->random->method('getRandomString')->willReturn('RANDOM');

        $captured = [];
        $newUser->method('setData')->willReturnCallback(
            function ($data) use (&$captured, $newUser) {
                $captured = $data;
                return $newUser;
            }
        );

        $this->provisioner->provision($identity);

        return $captured;
    }
}

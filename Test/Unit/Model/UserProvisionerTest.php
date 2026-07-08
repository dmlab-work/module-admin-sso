<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Test\Unit\Model;

use MageDevGroup\AdminSso\Model\UserProvisioner;
use MageDevGroup\SsoCore\Model\Data\Identity;
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
            static fn ($field) => $field === 'is_active' ? 1 : null
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

        $identity = new Identity('sub-1', 'user@example.com', 'User One', ['admins']);

        self::assertSame($existing, $this->provisioner->provision($identity));
    }

    public function testLinksExistingUserMatchedByEmail(): void
    {
        $existing = $this->createMock(User::class);
        $existing->method('getId')->willReturn(7);
        $existing->method('getData')->willReturnCallback(
            static fn ($field) => $field === 'is_active' ? 1 : null
        );
        // Email collision policy: adopt the local admin and stamp the subject on it.
        $existing->expects(self::once())
            ->method('setData')
            ->with(UserProvisioner::SUBJECT_FIELD, 'sub-1');

        $this->collectionFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                $this->collectionReturning($this->emptyUser()),
                $this->collectionReturning($existing)
            );
        $this->userResource->expects(self::once())->method('save')->with($existing);
        $this->userFactory->expects(self::never())->method('create');

        $identity = new Identity('sub-1', 'User@Example.com', 'User One', []);

        self::assertSame($existing, $this->provisioner->provision($identity));
    }

    public function testCreatesNewUserWhenNoneMatch(): void
    {
        // Subject, email, and username-uniqueness lookups all miss.
        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));

        $newUser = $this->createMock(User::class);
        $this->userFactory->expects(self::once())->method('create')->willReturn($newUser);
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
            ]);
        $this->userResource->expects(self::once())->method('save')->with($newUser);

        $identity = new Identity('sub-9', 'Jane.Doe@Example.com', 'Jane Doe', ['staff']);

        self::assertSame($newUser, $this->provisioner->provision($identity));
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
        $this->userFactory->expects(self::once())->method('create')->willReturn($newUser);
        $this->userResource->expects(self::once())->method('save')->with($newUser);
        $this->random->method('getRandomString')->willReturn('RANDOM');

        $captured = [];
        $newUser->method('setData')->willReturnCallback(
            function ($data) use (&$captured, $newUser) {
                $captured = $data;
                return $newUser;
            }
        );

        $this->provisioner->provision(new Identity('sub-9', 'Jane.Doe@Example.com', 'Jane Doe', []));

        self::assertSame('jane.doe2', $captured['username']);
    }

    public function testCreatesNewUserWithFallbackLastNameForSingleWordName(): void
    {
        // Subject, email, and username-uniqueness lookups all miss.
        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));

        $newUser = $this->createStub(User::class);
        $this->userFactory->expects(self::once())->method('create')->willReturn($newUser);
        $this->random->method('getRandomString')->willReturn('RANDOM');

        $captured = null;
        $newUser->method('setData')->willReturnCallback(
            function ($data) use (&$captured, $newUser) {
                $captured = $data;
                return $newUser;
            }
        );
        $this->userResource->expects(self::once())->method('save')->with($newUser);

        $identity = new Identity('sub-3', 'root@example.com', 'Madonna', []);
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

        $identity = new Identity('sub-x', null, 'No Email', []);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'The identity provider did not release an email address required to create an admin user.'
        );

        $this->provisioner->provision($identity);
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

        $this->provisioner->provision(new Identity('sub-1', 'user@example.com', 'User One', []));
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

        $this->provisioner->provision(new Identity('sub-1', 'user@example.com', 'User One', []));
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

        $this->provisioner->provision(new Identity('sub-NEW', 'user@example.com', 'User One', []));
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
        $this->provisioner->provision(new Identity('sub-1', 'User@Example.COM', 'User One', []));

        self::assertSame([UserProvisioner::SUBJECT_FIELD, 'sub-1'], $filters[0]);
        self::assertSame(['email', 'user@example.com'], $filters[1]);
    }

    /** Names/username longer than the admin_user columns are truncated to their limits. */
    public function testTruncatesLongNameAndUsernameToColumnLimits(): void
    {
        $captured = $this->captureCreatedUserData(
            new Identity(
                'sub-9',
                str_repeat('x', 50) . '@example.com',
                str_repeat('a', 40) . ' ' . str_repeat('b', 40),
                []
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
            new Identity('sub-9', 'user@example.com', str_repeat('中', 40), [])
        );

        self::assertSame(32, mb_strlen($captured['firstname']));
        self::assertTrue(mb_check_encoding($captured['firstname'], 'UTF-8'));
    }

    /** With an empty email local part the username falls back to the subject id. */
    public function testUsernameFallsBackToSubjectWhenEmailLocalPartEmpty(): void
    {
        $captured = $this->captureCreatedUserData(
            new Identity('sub-42', '@example.com', 'Jane Doe', [])
        );

        self::assertSame('sub-42', $captured['username']);
    }

    /**
     * Drive the create path (both lookups miss) and return the data set on the new user.
     *
     * @return array<string,mixed>
     */
    private function captureCreatedUserData(Identity $identity): array
    {
        // Subject, email, and username-uniqueness lookups all miss.
        $this->collectionFactory->expects(self::exactly(3))
            ->method('create')
            ->willReturn($this->collectionReturning($this->emptyUser()));

        $newUser = $this->createStub(User::class);
        $this->userFactory->expects(self::once())->method('create')->willReturn($newUser);
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

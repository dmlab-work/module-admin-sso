<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Model\Session;

use DmLab\AdminSso\Model\Session\IdpSessionStorage;
use DmLab\AdminSso\Test\Unit\Helper\PartialMockTrait;
use Magento\Backend\Model\Auth\Session as AuthSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

// The auth Session is mocked only to back the store with an in-memory array (`setData` is magic).
#[AllowMockObjectsWithoutExpectations]
class IdpSessionStorageTest extends TestCase
{
    use PartialMockTrait;

    /** @var array<string,mixed> */
    private array $store = [];

    /** @var IdpSessionStorage */
    private IdpSessionStorage $storage;

    protected function setUp(): void
    {
        $session = $this->createPartialMockWithReflection(AuthSession::class, ['getData', 'setData']);
        $session->expects($this->any())->method('getData')
            ->willReturnCallback(fn($key) => $this->store[$key] ?? null);
        $session->expects($this->any())->method('setData')
            ->willReturnCallback(function ($key, $value): void {
                $this->store[$key] = $value;
            });

        $this->storage = new IdpSessionStorage($session);
    }

    public function testEmptyWhenNothingSaved(): void
    {
        self::assertNull($this->storage->getIdToken());
        self::assertNull($this->storage->getProviderCode());
    }

    public function testReturnsSavedTokenAndProvider(): void
    {
        $this->storage->save('raw-id-token', 'okta');

        self::assertSame('raw-id-token', $this->storage->getIdToken());
        self::assertSame('okta', $this->storage->getProviderCode());
    }

    public function testIgnoresMalformedEntry(): void
    {
        $this->store['dmlab_admin_sso_idp_session'] = ['id_token' => ['x'], 'provider' => ''];

        self::assertNull($this->storage->getIdToken());
        self::assertNull($this->storage->getProviderCode());
    }
}

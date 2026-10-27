<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Model\Session;

use DmLab\AdminSso\Model\AuthorizationState;
use DmLab\AdminSso\Model\Session\AuthorizationStateStorage;
use DmLab\SsoCore\Api\Data\AuthorizationStateInterface;
use Magento\Backend\Model\Session;
use Magento\Framework\TestFramework\Unit\Helper\MockCreationTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

// The backend Session is mocked only to back the store with an in-memory array;
// its `setData` is a magic method, so a stub cannot express it.
#[AllowMockObjectsWithoutExpectations]
class AuthorizationStateStorageTest extends TestCase
{
    use MockCreationTrait;

    /** @var array<string,mixed> */
    private array $store = [];

    /** @var AuthorizationStateStorage */
    private AuthorizationStateStorage $storage;

    protected function setUp(): void
    {
        $this->store = [];

        $session = $this->createPartialMockWithReflection(Session::class, ['getData', 'setData']);
        $session->expects($this->any())->method('getData')
            ->willReturnCallback(fn($key) => $this->store[$key] ?? null);
        $session->expects($this->any())->method('setData')
            ->willReturnCallback(function ($key, $value): void {
                $this->store[$key] = $value;
            });

        $this->storage = new AuthorizationStateStorage($session);
    }

    private function state(
        string $state,
        string $nonce,
        string $verifier,
        string $issuer = 'https://idp.example'
    ): AuthorizationStateInterface {
        return new AuthorizationState($state, $nonce, $verifier, $issuer);
    }

    public function testSavedStateIsConsumedOnce(): void
    {
        $this->storage->save($this->state('s1', 'n1', 'v1'));

        $loaded = $this->storage->consume('s1');

        self::assertNotNull($loaded);
        self::assertSame('s1', $loaded->getState());
        self::assertSame('n1', $loaded->getNonce());
        self::assertSame('v1', $loaded->getCodeVerifier());
        self::assertSame('https://idp.example', $loaded->getIssuer());

        // Replay protection: a second consume returns null.
        self::assertNull($this->storage->consume('s1'));
    }

    public function testEntryWithoutIssuerRestoresEmptyIssuer(): void
    {
        $this->store['dmlab_admin_sso_auth_states'] = ['s1' => ['nonce' => 'n1', 'code_verifier' => 'v1']];

        self::assertSame('', $this->storage->consume('s1')?->getIssuer());
    }

    public function testConsumeUnknownStateReturnsNull(): void
    {
        self::assertNull($this->storage->consume('missing'));
    }

    public function testConcurrentStatesDoNotClobberEachOther(): void
    {
        $this->storage->save($this->state('s1', 'n1', 'v1'));
        $this->storage->save($this->state('s2', 'n2', 'v2'));

        $second = $this->storage->consume('s2');
        self::assertNotNull($second);
        self::assertSame('n2', $second->getNonce());

        // Consuming s2 leaves s1 intact.
        $first = $this->storage->consume('s1');
        self::assertNotNull($first);
        self::assertSame('v1', $first->getCodeVerifier());
    }
}

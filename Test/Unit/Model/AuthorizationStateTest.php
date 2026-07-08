<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\AdminSso\Test\Unit\Model;

use MageDevGroup\AdminSso\Model\AuthorizationState;
use MageDevGroup\SsoCore\Api\Data\AuthorizationStateInterface;
use PHPUnit\Framework\TestCase;

class AuthorizationStateTest extends TestCase
{
    public function testExposesItsValues(): void
    {
        $state = new AuthorizationState('the-state', 'the-nonce', 'the-verifier');

        self::assertInstanceOf(AuthorizationStateInterface::class, $state);
        self::assertSame('the-state', $state->getState());
        self::assertSame('the-nonce', $state->getNonce());
        self::assertSame('the-verifier', $state->getCodeVerifier());
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Test\Unit\Model;

use DmLab\AdminSso\Model\AuthorizationState;
use DmLab\SsoCore\Api\Data\AuthorizationStateInterface;
use PHPUnit\Framework\TestCase;

class AuthorizationStateTest extends TestCase
{
    public function testExposesItsValues(): void
    {
        $state = new AuthorizationState('the-state', 'the-nonce', 'the-verifier', 'https://idp.example');

        self::assertInstanceOf(AuthorizationStateInterface::class, $state);
        self::assertSame('the-state', $state->getState());
        self::assertSame('the-nonce', $state->getNonce());
        self::assertSame('the-verifier', $state->getCodeVerifier());
        self::assertSame('https://idp.example', $state->getIssuer());
    }

    public function testIssuerDefaultsToEmpty(): void
    {
        self::assertSame('', (new AuthorizationState('s', 'n', 'v'))->getIssuer());
    }
}

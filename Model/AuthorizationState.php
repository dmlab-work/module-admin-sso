<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Model;

use DmLab\SsoCore\Api\Data\AuthorizationStateInterface;

/**
 * Plain value object carrying the one-time authorization state reloaded from the
 * session on the callback. Unlike sso-core's {@see AuthorizationRequest} it holds
 * no redirect URL — only the `state`/`nonce`/PKCE verifier/issuer the callback needs.
 */
class AuthorizationState implements AuthorizationStateInterface
{
    /**
     * @param string $state
     * @param string $nonce
     * @param string $codeVerifier
     * @param string $issuer
     */
    public function __construct(
        private readonly string $state,
        private readonly string $nonce,
        private readonly string $codeVerifier,
        private readonly string $issuer = ''
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * @inheritDoc
     */
    public function getNonce(): string
    {
        return $this->nonce;
    }

    /**
     * @inheritDoc
     */
    public function getCodeVerifier(): string
    {
        return $this->codeVerifier;
    }

    /**
     * @inheritDoc
     */
    public function getIssuer(): string
    {
        return $this->issuer;
    }
}

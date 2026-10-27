<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Model\Oidc;

use DmLab\SsoCore\Api\Data\IdentityInterface;

/**
 * Outcome of a completed OIDC callback: the normalized identity plus the validated
 * ID-token claims it was built from (e.g. `amr`/`acr` for the 2FA decision).
 */
class CallbackResult
{
    /**
     * @param IdentityInterface $identity
     * @param array<string,mixed> $claims validated ID-token claims
     */
    public function __construct(
        private readonly IdentityInterface $identity,
        private readonly array $claims
    ) {
    }

    /**
     * Verified, provider-agnostic identity.
     */
    public function getIdentity(): IdentityInterface
    {
        return $this->identity;
    }

    /**
     * Validated ID-token claims.
     *
     * @return array<string,mixed>
     */
    public function getClaims(): array
    {
        return $this->claims;
    }
}

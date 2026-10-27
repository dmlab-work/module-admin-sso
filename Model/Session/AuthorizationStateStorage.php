<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSso\Model\Session;

use DmLab\AdminSso\Model\AuthorizationState;
use DmLab\SsoCore\Api\AuthorizationStateStorageInterface;
use DmLab\SsoCore\Api\Data\AuthorizationStateInterface;
use Magento\Backend\Model\Session;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Backend-session-backed store for the per-request OIDC authorization state.
 *
 * The start controller {@see save}s the state (keyed by its `state` token) before
 * redirecting to the IdP; the callback controller {@see consume}s it once. Entries
 * are held in the admin area session — available before login — under a single
 * session key so several in-flight logins from one browser don't clobber each
 * other. Consuming an entry removes it, giving replay protection. Entries expire after
 * {@see self::TTL} seconds and at most {@see self::MAX_PENDING} are kept (newest win), so
 * abandoned logins cannot grow the session.
 */
class AuthorizationStateStorage implements AuthorizationStateStorageInterface
{
    /** Session key holding the map of pending states, keyed by `state` token. */
    private const SESSION_KEY = 'dmlab_admin_sso_auth_states';

    /** Seconds a pending state stays valid. */
    public const TTL = 600;

    /** Pending states kept per session. */
    public const MAX_PENDING = 10;

    /**
     * @param Session $session
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly Session $session,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @inheritDoc
     */
    public function save(AuthorizationStateInterface $state): void
    {
        $now = $this->now();
        $states = $this->pruneExpired($this->load(), $now);
        unset($states[$state->getState()]);
        $states[$state->getState()] = [
            'nonce' => $state->getNonce(),
            'code_verifier' => $state->getCodeVerifier(),
            'issuer' => $state->getIssuer(),
            'created_at' => $now,
        ];
        $this->session->setData(self::SESSION_KEY, array_slice($states, -self::MAX_PENDING, null, true));
    }

    /**
     * @inheritDoc
     */
    public function consume(string $state): ?AuthorizationStateInterface
    {
        $states = $this->pruneExpired($this->load(), $this->now());
        $entry = $states[$state] ?? null;
        unset($states[$state]);
        $this->session->setData(self::SESSION_KEY, $states);
        if (!is_array($entry)) {
            return null;
        }

        return new AuthorizationState(
            $state,
            (string)($entry['nonce'] ?? ''),
            (string)($entry['code_verifier'] ?? ''),
            (string)($entry['issuer'] ?? '')
        );
    }

    /**
     * Drop entries older than the TTL; entries without a timestamp count as expired.
     *
     * @param array<string,mixed> $states
     * @param int $now
     * @return array<string,mixed>
     */
    private function pruneExpired(array $states, int $now): array
    {
        return array_filter(
            $states,
            static fn ($entry) => is_array($entry)
                && isset($entry['created_at'])
                && (int)$entry['created_at'] + self::TTL >= $now
        );
    }

    /**
     * Current UTC timestamp.
     */
    private function now(): int
    {
        return (int)$this->dateTime->gmtTimestamp();
    }

    /**
     * Load the pending-state map from the session.
     *
     * @return array<string,array{nonce:string,code_verifier:string,issuer?:string,created_at?:int}>
     */
    private function load(): array
    {
        $states = $this->session->getData(self::SESSION_KEY);

        return is_array($states) ? $states : [];
    }
}

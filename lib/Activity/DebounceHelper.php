<?php

/**
 * DebounceHelper
 *
 * APCu-backed debounce guard used by the Activity Feed Integration
 * capability (REQ-ACT-007, REQ-ACT-008). Two windows are exposed:
 *
 * - `allowReaction(actor, dashboard)` — at most one reaction per actor
 *   per dashboard per 900-second window.
 * - `allowGlobalFanout(dashboard, eventType)` — at most one default-group
 *   fan-out per dashboard per event type per 900-second window.
 *
 * The class falls back to a per-process in-memory store when APCu is not
 * available (e.g. CLI or test environments) so call sites do not need to
 * branch on runtime availability. The in-memory store still honours the
 * 900-second TTL for deterministic unit tests.
 *
 * @category  Activity
 * @package   OCA\MyDash\Activity
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Activity;

/**
 * Per-window debounce guard for Activity emission.
 */
class DebounceHelper
{
    /**
     * Debounce TTL in seconds (15 minutes). REQ-ACT-007, REQ-ACT-008.
     */
    public const TTL_SECONDS = 900;

    /**
     * In-memory fallback store used when APCu is not available.
     *
     * Keyed by the same APCu key string; the value is the unix
     * timestamp at which the entry expires.
     *
     * @var array<string, int>
     */
    private array $memory = [];

    /**
     * Optional clock callable returning the current unix timestamp.
     *
     * Injected by tests to advance time deterministically without
     * sleeping for 15 minutes.
     *
     * @var callable():int
     */
    private $clock;

    /**
     * True when the helper is using the real wall-clock and may delegate
     * to APCu. False when a test clock is injected — in that case APCu's
     * own TTL would not move with the test clock, so the in-memory
     * fallback is the only correct backend.
     *
     * @var boolean
     */
    private bool $realClock;

    /**
     * Constructor.
     *
     * @param (callable():int)|null $clock Optional clock callable; defaults to `time()`.
     */
    public function __construct(?callable $clock=null)
    {
        $this->realClock = ($clock === null);
        $this->clock     = ($clock ?? static fn(): int => time());
    }//end __construct()

    /**
     * Check whether a reaction event from `$actorUserId` on
     * `$dashboardUuid` may be emitted now.
     *
     * Returns true on the first call and again after the 900-second
     * window has elapsed; returns false for any call inside an active
     * window.
     *
     * @param string $actorUserId   The acting user ID.
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return bool True when emission is allowed.
     */
    public function allowReaction(
        string $actorUserId,
        string $dashboardUuid
    ): bool {
        $key = sprintf(
            'mydash_act_react_%s_%s',
            $actorUserId,
            $dashboardUuid
        );
        return $this->claim(key: $key);
    }//end allowReaction()

    /**
     * Check whether a default-group fan-out for `(dashboardUuid, eventType)`
     * may be performed now (REQ-ACT-008).
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $eventType     The event type constant value.
     *
     * @return bool True when fan-out is allowed.
     */
    public function allowGlobalFanout(
        string $dashboardUuid,
        string $eventType
    ): bool {
        $key = sprintf(
            'mydash_act_global_%s_%s',
            $dashboardUuid,
            $eventType
        );
        return $this->claim(key: $key);
    }//end allowGlobalFanout()

    /**
     * Atomically claim the key for the configured TTL.
     *
     * Uses APCu when available (with `apcu_add` for race-free claim)
     * and falls back to the in-memory map otherwise.
     *
     * @param string $key The full APCu key.
     *
     * @return bool True when the caller successfully claimed the window.
     */
    private function claim(string $key): bool
    {
        $now = ($this->clock)();

        if ($this->apcuUsable() === true) {
            // `apcu_add` returns false when the key already exists,
            // which is exactly the semantics we want for a debounce
            // claim. The TTL is enforced by APCu itself.
            return (bool) apcu_add($key, $now, self::TTL_SECONDS);
        }

        // Purge expired entries opportunistically to keep the in-memory
        // store from growing without bound.
        foreach ($this->memory as $existingKey => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->memory[$existingKey]);
            }
        }

        if (array_key_exists(key: $key, array: $this->memory) === true) {
            return false;
        }

        $this->memory[$key] = ($now + self::TTL_SECONDS);
        return true;
    }//end claim()

    /**
     * True when APCu is actually usable for debounce claims.
     *
     * `function_exists('apcu_add')` alone is not enough — the function
     * is loaded by the extension even when APCu is disabled at runtime
     * (most notably under CLI when `apc.enable_cli=0`), in which case
     * `apcu_add()` silently returns false on every call. That breaks
     * the debounce semantics: the helper would treat every claim as
     * "already taken" and reject every emission.
     *
     * `apcu_enabled()` was introduced in APCu 4.0.5 specifically for
     * this gate; when it's available we trust it. When it isn't (very
     * old APCu builds), fall back to the existence check + an
     * `ini_get('apc.enabled')` probe.
     *
     * @return bool True when APCu is loaded AND enabled at runtime.
     */
    private function apcuUsable(): bool
    {
        // A test-injected clock cannot move APCu's wall-clock TTL, so
        // the in-memory store is the only backend that produces
        // deterministic results when the clock is fake.
        if ($this->realClock === false) {
            return false;
        }

        if (function_exists(function: 'apcu_add') === false || function_exists(function: 'apcu_exists') === false) {
            return false;
        }

        if (function_exists(function: 'apcu_enabled') === true) {
            return (bool) apcu_enabled();
        }

        return (bool) ini_get(option: 'apc.enabled');
    }//end apcuUsable()
}//end class

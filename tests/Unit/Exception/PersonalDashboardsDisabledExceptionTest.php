<?php

/**
 * PersonalDashboardsDisabledExceptionTest
 *
 * Pins the exception's stable error envelope (REQ-ASET-003 extended).
 * Downstream agents — frontend toast, REST clients, OpenAPI codegen —
 * match on these exact strings, so renaming any of them is a breaking
 * spec change.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Exception
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Exception;

use OCA\MyDash\Exception\PersonalDashboardsDisabledException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see PersonalDashboardsDisabledException}.
 */
class PersonalDashboardsDisabledExceptionTest extends TestCase
{

    /**
     * The stable error code MUST be `personal_dashboards_disabled` —
     * downstream agents pattern-match on this exact value.
     *
     * @return void
     */
    public function testStableErrorCodeIsPinned(): void
    {
        $exception = new PersonalDashboardsDisabledException();
        $this->assertSame(
            'personal_dashboards_disabled',
            $exception->getErrorCode()
        );
    }//end testStableErrorCodeIsPinned()

    /**
     * HTTP status MUST be 403 (Forbidden) — REQ-ASET-003 envelope.
     *
     * @return void
     */
    public function testHttpStatusIs403(): void
    {
        $exception = new PersonalDashboardsDisabledException();
        $this->assertSame(403, $exception->getHttpStatus());
    }//end testHttpStatusIs403()

    /**
     * The default English message MUST match the translatable source
     * string in `l10n/en.json`. Renaming this string would orphan
     * existing translations.
     *
     * @return void
     */
    public function testDefaultMessageMatchesTranslationSource(): void
    {
        $exception = new PersonalDashboardsDisabledException();
        $this->assertSame(
            'Personal dashboards are not enabled by your administrator',
            $exception->getMessage()
        );
    }//end testDefaultMessageMatchesTranslationSource()

    /**
     * The constructor MUST allow callers to override the message so a
     * controller can swap in a localised string when richer context is
     * available.
     *
     * @return void
     */
    public function testConstructorAcceptsCustomMessage(): void
    {
        $exception = new PersonalDashboardsDisabledException(
            message: 'Custom message'
        );
        $this->assertSame('Custom message', $exception->getMessage());
        // Stable code MUST remain intact regardless of the message.
        $this->assertSame(
            'personal_dashboards_disabled',
            $exception->getErrorCode()
        );
    }//end testConstructorAcceptsCustomMessage()

    /**
     * The display message MUST equal the constructor message so callers
     * can use either accessor.
     *
     * @return void
     */
    public function testDisplayMessageMatchesMessage(): void
    {
        $exception = new PersonalDashboardsDisabledException();
        $this->assertSame(
            $exception->getMessage(),
            $exception->getDisplayMessage()
        );
    }//end testDisplayMessageMatchesMessage()
}//end class

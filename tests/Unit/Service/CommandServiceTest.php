<?php

/**
 * CommandServiceTest
 *
 * REQ-CLI-006 / REQ-CLI-007 / REQ-CLI-010 — verify the exit-code
 * constants are distinct, the JSON envelope conforms to the documented
 * schema, and the audit-log line uses the `[mydash] cli ...` format.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\MyDash\Service\CommandService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for {@see CommandService}.
 */
class CommandServiceTest extends TestCase
{
    /**
     * REQ-CLI-006 — the six exit-code constants MUST be distinct
     * non-negative integers covering 0..5.
     *
     * @return void
     */
    public function testExitCodeConstantsAreDistinctAndCoverContract(): void
    {
        $codes = [
            CommandService::EXIT_SUCCESS,
            CommandService::EXIT_ERROR,
            CommandService::EXIT_INVALID_ARGS,
            CommandService::EXIT_PERMISSION_DENIED,
            CommandService::EXIT_NOT_FOUND,
            CommandService::EXIT_PARTIAL_SUCCESS,
        ];

        $this->assertCount(expectedCount: 6, haystack: array_unique(array: $codes));
        $this->assertSame(expected: [0, 1, 2, 3, 4, 5], actual: $codes);
    }//end testExitCodeConstantsAreDistinctAndCoverContract()

    /**
     * REQ-CLI-007 — successful envelope MUST have `success=true`,
     * `exitCode=0`, an empty `errors` array, and the supplied data.
     *
     * @return void
     */
    public function testEnvelopeSuccessShape(): void
    {
        $service  = new CommandService(logger: $this->createMock(originalClassName: LoggerInterface::class));
        $envelope = $service->envelopeSuccess(data: ['count' => 3]);

        $this->assertTrue(condition: $envelope['success']);
        $this->assertSame(expected: 0, actual: $envelope['exitCode']);
        $this->assertSame(expected: ['count' => 3], actual: $envelope['data']);
        $this->assertSame(expected: [], actual: $envelope['errors']);
    }//end testEnvelopeSuccessShape()

    /**
     * REQ-CLI-007 — error envelope MUST have `success=false`,
     * `data=null`, and a single error object with `code`, `message`,
     * and optional `context` fields.
     *
     * @return void
     */
    public function testEnvelopeErrorShape(): void
    {
        $service  = new CommandService(logger: $this->createMock(originalClassName: LoggerInterface::class));
        $envelope = $service->envelopeError(
            exitCode: CommandService::EXIT_NOT_FOUND,
            code: 'NOT_FOUND',
            message: 'User not found',
            context: ['userId' => 'alice']
        );

        $this->assertFalse(condition: $envelope['success']);
        $this->assertSame(expected: 4, actual: $envelope['exitCode']);
        $this->assertNull(actual: $envelope['data']);
        $this->assertCount(expectedCount: 1, haystack: $envelope['errors']);
        $this->assertSame(expected: 'NOT_FOUND', actual: $envelope['errors'][0]['code']);
        $this->assertSame(expected: 'User not found', actual: $envelope['errors'][0]['message']);
        $this->assertSame(expected: ['userId' => 'alice'], actual: $envelope['errors'][0]['context']);
    }//end testEnvelopeErrorShape()

    /**
     * REQ-CLI-007 — encoded envelope MUST be valid JSON parsable by
     * `json_decode`.
     *
     * @return void
     */
    public function testEncodeEnvelopeProducesValidJson(): void
    {
        $service = new CommandService(logger: $this->createMock(originalClassName: LoggerInterface::class));
        $encoded = $service->encodeEnvelope(envelope: $service->envelopeSuccess(data: ['ok' => true]));

        $decoded = json_decode(json: $encoded, associative: true);
        $this->assertIsArray(actual: $decoded);
        $this->assertTrue(condition: $decoded['success']);
        $this->assertSame(expected: 0, actual: $decoded['exitCode']);
    }//end testEncodeEnvelopeProducesValidJson()

    /**
     * REQ-CLI-010 — audit line MUST follow the documented format and
     * default `byUser` to `cli` when no session uid is supplied.
     *
     * @return void
     */
    public function testFormatAuditLineUsesContractFormat(): void
    {
        $service = new CommandService(logger: $this->createMock(originalClassName: LoggerInterface::class));
        $line    = $service->formatAuditLine(
            command: 'dashboard:list',
            args: '--user=alice --status=published',
            exitCode: 0,
            durationMs: 42,
            byUser: null
        );

        $this->assertSame(
            expected: '[mydash] cli dashboard:list --user=alice --status=published exitCode=0 durationMs=42 byUser=cli',
            actual: $line
        );
    }//end testFormatAuditLineUsesContractFormat()

    /**
     * REQ-CLI-010 — args longer than {@see CommandService::AUDIT_ARGS_MAX}
     * MUST be truncated and end with the `...` sentinel.
     *
     * @return void
     */
    public function testFormatAuditLineTruncatesLongArgs(): void
    {
        $service  = new CommandService(logger: $this->createMock(originalClassName: LoggerInterface::class));
        $longArgs = str_repeat(string: 'x', times: 250);
        $line     = $service->formatAuditLine(
            command: 'import',
            args: $longArgs,
            exitCode: 0,
            durationMs: 1,
            byUser: 'admin'
        );

        $this->assertStringContainsString(needle: '...', haystack: $line);
        // Total args field is 100 chars including the `...` suffix; full
        // line MUST therefore be shorter than the original arg-only
        // length plus the surrounding tokens.
        $this->assertLessThan(expected: 200, actual: mb_strlen(string: $line));
    }//end testFormatAuditLineTruncatesLongArgs()

    /**
     * REQ-CLI-010 — `audit()` MUST emit exactly one `info`-level log
     * call carrying the `mydash` app context.
     *
     * @return void
     */
    public function testAuditEmitsSingleInfoLine(): void
    {
        $logger = $this->createMock(originalClassName: LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('[mydash] cli dashboard:list'),
                $this->callback(static function (array $context): bool {
                    return ($context['app'] ?? '') === 'mydash'
                        && ($context['command'] ?? '') === 'dashboard:list'
                        && ($context['exitCode'] ?? -1) === 0;
                })
            );

        $service = new CommandService(logger: $logger);
        $service->audit(
            command: 'dashboard:list',
            args: '--user=alice',
            exitCode: 0,
            durationMs: 5,
            byUser: 'admin'
        );
    }//end testAuditEmitsSingleInfoLine()
}//end class

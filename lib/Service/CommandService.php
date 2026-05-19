<?php

/**
 * CommandService
 *
 * Shared CLI infrastructure (REQ-CLI-006, REQ-CLI-007, REQ-CLI-010).
 * Defines the standard MyDash exit-code contract, the JSON envelope
 * schema, and the audit-log format that every `mydash:*` command MUST
 * use to remain interoperable with operator tooling.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
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

namespace OCA\MyDash\Service;

use Psr\Log\LoggerInterface;

/**
 * Shared CLI helpers — exit-code constants, JSON envelope, audit log.
 *
 * The class is intentionally stateless: it holds only the platform
 * logger and exposes pure helpers so individual commands stay thin.
 */
class CommandService
{
    /**
     * Exit code for successful execution (REQ-CLI-006).
     *
     * @var integer
     */
    public const EXIT_SUCCESS = 0;

    /**
     * Exit code for unhandled exceptions / generic errors (REQ-CLI-006).
     *
     * @var integer
     */
    public const EXIT_ERROR = 1;

    /**
     * Exit code for invalid / malformed CLI arguments (REQ-CLI-006).
     *
     * @var integer
     */
    public const EXIT_INVALID_ARGS = 2;

    /**
     * Exit code for permission / authorization failures (REQ-CLI-006).
     *
     * @var integer
     */
    public const EXIT_PERMISSION_DENIED = 3;

    /**
     * Exit code for "resource not found" lookups (REQ-CLI-006).
     *
     * @var integer
     */
    public const EXIT_NOT_FOUND = 4;

    /**
     * Exit code for batch operations that partially succeeded
     * (REQ-CLI-006).
     *
     * @var integer
     */
    public const EXIT_PARTIAL_SUCCESS = 5;

    /**
     * Maximum number of characters of the args string written to the
     * audit log line (REQ-CLI-010).
     *
     * @var integer
     */
    public const AUDIT_ARGS_MAX = 100;

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Platform logger used for audit
     *                                lines (REQ-CLI-010).
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }//end __construct()

    /**
     * Build a successful JSON envelope (REQ-CLI-007).
     *
     * @param array<string,mixed>|list<mixed>|null $data Command payload.
     *
     * @return array<string,mixed>
     */
    public function envelopeSuccess(array|null $data): array
    {
        return [
            'success'  => true,
            'exitCode' => self::EXIT_SUCCESS,
            'data'     => $data,
            'errors'   => [],
        ];
    }//end envelopeSuccess()

    /**
     * Build a partial-success JSON envelope (REQ-CLI-007).
     *
     * @param array<string,mixed>|list<mixed>|null $data   Payload.
     * @param list<array<string,mixed>>            $errors Per-item errors.
     *
     * @return array<string,mixed>
     */
    public function envelopePartial(array|null $data, array $errors): array
    {
        return [
            'success'  => false,
            'exitCode' => self::EXIT_PARTIAL_SUCCESS,
            'data'     => $data,
            'errors'   => $errors,
        ];
    }//end envelopePartial()

    /**
     * Build an error JSON envelope (REQ-CLI-007).
     *
     * @param int                      $exitCode Command exit code.
     * @param string                   $code     Stable error identifier.
     * @param string                   $message  Human-readable text.
     * @param array<string,mixed>|null $context  Optional metadata.
     *
     * @return array<string,mixed>
     */
    public function envelopeError(
        int $exitCode,
        string $code,
        string $message,
        array|null $context=null
    ): array {
        $error = [
            'code'    => $code,
            'message' => $message,
        ];
        if ($context !== null) {
            $error['context'] = $context;
        }

        return [
            'success'  => false,
            'exitCode' => $exitCode,
            'data'     => null,
            'errors'   => [$error],
        ];
    }//end envelopeError()

    /**
     * Encode an envelope to a single JSON line (REQ-CLI-007).
     *
     * @param array<string,mixed> $envelope The envelope to encode.
     *
     * @return string
     */
    public function encodeEnvelope(array $envelope): string
    {
        return (string) json_encode(
            value: $envelope,
            flags: (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }//end encodeEnvelope()

    /**
     * Format an audit-log line per REQ-CLI-010.
     *
     * Format: `[mydash] cli <command> <args> exitCode=<n>
     * durationMs=<ms> byUser=<uid|cli>`
     *
     * @param string      $command    Full command name without the
     *                                `mydash:` prefix (e.g.
     *                                `dashboard:list`).
     * @param string      $args       Space-joined argv tail. May be
     *                                truncated to {@see AUDIT_ARGS_MAX}.
     * @param int         $exitCode   Final exit code.
     * @param int         $durationMs Wall-clock execution time.
     * @param string|null $byUser     Caller user id or null for `cli`.
     *
     * @return string
     */
    public function formatAuditLine(
        string $command,
        string $args,
        int $exitCode,
        int $durationMs,
        string|null $byUser
    ): string {
        $argsClean = trim(string: $args);
        if (mb_strlen(string: $argsClean) > self::AUDIT_ARGS_MAX) {
            $argsClean = mb_substr(
                string: $argsClean,
                start: 0,
                length: (self::AUDIT_ARGS_MAX - 3)
            ).'...';
        }

        $user = $byUser;
        if ($user === null || $user === '') {
            $user = 'cli';
        }

        return sprintf(
            '[mydash] cli %s %s exitCode=%d durationMs=%d byUser=%s',
            $command,
            $argsClean,
            $exitCode,
            $durationMs,
            $user
        );
    }//end formatAuditLine()

    /**
     * Write the audit-log line to the platform logger (REQ-CLI-010).
     *
     * @param string      $command    Command name.
     * @param string      $args       Argument string.
     * @param int         $exitCode   Exit code.
     * @param int         $durationMs Wall-clock duration.
     * @param string|null $byUser     Caller user id.
     *
     * @return void
     */
    public function audit(
        string $command,
        string $args,
        int $exitCode,
        int $durationMs,
        string|null $byUser
    ): void {
        $this->logger->info(
            message: $this->formatAuditLine(
                command: $command,
                args: $args,
                exitCode: $exitCode,
                durationMs: $durationMs,
                byUser: $byUser
            ),
            context: [
                'app'        => 'mydash',
                'command'    => $command,
                'exitCode'   => $exitCode,
                'durationMs' => $durationMs,
                'byUser'     => ($byUser ?? 'cli'),
            ]
        );
    }//end audit()
}//end class

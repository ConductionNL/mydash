<?php
/**
 * UrlSafetyValidator — shared SSRF guard for outbound HTTP fetches.
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

use OCP\IAppConfig;

/**
 * Shared URL safety / SSRF guard for outbound HTTP fetches.
 *
 * Rules enforced (all must pass):
 *  1. Scheme MUST be https (no plain-text HTTP, no other schemes).
 *  2. Host MUST be present and non-empty.
 *  3. Every resolved IP MUST pass FILTER_FLAG_NO_PRIV_RANGE |
 *     FILTER_FLAG_NO_RES_RANGE.
 *
 * @spec openspec/specs/news-widget/spec.md
 * @spec openspec/specs/calendar-widget/spec.md
 */
class UrlSafetyValidator
{
    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig App configuration (used by checkAllowList).
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Validate an outbound URL against SSRF rules.
     *
     * Accepts only HTTPS URLs whose hostname resolves exclusively to
     * public IPs (no private/reserved/loopback ranges).
     *
     * @param string $url The URL to validate.
     *
     * @return bool True when the URL passes all checks.
     */
    public function isSafe(string $url): bool
    {
        $parts = parse_url(url: $url);
        if (is_array(value: $parts) === false) {
            return false;
        }

        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }

        $ips = gethostbynamel(hostname: $host);
        if ($ips === false || $ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            $publicIp = filter_var(
                value: $ip,
                filter: FILTER_VALIDATE_IP,
                options: (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            );
            if ($publicIp === false) {
                return false;
            }
        }

        return true;
    }//end isSafe()

    /**
     * Check whether the URL host is permitted by an admin allow-list
     * stored in IAppConfig.
     *
     * An empty / missing list means ALL hosts are allowed (open policy).
     * When the list is non-empty the host MUST appear in it (exact,
     * case-insensitive; no wildcard subdomain expansion).
     *
     * @param string $url       The URL to check.
     * @param string $appId     The app whose config key to read.
     * @param string $configKey The IAppConfig key holding the JSON
     *                          array of allowed hostnames.
     *
     * @return bool True when the host passes the allow-list check.
     */
    public function checkAllowList(string $url, string $appId, string $configKey): bool
    {
        $raw = $this->appConfig->getValueString(
            app: $appId,
            key: $configKey,
            default: ''
        );

        if (trim(string: $raw) === '') {
            return true;
        }

        $decoded = json_decode(json: $raw, associative: true);
        if (is_array(value: $decoded) === false || $decoded === []) {
            return true;
        }

        $host = parse_url(url: $url, component: PHP_URL_HOST);
        if (is_string(value: $host) === false || $host === '') {
            return false;
        }

        $needle = strtolower(string: $host);
        foreach ($decoded as $allowed) {
            if (is_string(value: $allowed) === true
                && strtolower(string: $allowed) === $needle
            ) {
                return true;
            }
        }

        return false;
    }//end checkAllowList()
}//end class

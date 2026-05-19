<?php

/**
 * FooterService
 *
 * Resolves the global instance footer + per-dashboard overrides for
 * the footer-customization capability. Owns the shared HTML
 * sanitisation allow-list (REQ-FTR-005, design D4) and the structured
 * config schema validator (REQ-FTR-003).
 *
 * Storage:
 *  - Global settings live in `mydash_admin_settings` under five keys
 *    on {@see \OCA\MyDash\Db\AdminSetting} (`KEY_FOOTER_*`).
 *  - Per-dashboard override lives on the {@see \OCA\MyDash\Db\Dashboard}
 *    entity (`dashboardFooterMode`, `dashboardFooterHtml`).
 *
 * Public surface:
 *  - {@see FooterService::getGlobalSettings()} — read settings (returns
 *    camelCase array suitable for the `GET /api/admin/footer-settings`
 *    response).
 *  - {@see FooterService::updateGlobalSettings()} — patch settings,
 *    sanitises HTML / validates structured config / validates colour
 *    hex strings before persisting.
 *  - {@see FooterService::sanitiseHtml()} — public so
 *    `DashboardService` (and tests) can apply identical sanitisation
 *    to the per-dashboard `dashboardFooterHtml` field.
 *  - {@see FooterService::resolveFooterForDashboard()} — produces the
 *    `effectiveFooter` payload for a dashboard's API response.
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

use InvalidArgumentException;
use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Db\Dashboard;

/**
 * Resolve the instance footer + per-dashboard overrides.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *      Sanitiser + structured-config validator + colour validator +
 *      resolution all live here intentionally so the footer surface
 *      has one canonical implementation.
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 *      `sanitiseHtml`, `updateGlobalSettings`, and
 *      `resolveFooterForDashboard` all need explicit branching for
 *      each schema-validated input — splitting hides the contract.
 * @SuppressWarnings(PHPMD.NPathComplexity)
 *      Same — every conditional in the sanitiser is documented in
 *      REQ-FTR-005 and the test suite covers each branch.
 */
class FooterService
{
    /**
     * Maximum allowed footer HTML length (REQ-FTR-002 — 8 KB cap).
     * Inputs that exceed this MUST be rejected with HTTP 413 by the
     * controller — `sanitiseHtml()` throws
     * {@see \InvalidArgumentException} so the caller can map it.
     *
     * @var integer
     */
    public const MAX_HTML_BYTES = 8192;

    /**
     * Tag allow-list for the footer HTML (REQ-FTR-005, design D4).
     * Mirrors the text-display widget's allow-list — a single
     * canonical definition that both surfaces SHOULD reference.
     *
     * @var array<int, string>
     */
    public const ALLOWED_TAGS = [
        'a',
        'p',
        'strong',
        'em',
        'br',
        'ul',
        'ol',
        'li',
        'img',
    ];

    /**
     * Per-tag attribute allow-list (REQ-FTR-005). Tags not listed in
     * {@see FooterService::ALLOWED_TAGS} are stripped wholesale; tags
     * listed but missing from this map keep no attributes.
     *
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_ATTRIBUTES = [
        'a'   => ['href'],
        'img' => ['src'],
    ];

    /**
     * Allowed structured-config top-level keys (REQ-FTR-003). Schema
     * payloads with extra keys MUST be rejected with HTTP 400 — the
     * service throws {@see \InvalidArgumentException}.
     *
     * @var array<int, string>
     */
    public const STRUCTURED_CONFIG_KEYS = [
        'logoUrl',
        'organisation',
        'address',
        'links',
        'legal',
        'copyrightYear',
        'layoutMode',
    ];

    /**
     * Allowed structured layout modes (REQ-FTR-003).
     *
     * @var array<int, string>
     */
    public const LAYOUT_MODES = ['columns', 'inline'];

    /**
     * Constructor.
     *
     * @param AdminSettingMapper $settingMapper Persisted-settings mapper.
     */
    public function __construct(
        private readonly AdminSettingMapper $settingMapper,
    ) {
    }//end __construct()

    /**
     * Read the five footer settings into a camelCase response payload.
     *
     * Defaults:
     *  - `footerEnabled` — false (REQ-FTR-001 default-off scenario).
     *  - `footerHtml` — empty string.
     *  - `footerConfig` — empty stdClass-like array (`[]`).
     *  - `footerBackgroundColor` / `footerTextColor` — null (theme
     *    fallback).
     *
     * @return array<string, mixed> Settings keyed by camelCase
     *                              (`footerEnabled`, `footerHtml`,
     *                              `footerConfig`,
     *                              `footerBackgroundColor`,
     *                              `footerTextColor`).
     */
    public function getGlobalSettings(): array
    {
        $enabled = (bool) $this->settingMapper->getValue(
            key: AdminSetting::KEY_FOOTER_ENABLED,
            default: false
        );

        $html = $this->settingMapper->getValue(
            key: AdminSetting::KEY_FOOTER_HTML,
            default: ''
        );

        if (is_string($html) === false && is_array($html) === false) {
            $html = '';
        }

        $config = $this->settingMapper->getValue(
            key: AdminSetting::KEY_FOOTER_CONFIG,
            default: []
        );

        if (is_array($config) === false) {
            $config = [];
        }

        $backgroundColor = $this->settingMapper->getValue(
            key: AdminSetting::KEY_FOOTER_BACKGROUND_COLOR,
            default: null
        );

        if ($backgroundColor !== null && is_string($backgroundColor) === false) {
            $backgroundColor = null;
        }

        $textColor = $this->settingMapper->getValue(
            key: AdminSetting::KEY_FOOTER_TEXT_COLOR,
            default: null
        );

        if ($textColor !== null && is_string($textColor) === false) {
            $textColor = null;
        }

        return [
            'footerEnabled'         => $enabled,
            'footerHtml'            => $html,
            'footerConfig'          => $config,
            'footerBackgroundColor' => $backgroundColor,
            'footerTextColor'       => $textColor,
        ];
    }//end getGlobalSettings()

    /**
     * Patch one or more global footer settings (REQ-FTR-001..003,
     * REQ-FTR-009, REQ-FTR-010).
     *
     * Only keys present in `$patch` are updated; untouched keys
     * retain their previous values. Each value is validated before
     * persistence:
     *  - `footerEnabled` — coerced to bool.
     *  - `footerHtml` — sanitised via {@see FooterService::sanitiseHtml()}
     *    (throws on > 8 KB input).
     *  - `footerConfig` — validated via {@see FooterService::validateStructuredConfig()}.
     *  - `footerBackgroundColor` / `footerTextColor` — validated via
     *    {@see FooterService::assertHexColour()} (or NULL to clear).
     *
     * @param array<string, mixed> $patch Partial payload from the
     *                                    HTTP body.
     *
     * @return void
     *
     * @throws InvalidArgumentException When validation fails. The
     *                                  controller maps the message to
     *                                  HTTP 400 / 413 as appropriate.
     */
    public function updateGlobalSettings(array $patch): void
    {
        if (array_key_exists(key: 'footerEnabled', array: $patch) === true) {
            $this->settingMapper->setSetting(
                key: AdminSetting::KEY_FOOTER_ENABLED,
                value: (bool) $patch['footerEnabled']
            );
        }

        if (array_key_exists(key: 'footerHtml', array: $patch) === true) {
            $raw = $patch['footerHtml'];
            if ($raw === null) {
                $sanitised = '';
            } else if (is_array($raw) === true) {
                // Language-tagged variant map (REQ-FTR-007). Sanitise
                // each variant independently.
                $sanitised = [];
                foreach ($raw as $locale => $variant) {
                    if (is_string($locale) === false || is_string($variant) === false) {
                        throw new InvalidArgumentException(
                            message: 'footerHtml language variants must be string→string'
                        );
                    }

                    $sanitised[$locale] = $this->sanitiseHtml(html: $variant);
                }
            } else if (is_string($raw) === true) {
                $sanitised = $this->sanitiseHtml(html: $raw);
            } else {
                throw new InvalidArgumentException(
                    message: 'footerHtml must be a string, NULL, or a variant map'
                );
            }//end if

            $this->settingMapper->setSetting(
                key: AdminSetting::KEY_FOOTER_HTML,
                value: $sanitised
            );
        }//end if

        if (array_key_exists(key: 'footerConfig', array: $patch) === true) {
            $rawConfig = $patch['footerConfig'];
            if ($rawConfig === null) {
                $rawConfig = [];
            }

            if (is_array($rawConfig) === false) {
                throw new InvalidArgumentException(
                    message: 'footerConfig must be a JSON object'
                );
            }

            $this->validateStructuredConfig(config: $rawConfig);

            $this->settingMapper->setSetting(
                key: AdminSetting::KEY_FOOTER_CONFIG,
                value: $rawConfig
            );
        }

        if (array_key_exists(key: 'footerBackgroundColor', array: $patch) === true) {
            $colour = $patch['footerBackgroundColor'];
            if ($colour !== null) {
                $this->assertHexColour(value: $colour, fieldName: 'footerBackgroundColor');
            }

            $this->settingMapper->setSetting(
                key: AdminSetting::KEY_FOOTER_BACKGROUND_COLOR,
                value: $colour
            );
        }

        if (array_key_exists(key: 'footerTextColor', array: $patch) === true) {
            $colour = $patch['footerTextColor'];
            if ($colour !== null) {
                $this->assertHexColour(value: $colour, fieldName: 'footerTextColor');
            }

            $this->settingMapper->setSetting(
                key: AdminSetting::KEY_FOOTER_TEXT_COLOR,
                value: $colour
            );
        }
    }//end updateGlobalSettings()

    /**
     * Sanitise raw footer HTML against the allow-list (REQ-FTR-002,
     * REQ-FTR-005). Strips disallowed tags + attributes, normalises
     * external links with `rel="noopener noreferrer"` and
     * `target="_blank"`, and rejects oversized payloads.
     *
     * Implementation note: a tiny regex-based sanitiser is sufficient
     * here because the allow-list is closed and small. The DOM-based
     * fallback used by the text-display widget is overkill for the
     * footer's narrow surface — any payload > 8 KB is rejected before
     * processing so worst-case complexity is bounded.
     *
     * @param string $html The raw HTML input.
     *
     * @return string The sanitised HTML (always safe to render).
     *
     * @throws InvalidArgumentException When the input exceeds 8 KB.
     */
    public function sanitiseHtml(string $html): string
    {
        if (strlen(string: $html) > self::MAX_HTML_BYTES) {
            throw new InvalidArgumentException(
                message: 'footerHtml exceeds 8 KB limit'
            );
        }

        if ($html === '') {
            return '';
        }

        // Strip <script>...</script> bodies entirely (content + tags).
        $stripped = preg_replace(
            pattern: '#<script\b[^>]*>.*?</script>#is',
            replacement: '',
            subject: $html
        );

        if ($stripped === null) {
            return '';
        }

        // Replace any tag with a sanitised version or empty string. The
        // callback inspects each match, validates the tag name, drops
        // disallowed attributes, and force-tags external <a> elements
        // with rel/target.
        $result = preg_replace_callback(
            pattern: '#<(/?)\s*([a-zA-Z0-9]+)\b([^>]*)>#s',
            callback: function (array $matches): string {
                $closing = ($matches[1] === '/');
                $tag     = strtolower(string: $matches[2]);
                $attrs   = $matches[3];

                if (in_array(needle: $tag, haystack: self::ALLOWED_TAGS, strict: true) === false) {
                    return '';
                }

                if ($closing === true) {
                    return '</'.$tag.'>';
                }

                $allowedAttrs = (self::ALLOWED_ATTRIBUTES[$tag] ?? []);
                $kept         = [];
                if ($allowedAttrs !== [] && trim(string: $attrs) !== '') {
                    if (preg_match_all(
                        pattern: '#([a-zA-Z][a-zA-Z0-9_-]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#',
                        subject: $attrs,
                        matches: $found,
                        flags: PREG_SET_ORDER
                    ) > 0
                    ) {
                        foreach ($found as $attr) {
                            $name = strtolower(string: $attr[1]);
                            if (in_array(needle: $name, haystack: $allowedAttrs, strict: true) === false) {
                                continue;
                            }

                            $value = ($attr[3] ?? ($attr[4] ?? ($attr[5] ?? '')));
                            // Reject data: / javascript: schemes on URL
                            // attributes (href / src). The allow-list
                            // already restricted us to those names so a
                            // scheme check is enough.
                            if (preg_match(
                                pattern: '#^\s*(javascript|data|vbscript):#i',
                                subject: $value
                            ) === 1
                            ) {
                                continue;
                            }

                            // Strip any control char or newline; keep
                            // simple value escaping for HTML context.
                            $cleanValue  = htmlspecialchars(
                                string: $value,
                                flags: (ENT_QUOTES | ENT_HTML5),
                                encoding: 'UTF-8'
                            );
                            $kept[$name] = $cleanValue;
                        }//end foreach
                    }//end if
                }//end if

                $rendered = '<'.$tag;
                foreach ($kept as $name => $value) {
                    $rendered .= ' '.$name.'="'.$value.'"';
                }

                // External <a> elements get rel + target automatically
                // (REQ-FTR-002 external-link scenario).
                if ($tag === 'a'
                    && isset($kept['href']) === true
                    && preg_match(
                        pattern: '#^https?://#i',
                        subject: $kept['href']
                    ) === 1
                ) {
                    $rendered .= ' rel="noopener noreferrer" target="_blank"';
                }

                if (in_array(needle: $tag, haystack: ['br', 'img'], strict: true) === true) {
                    $rendered .= ' />';
                } else {
                    $rendered .= '>';
                }

                return $rendered;
            },
            subject: $stripped
        );

        if ($result === null) {
            return '';
        }

        return $result;
    }//end sanitiseHtml()

    /**
     * Validate a structured-mode config payload against the documented
     * schema (REQ-FTR-003). Throws on extra keys, malformed `links`,
     * or an unknown `layoutMode`.
     *
     * @param array<string, mixed> $config The structured config.
     *
     * @return void
     *
     * @throws InvalidArgumentException On schema mismatch.
     */
    public function validateStructuredConfig(array $config): void
    {
        foreach (array_keys(array: $config) as $key) {
            if (in_array(needle: $key, haystack: self::STRUCTURED_CONFIG_KEYS, strict: true) === false) {
                throw new InvalidArgumentException(
                    message: 'footerConfig contains unknown key: '.(string) $key
                );
            }
        }

        if (array_key_exists(key: 'layoutMode', array: $config) === true) {
            $mode = $config['layoutMode'];
            if (is_string($mode) === false
                || in_array(needle: $mode, haystack: self::LAYOUT_MODES, strict: true) === false
            ) {
                throw new InvalidArgumentException(
                    message: 'footerConfig.layoutMode must be one of: '.implode(separator: ', ', array: self::LAYOUT_MODES)
                );
            }
        }

        if (array_key_exists(key: 'links', array: $config) === true) {
            $links = $config['links'];
            if (is_array($links) === false) {
                throw new InvalidArgumentException(
                    message: 'footerConfig.links must be an array'
                );
            }

            foreach ($links as $entry) {
                if (is_array($entry) === false
                    || isset($entry['label']) === false
                    || isset($entry['url']) === false
                    || is_string($entry['label']) === false
                    || is_string($entry['url']) === false
                ) {
                    throw new InvalidArgumentException(
                        message: 'footerConfig.links entries must be {label, url} string pairs'
                    );
                }
            }
        }//end if
    }//end validateStructuredConfig()

    /**
     * Resolve the effective footer payload for a single dashboard
     * (REQ-FTR-004, REQ-FTR-006). Returns NULL when no footer should
     * render, or an associative array suitable for serialising as
     * the dashboard API response's `effectiveFooter` field.
     *
     * Resolution order (matches DashboardService.resolveFooterForDashboard
     * tasks 6.5):
     *  - dashboard mode = `hidden` → NULL.
     *  - dashboard mode = `custom` → render the dashboard HTML.
     *  - dashboard mode = `inherit` → check global `footerEnabled`;
     *    if false → NULL; otherwise render the global footer HTML or
     *    structured config.
     *
     * @param Dashboard $dashboard The dashboard whose footer to resolve.
     *
     * @return array<string, mixed>|null Effective footer payload keyed
     *                                    by `mode`, `html`, `config`,
     *                                    `backgroundColor`, `textColor`.
     */
    public function resolveFooterForDashboard(Dashboard $dashboard): ?array
    {
        $rawMode = $dashboard->getDashboardFooterMode();
        if ($rawMode === '') {
            $mode = Dashboard::FOOTER_MODE_INHERIT;
        } else {
            $mode = $rawMode;
        }

        if ($mode === Dashboard::FOOTER_MODE_HIDDEN) {
            return null;
        }

        $globals = $this->getGlobalSettings();

        if ($mode === Dashboard::FOOTER_MODE_CUSTOM) {
            $html = $dashboard->getDashboardFooterHtml();
            if ($html === null || trim(string: $html) === '') {
                return null;
            }

            return [
                'mode'            => Dashboard::FOOTER_MODE_CUSTOM,
                'html'            => $html,
                'config'          => null,
                'backgroundColor' => $globals['footerBackgroundColor'],
                'textColor'       => $globals['footerTextColor'],
            ];
        }

        // Inherit branch — must consult the global toggle.
        if ($globals['footerEnabled'] !== true) {
            return null;
        }

        $html      = $globals['footerHtml'];
        $htmlValue = null;
        if (is_string($html) === true && $html !== '') {
            $htmlValue = $html;
        } else if (is_array($html) === true && $html !== []) {
            // Language-variant map — the frontend selects the locale.
            $htmlValue = $html;
        }

        $config      = $globals['footerConfig'];
        $configValue = null;
        if (is_array($config) === true && $config !== []) {
            $configValue = $config;
        }

        if ($htmlValue === null && $configValue === null) {
            // Footer enabled but nothing configured — skip rendering.
            return null;
        }

        return [
            'mode'            => 'global',
            'html'            => $htmlValue,
            'config'          => $configValue,
            'backgroundColor' => $globals['footerBackgroundColor'],
            'textColor'       => $globals['footerTextColor'],
        ];
    }//end resolveFooterForDashboard()

    /**
     * Validate that a value is a `#rgb` / `#rrggbb` hex colour string
     * (REQ-FTR-009). Throws on anything else.
     *
     * @param mixed  $value     The value to validate.
     * @param string $fieldName The field name for the error message.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the value is not a hex string.
     */
    private function assertHexColour(mixed $value, string $fieldName): void
    {
        if (is_string($value) === false
            || preg_match(pattern: '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', subject: $value) !== 1
        ) {
            throw new InvalidArgumentException(
                message: $fieldName.' must be a hex colour string (e.g. #1a1a1a)'
            );
        }
    }//end assertHexColour()
}//end class

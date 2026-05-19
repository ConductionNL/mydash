<?php

/**
 * MacroRenderer
 *
 * Pre-sanitisation pass that converts Confluence Storage Format macros
 * (`<ac:structured-macro>`, `<ac:image>`, `<ri:attachment>`) into plain
 * HTML the {@see HtmlSanitizer} can keep. Implements REQ-CFLI-006:
 * recognised macros render as styled blocks, unrecognised macros become
 * a `<div class="confluence-unsupported-macro">` placeholder so the
 * admin can spot them in the imported dashboard.
 *
 * @category  Service
 * @package   OCA\MyDash\Service\Confluence
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

namespace OCA\MyDash\Service\Confluence;

/**
 * Render Confluence macros to plain HTML before sanitisation.
 *
 * The renderer is regex-based on purpose: the input is the raw HTML
 * fragment grabbed from the Confluence page export, which mixes XHTML,
 * HTML4 and Storage Format. A full DOM parse for namespaced elements
 * is brittle across export variants. The regexes are run in fixed
 * order and never recurse into their own output.
 *
 * @SuppressWarnings(PHPMD.UndefinedVariable)
 *      `preg_match` populates its by-ref `$matches` argument; PHPMD's
 *      flow analysis does not follow PHP by-reference semantics.
 * @SuppressWarnings(PHPMD.ShortVariable)
 *      Local variables `$m` / `$rb` are scoped to the closure-only
 *      regex callback; expanding them would harm readability.
 */
class MacroRenderer
{

    /**
     * Recognised "panel" macro names mapped to their CSS class suffix.
     *
     * Each renders as `<div class="confluence-panel-<suffix>">…</div>`
     * with the macro body unwrapped from `<ac:rich-text-body>`.
     *
     * @var array<int, string>
     */
    private const PANEL_TYPES = ['info', 'note', 'warning', 'tip', 'error', 'panel'];

    /**
     * Render every recognised macro inside an HTML fragment.
     *
     * @param string $html The raw HTML fragment.
     *
     * @return string The fragment with macros expanded into plain HTML.
     */
    public function render(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $out = $this->renderImages(html: $html);
        $out = $this->renderCodeMacros(html: $out);
        $out = $this->renderPanelMacros(html: $out);
        $out = $this->renderExpandMacros(html: $out);
        $out = $this->renderFallbackMacros(html: $out);
        $out = $this->stripStorageNamespaces(html: $out);
        return $out;
    }//end render()

    /**
     * Convert `<ac:image>…<ri:attachment ri:filename="x"/>…</ac:image>`
     * to `<img src="x" alt="x">`. Rewrites are limited to the filename
     * fragment — the upload pipeline is expected to swap `src` to a
     * Nextcloud URL afterwards.
     *
     * @param string $html The HTML fragment.
     *
     * @return string The fragment with `<ac:image>` rewritten.
     */
    private function renderImages(string $html): string
    {
        $pattern  = '/<ac:image\b[^>]*>(.*?)<\/ac:image>/is';
        $callback = static function (array $match): string {
            $body = (string) ($match[1] ?? '');
            if (preg_match(pattern: '/ri:filename\s*=\s*"([^"]+)"/i', subject: $body, matches: $m) === 1) {
                $name = htmlspecialchars(
                    string: $m[1],
                    flags: (ENT_QUOTES | ENT_SUBSTITUTE),
                    encoding: 'UTF-8'
                );
                return '<img src="'.$name.'" alt="'.$name.'">';
            }

            if (preg_match(pattern: '/ri:value\s*=\s*"([^"]+)"/i', subject: $body, matches: $m) === 1) {
                $url = htmlspecialchars(
                    string: $m[1],
                    flags: (ENT_QUOTES | ENT_SUBSTITUTE),
                    encoding: 'UTF-8'
                );
                return '<img src="'.$url.'" alt="">';
            }

            return '';
        };

        $result = preg_replace_callback(
            pattern: $pattern,
            callback: $callback,
            subject: $html
        );

        return ($result ?? $html);
    }//end renderImages()

    /**
     * Render `<ac:structured-macro ac:name="code">` as `<pre><code>…</code></pre>`.
     *
     * The optional `<ac:parameter ac:name="language">` controls the
     * `class="language-X"` hint on the inner `<code>`.
     *
     * @param string $html The HTML fragment.
     *
     * @return string The fragment with code macros expanded.
     */
    private function renderCodeMacros(string $html): string
    {
        $pattern = '/<ac:structured-macro\b[^>]*ac:name="code"[^>]*>(.*?)<\/ac:structured-macro>/is';

        $callback = static function (array $match): string {
            $inner = (string) ($match[1] ?? '');

            $language = '';
            if (preg_match(
                pattern: '/<ac:parameter\b[^>]*ac:name="language"[^>]*>([^<]+)<\/ac:parameter>/i',
                subject: $inner,
                matches: $langMatch
            ) === 1
            ) {
                $language = preg_replace(
                    pattern: '/[^A-Za-z0-9_-]/',
                    replacement: '',
                    subject: (string) $langMatch[1]
                ) ?? '';
            }

            $body = '';
            if (preg_match(
                pattern: '/<ac:plain-text-body><!\[CDATA\[(.*?)\]\]><\/ac:plain-text-body>/is',
                subject: $inner,
                matches: $bodyMatch
            ) === 1
            ) {
                $body = (string) $bodyMatch[1];
            }

            $escaped   = htmlspecialchars(
                string: $body,
                flags: (ENT_QUOTES | ENT_SUBSTITUTE),
                encoding: 'UTF-8'
            );
            $codeClass = '';
            if ($language !== '') {
                $codeClass = ' class="language-'.$language.'"';
            }

            return '<pre><code'.$codeClass.'>'.$escaped.'</code></pre>';
        };

        $result = preg_replace_callback(
            pattern: $pattern,
            callback: $callback,
            subject: $html
        );

        return ($result ?? $html);
    }//end renderCodeMacros()

    /**
     * Render Confluence panel-style macros (`info`, `note`, `warning`,
     * `tip`, `error`, `panel`) as `<div class="confluence-panel-X">`.
     *
     * @param string $html The HTML fragment.
     *
     * @return string The fragment with panel macros expanded.
     */
    private function renderPanelMacros(string $html): string
    {
        $allowed = implode(separator: '|', array: self::PANEL_TYPES);
        $pattern = '/<ac:structured-macro\b[^>]*ac:name="('.$allowed.')"[^>]*>(.*?)<\/ac:structured-macro>/is';

        $callback = static function (array $match): string {
            $type = strtolower(string: (string) ($match[1] ?? 'panel'));
            $body = (string) ($match[2] ?? '');

            $rich = $body;
            if (preg_match(
                pattern: '/<ac:rich-text-body>(.*?)<\/ac:rich-text-body>/is',
                subject: $body,
                matches: $rb
            ) === 1
            ) {
                $rich = (string) $rb[1];
            }

            return '<div class="confluence-panel-'.$type.'">'.$rich.'</div>';
        };

        $result = preg_replace_callback(
            pattern: $pattern,
            callback: $callback,
            subject: $html
        );

        return ($result ?? $html);
    }//end renderPanelMacros()

    /**
     * Render `<ac:structured-macro ac:name="expand">` as
     * `<details><summary>…</summary>…</details>`.
     *
     * @param string $html The HTML fragment.
     *
     * @return string The fragment with expand macros expanded.
     */
    private function renderExpandMacros(string $html): string
    {
        $pattern = '/<ac:structured-macro\b[^>]*ac:name="expand"[^>]*>(.*?)<\/ac:structured-macro>/is';

        $callback = static function (array $match): string {
            $inner = (string) ($match[1] ?? '');

            $title = 'Details';
            if (preg_match(
                pattern: '/<ac:parameter\b[^>]*ac:name="title"[^>]*>([^<]+)<\/ac:parameter>/i',
                subject: $inner,
                matches: $titleMatch
            ) === 1
            ) {
                $title = htmlspecialchars(
                    string: (string) $titleMatch[1],
                    flags: (ENT_QUOTES | ENT_SUBSTITUTE),
                    encoding: 'UTF-8'
                );
            }

            $body = $inner;
            if (preg_match(
                pattern: '/<ac:rich-text-body>(.*?)<\/ac:rich-text-body>/is',
                subject: $inner,
                matches: $rb
            ) === 1
            ) {
                $body = (string) $rb[1];
            }

            return '<details><summary>'.$title.'</summary>'.$body.'</details>';
        };

        $result = preg_replace_callback(
            pattern: $pattern,
            callback: $callback,
            subject: $html
        );

        return ($result ?? $html);
    }//end renderExpandMacros()

    /**
     * Render any remaining `<ac:structured-macro>` as the
     * `<div class="confluence-unsupported-macro">` placeholder.
     *
     * @param string $html The HTML fragment.
     *
     * @return string The fragment with fallback placeholders injected.
     */
    private function renderFallbackMacros(string $html): string
    {
        $pattern = '/<ac:structured-macro\b[^>]*ac:name="([^"]+)"[^>]*>(?:.*?<\/ac:structured-macro>)?/is';

        $callback = static function (array $match): string {
            $name = htmlspecialchars(
                string: (string) ($match[1] ?? 'unknown'),
                flags: (ENT_QUOTES | ENT_SUBSTITUTE),
                encoding: 'UTF-8'
            );
            return '<div class="confluence-unsupported-macro">Unsupported macro: <code>'.$name.'</code></div>';
        };

        $result = preg_replace_callback(
            pattern: $pattern,
            callback: $callback,
            subject: $html
        );

        return ($result ?? $html);
    }//end renderFallbackMacros()

    /**
     * Strip residual Confluence Storage Format namespace tags.
     *
     * @param string $html The HTML fragment.
     *
     * @return string The fragment with namespace tags removed.
     */
    private function stripStorageNamespaces(string $html): string
    {
        $patterns = [
            '/<\/?ac:[a-z0-9_-]+\b[^>]*>/i',
            '/<\/?ri:[a-z0-9_-]+\b[^>]*>/i',
        ];

        $result = preg_replace(pattern: $patterns, replacement: '', subject: $html);
        return ($result ?? $html);
    }//end stripStorageNamespaces()
}//end class

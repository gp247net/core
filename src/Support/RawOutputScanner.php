<?php

namespace GP247\Core\Support;

/**
 * Finds `{!! … !!}` prints of a variable or a call in Blade source and tells trusted HTML
 * forms (rich admin content, code-built markup, helpers that escape the text they wrap) from
 * raw prints of text someone typed. Once text is stored as typed (core 3.12), such a raw
 * print is stored XSS. One definition shared by the package guard test (RawOutputGuardTest)
 * and the doctor / System alerts check (RawOutputScanCheck).
 *
 * Pattern based on purpose: an unusual trusted form shows up as a finding the reader can
 * dismiss; there is no data-flow analysis.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-raw-output-guard, US-CLI-doctor-raw-output-scan
 * @aidlc-adr compat-foundation_static-security-guards
 */
final class RawOutputScanner
{
    /**
     * Calls whose whole result is trusted HTML when the call wraps the entire expression.
     * name => reason.
     */
    public const TRUSTED_CALLS = [
        // WHY: gp247_html_render() only decodes entities — there is no purifier. It is meant
        // for rich content written in the admin editor (page/product content, banner HTML),
        // which is HTML by design.
        'gp247_html_render' => 'rich content from the admin editor, HTML by design',
        'e' => 'escapes its whole argument',
        'gp247_form_render_escape' => 'escapes its whole argument for a form value',
        'gp247_currency_render' => 'price HTML built from a number',
        'gp247_render_block' => 'concatenation of layout block views, each guarded on its own',
        'gp247_render_plugin_hook' => 'concatenation of plugin hook views',
        'gp247_image_render' => 'escapes alt, title and src itself',
        'gp247_render_option_price' => 'escapes the option name itself; the price part is a number',
        'gp247_render_basic_markup' => 'escapes everything, gives back only <b>/<strong>/<i>/<em>/<br> without attributes',
        'gp247_language_label' => 'translation or the escaped string',
        'csrf_field' => 'hidden token input built by Laravel',
        'method_field' => 'hidden method input built by Laravel',
    ];

    /** Model methods that return a rendered view or price HTML (the view itself is checked). */
    public const TRUSTED_METHODS = ['showPrice', 'showPriceDetail', 'displayVendor', 'renderAttributeDetails', 'links', 'render'];

    /**
     * Raw prints (`{!! expr !!}` whose expr holds a variable or a call) of one Blade source.
     * Blade comments are blanked first so commented examples do not count.
     *
     * @param string $source
     * @return array<int, array{line:int, expr:string}>
     */
    public static function rawPrints(string $source): array
    {
        $source = (string) preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            fn ($m) => str_repeat("\n", substr_count($m[0], "\n")),
            $source
        );

        preg_match_all('/\{!!(.*?)!!\}/s', $source, $matches, PREG_OFFSET_CAPTURE);

        $prints = [];
        foreach ($matches[1] as [$raw, $offset]) {
            $expr = trim((string) preg_replace('/\s+/', ' ', $raw));
            if (!preg_match('/[\$\(]/', $expr)) {
                continue;
            }
            $prints[] = [
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                'expr' => $expr,
            ];
        }

        return $prints;
    }

    /**
     * Whether a normalised expression is one of the trusted forms.
     *
     * @param string $expr
     * @return bool
     */
    public static function isTrustedForm(string $expr): bool
    {
        foreach (array_keys(self::TRUSTED_CALLS) as $name) {
            if (self::isWholeCall($expr, $name)) {
                return true;
            }
        }

        // nl2br(e(...)) — escaped first, then only <br> added.
        if (self::isWholeCall($expr, 'nl2br')) {
            $inner = trim(substr($expr, strpos($expr, '(') + 1, -1));
            if (self::isWholeCall($inner, 'e')) {
                return true;
            }
        }

        $methods = implode('|', self::TRUSTED_METHODS);
        if (preg_match('/^\$[A-Za-z_]\w*->(' . $methods . ')\(\)$/', $expr)) {
            return true;
        }

        // Language string looked up by a literal key, without replacements (language strings
        // keep encode-on-write — decision D1, mod 20261112T061240).
        return (bool) preg_match("/^gp247_language_(render|quickly)\(\s*'[^']*'\s*(,\s*'[^']*'\s*)?\)$/", $expr);
    }

    /**
     * Whether $expr is exactly `$name( … )` with the opening parenthesis closing at the end.
     *
     * @param string $expr
     * @param string $name
     * @return bool
     */
    private static function isWholeCall(string $expr, string $name): bool
    {
        if (!preg_match('/^' . preg_quote($name, '/') . '\s*\(/', $expr, $m)) {
            return false;
        }

        $depth = 0;
        $quote = null;
        $length = strlen($expr);
        for ($i = strlen($m[0]) - 1; $i < $length; $i++) {
            $ch = $expr[$i];
            if ($quote !== null) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '\'' || $ch === '"') {
                $quote = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i === $length - 1;
                }
            }
        }

        return false;
    }
}

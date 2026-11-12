<?php

if (!function_exists('gp247_check_view') && !in_array('gp247_check_view', config('gp247_functions_except', []))) {
    /**
     * Check view exist
     *
     * @param   [string]  $view path view
     *
     * @return  [string]         [$domain]
     */
    function gp247_check_view($view)
    {
        if (!view()->exists($view)) {
            gp247_report('View not found '.$view);
            echo  gp247_language_render('front.view_not_exist', ['view' => $view]);
            exit();
        }
    }
}


if (!function_exists('gp247_text_plain') && !in_array('gp247_text_plain', config('gp247_functions_except', []))) {
    /**
     * Normalise plain text before it is stored, WITHOUT HTML-encoding it: valid UTF-8
     * (broken bytes are replaced, the string is never emptied), control characters removed
     * (new lines and tabs kept), tags removed only when $stripTags, Unicode whitespace trimmed.
     * Output is encoded where it is shown ({{ }}, e(), @json) — US-CMP-text-stored-as-typed.
     *
     * Same shape as gp247_clean(): a string, or an array walked recursively where keys listed
     * in $exclude (rich HTML, secrets) are left untouched; other values pass through as is.
     *
     * @param mixed $data
     * @param array<int, string> $exclude Keys whose values are kept as they are.
     * @param bool $stripTags Remove HTML tags (fields that never hold markup).
     * @return mixed
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-text-stored-as-typed
     * @aidlc-adr compat-foundation_text-stored-as-typed
     */
    function gp247_text_plain($data = null, array $exclude = [], bool $stripTags = false)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (in_array($key, $exclude, true)) {
                    continue;
                }
                $data[$key] = gp247_text_plain($value, $exclude, $stripTags);
            }

            return $data;
        }
        if (!is_string($data)) {
            return $data;
        }

        $text = mb_check_encoding($data, 'UTF-8') ? $data : mb_scrub($data, 'UTF-8');
        // WHY: C0 controls except \t \n \r, DEL and C1 controls never belong in stored text and
        // can corrupt exports (CSV, JSON, mail headers).
        $text = (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', '', $text);
        if ($stripTags) {
            $text = strip_tags($text);
        }

        return (string) preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $text);
    }
}

if (!function_exists('gp247_text_encode_label') && !in_array('gp247_text_encode_label', config('gp247_functions_except', []))) {
    /**
     * Normalise and HTML-encode a language string before it is stored. Language strings are
     * the one place that keeps encode-on-write, because their labels are printed with {!! !!}
     * on purpose (decision D1, mod 20261112T061240). Text already encoded is not encoded twice.
     *
     * @param mixed $data
     * @return mixed
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-text-stored-as-typed
     * @aidlc-adr compat-foundation_text-stored-as-typed
     */
    function gp247_text_encode_label($data = null)
    {
        if (!is_string($data)) {
            return $data;
        }

        return htmlspecialchars(htmlspecialchars_decode((string) gp247_text_plain($data)), ENT_COMPAT, 'UTF-8');
    }
}

if (!function_exists('gp247_clean') && !in_array('gp247_clean', config('gp247_functions_except', []))) {
    /**
     * HTML-encode text before it is stored (the old encode-on-write convention).
     *
     * @deprecated since core 3.12 — use gp247_text_plain() to store text as typed and encode it
     *             on output ({{ }}); gp247_text_encode_label() for language strings. Kept with
     *             its behaviour for plugins that still call it; removed in 4.0.
     */
    function gp247_clean($data = null, $exclude = [], $hight = false)
    {
        if (is_array($data)) {
            array_walk($data, function (&$v, $k) use ($exclude, $hight) {
                if (is_array($v)) {
                    $v = gp247_clean($v, $exclude, $hight);
                } 
                if (is_string($v)) {
                    if (in_array($k, $exclude)) {
                        $v = $v;
                    } else {
                        if ($hight) {
                            $v = strip_tags($v);
                        }
                        $v = htmlspecialchars_decode($v);
                        $v = htmlspecialchars($v, ENT_COMPAT, 'UTF-8');
                    }
                }
            });
        }
        if (is_string($data)) {
            if ($hight) {
                $data = strip_tags($data);
            }
            $data = htmlspecialchars_decode($data);
            $data = htmlspecialchars($data, ENT_COMPAT, 'UTF-8');
        }
        return $data;
    }
}
<?php

if (!function_exists('gp247_form_render_field') && !in_array('gp247_form_render_field', config('gp247_functions_except', []))) {
    function gp247_form_render_field(array $data = [])
    {
        $type = $data['type'] ?? 'text';
        if ($type =='textarea') {
            return gp247_form_render_textarea($data);
        } else 
        if ($type =='select') {
            return gp247_form_render_select($data);
        } else 
        if ($type =='radio') {
            return gp247_form_render_radio($data);
        } else 
        if ($type =='checkbox') {
            return gp247_form_render_checkbox($data);
        } else 
        if ($type =='file') {
            // return gp247_form_render_checkbox($data);
        } else {
            return gp247_form_render_text($data);
        }

    }
}


if (!function_exists('gp247_form_render_escape') && !in_array('gp247_form_render_escape', config('gp247_functions_except', []))) {
    /**
     * HTML-escape a value for safe insertion into markup built by gp247_form_render_*.
     *
     * Normalizes mixed-source values (raw user input reflected via old(), admin-authored
     * raw field defaults/options, and detail values already single-encoded by gp247_clean
     * on write) to exactly one layer of HTML-entity encoding. This closes both reflected
     * XSS (public register/change-info forms) and stored XSS (admin-authored default/option
     * rendered on the storefront) while avoiding double-encoded display artifacts.
     *
     * @param mixed $value Raw or partially-encoded value to render.
     * @return string HTML-entity-encoded string safe for HTML text and quoted attributes.
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-custom-field-hardening
     * @aidlc-adr ADR-compat-foundation-custom-field-output-encoding
     */
    function gp247_form_render_escape($value): string
    {
        // WHY: decode once first so values already encoded by gp247_clean on write are not
        // double-encoded on display; the trailing e() (ENT_QUOTES) is what enforces safety,
        // so a single decode pass can never widen the attack surface — the final encode
        // always yields an attribute/HTML-safe string regardless of prior encoding state.
        return e(htmlspecialchars_decode((string) $value, ENT_QUOTES));
    }
}

if (!function_exists('gp247_form_render_text') && !in_array('gp247_form_render_text', config('gp247_functions_except', []))) {
    function gp247_form_render_text(array $data = [])
    {
        //number, text, date, week, month, time, email, password, url, color
        $name        = $data['name'] ?? '';
        $attribute   = $data['attribute'] ?? '';
        $type        = $data['type'] ?? 'text';
        $placeholder = $data['placeholder'] ?? '';
        $class       = $data['class'] ?? '';
        $css         = $data['css'] ?? '';
        $default     = $data['default'] ?? '';
        $id          = $data['id'] ?? '';
        $required    = !empty($data['required']) ?? 'required="required"';

        $html ='';
        $html .='<input style="'.gp247_form_render_escape($css).'" class="form-control form-control-sm '.gp247_form_render_escape($class).'" id="'.gp247_form_render_escape($id).'" name="'.gp247_form_render_escape($name).'" '.$required.' type="'.gp247_form_render_escape($type).'" placeholder="'.gp247_form_render_escape($placeholder).'" value="'.gp247_form_render_escape($default).'">';
        return $html;
    }
}

if (!function_exists('gp247_form_render_textarea') && !in_array('gp247_form_render_textarea', config('gp247_functions_except', []))) {
    function gp247_form_render_textarea(array $data = [])
    {
        $name        = $data['name'] ?? '';
        $attribute   = $data['attribute'] ?? '';
        $placeholder = $data['placeholder'] ?? '';
        $class       = $data['class'] ?? '';
        $css         = $data['css'] ?? '';
        $default     = $data['default'] ?? '';
        $id          = $data['id'] ?? '';
        $required    = !empty($data['required']) ? 'required="required"':'';

        $html ='<div class="form-group">';
        $html .='<textarea style="'.gp247_form_render_escape($css).'" class="form-control form-control-sm '.gp247_form_render_escape($class).'" id="'.gp247_form_render_escape($id).'" name="'.gp247_form_render_escape($name).'" '.$required.' rows="3" placeholder="'.gp247_form_render_escape($placeholder).'">'.gp247_form_render_escape($default).'</textarea>';
        $html .='</div>';
        return $html;
    }
}


if (!function_exists('gp247_form_render_select') && !in_array('gp247_form_render_select', config('gp247_functions_except', []))) {
    function gp247_form_render_select(array $data = [])
    {
        //select
        $name        = $data['name'] ?? '';
        $attribute   = $data['attribute'] ?? '';
        $placeholder = $data['placeholder'] ?? '';
        $class       = $data['class'] ?? '';
        $css         = $data['css'] ?? '';
        $default     = $data['default'] ?? '';
        $id          = $data['id'] ?? '';
        $dataFormat  = $data['dataFormat'] ?? [];
        $required    = !empty($data['required']) ? 'required="required"':'';

        $html ='';
        $html .='<select style="'.gp247_form_render_escape($css).'" class="form-control form-control-sm '.gp247_form_render_escape($class).'" id="'.gp247_form_render_escape($id).'" name="'.gp247_form_render_escape($name).'" '.$required.'>';
        $html .='<option value="">'.gp247_form_render_escape($placeholder).'</option>';
        if (!empty($dataFormat) && is_countable($dataFormat) && count($dataFormat)) {
            foreach ($dataFormat as $key => $row) {
                $html .='<option value="'.gp247_form_render_escape($key).'" '.(($default == $key) ? 'selected':''). '>'.gp247_form_render_escape($row).'</option>';
            }
        }
        $html .='</select>';
        return $html;
    }
}


if (!function_exists('gp247_form_render_checkbox') && !in_array('gp247_form_render_checkbox', config('gp247_functions_except', []))) {
    function gp247_form_render_checkbox(array $data = [])
    {
        //check
        $name       = $data['name'] ?? '';
        $attribute  = $data['attribute'] ?? 'inline';
        $class      = $data['class'] ?? '';
        $css        = $data['css'] ?? '';
        $label      = $data['label'] ?? '';
        $default    = $data['default'] ?? '';
        $id         = $data['id'] ?? '';
        $dataFormat = $data['dataFormat'] ?? [];
        $default    = explode(',', $default);
        $html ='<div class="form-group">';
        if ($label) {
            $html .='<label for="'.gp247_form_render_escape($id).'">'.gp247_form_render_escape($label).'</label>';
        }
        if ($attribute != 'inline') {
            if (!empty($dataFormat) && is_countable($dataFormat) && count($dataFormat)) {
                foreach ($dataFormat as $key => $row) {
                    $html .='<div class="icheck-primary d-inline">';
                    $html .='<input id="'.gp247_form_render_escape($id).'__'.gp247_form_render_escape($key).'" class="'.gp247_form_render_escape($class).'" style="'.gp247_form_render_escape($css).'" type="checkbox" name="'.gp247_form_render_escape($name).'" value="'.gp247_form_render_escape($key).'" '.((in_array($key, $default)) ? 'checked':''). '>';
                    $html .='<label for="'.gp247_form_render_escape($id).'__'.gp247_form_render_escape($key).'">'.gp247_form_render_escape($row).'</label>';
                    $html .='</div> ';
                }
            }
        } else {
            if (!empty($dataFormat) && is_countable($dataFormat) && count($dataFormat)) {
                foreach ($dataFormat as $key => $row) {
                    $html .='<div class="icheck-primary d-inline">';
                    $html .='<input id="'.gp247_form_render_escape($id).'__'.gp247_form_render_escape($key).'" class="'.gp247_form_render_escape($class).'" style="'.gp247_form_render_escape($css).'" type="checkbox" name="'.gp247_form_render_escape($name).'" value="'.gp247_form_render_escape($key).'" '.((in_array($key, $default)) ? 'checked':''). '>';
                    $html .='<label for="'.gp247_form_render_escape($id).'__'.gp247_form_render_escape($key).'">'.gp247_form_render_escape($row).'</label>';
                    $html .='</div> ';
                }
            }
        }

        $html .='</div>';
        return $html;
    }
}


if (!function_exists('gp247_form_render_radio') && !in_array('gp247_form_render_radio', config('gp247_functions_except', []))) {
    function gp247_form_render_radio(array $data = [])
    {
        //radio
        $name        = $data['name'] ?? '';
        $attribute   = $data['attribute'] ?? 'inline';
        $class       = $data['class'] ?? '';
        $css        = $data['css'] ?? '';
        $default     = $data['default'] ?? '';
        $id          = $data['id'] ?? '';
        $dataFormat  = $data['dataFormat'] ?? [];

        $html ='';
        if (!empty($dataFormat) && is_countable($dataFormat) && count($dataFormat)) {
            if ($attribute != 'inline') {
                foreach ($dataFormat as $key => $row) {
                    $html .='<div class="icheck-primary d-inline">';
                    $html .='<input id="'.gp247_form_render_escape($id).'__'.gp247_form_render_escape($key).'" class="'.gp247_form_render_escape($class).'" style="'.gp247_form_render_escape($css).'" type="radio" name="'.gp247_form_render_escape($name).'" value="'.gp247_form_render_escape($key).'" '.(($default == $key) ? 'checked':''). '>';
                    $html .='<label for="'.gp247_form_render_escape($id).'__'.gp247_form_render_escape($key).'">'.gp247_form_render_escape($row).'</label>';
                    $html .='</div> ';
                }
            } else {
                foreach ($dataFormat as $key => $row) {
                    $html .='<div class="icheck-primary d-inline">';
                    $html .='<input id="'.gp247_form_render_escape($id).'__'.gp247_form_render_escape($key).'" class="'.gp247_form_render_escape($class).'" style="'.gp247_form_render_escape($css).'" type="radio" name="'.gp247_form_render_escape($name).'" value="'.gp247_form_render_escape($key).'" '.(($default == $key) ? 'checked':''). '>';
                    $html .='<label for="'.gp247_form_render_escape($id).'__'.gp247_form_render_escape($key).'">'.gp247_form_render_escape($row).'</label>';
                    $html .='</div> ';
                }
            }

        }
        return $html;
    }
}
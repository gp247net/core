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
        $html .='<input style="'.$css.'" class="form-control form-control-sm '.$class.'" id = "'.$id.'" name="'.$name.'" '.$required.' type="'.$type.'" placeholder="'.$placeholder.'" value="'.$default.'">';
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
        $html .='<textarea style="'.$css.'" class="form-control form-control-sm '.$class.'" id = "'.$id.'" name="'.$name.'" '.$required.' rows="3" placeholder="'.$placeholder.'">'.$default.'</textarea>';
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
        $html .='<select style="'.$css.'" class="form-control form-control-sm '.$class.'" id = "'.$id.'" name='.$name.' '.$required.'>';
        $html .='<option value="">'.$placeholder.'</option>';
        if (!empty($dataFormat) && is_countable($dataFormat) && count($dataFormat)) {
            foreach ($dataFormat as $key => $row) {
                $html .='<option value="'.$key.'" '.(($default == $key) ? 'selected':''). '>'.$row.'</option>';
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
            $html .='<label for="'.$id.'">'.$label.'</label>';
        }
        if ($attribute != 'inline') {
            if (!empty($dataFormat) && is_countable($dataFormat) && count($dataFormat)) {
                foreach ($dataFormat as $key => $row) {
                    $html .='<div class="icheck-primary d-inline">';
                    $html .='<input id="'.$id.'__'.$key.'" class="'.$class.'" style="'.$css.'" type="checkbox" name="'.$name.'" value="'.$key.'" '.((in_array($key, $default)) ? 'checked':''). '>';
                    $html .='<label for="'.$id.'__'.$key.'">'.$row.'</label>';
                    $html .='</div> ';
                }
            }
        } else {
            if (!empty($dataFormat) && is_countable($dataFormat) && count($dataFormat)) {
                foreach ($dataFormat as $key => $row) {
                    $html .='<div class="icheck-primary d-inline">';
                    $html .='<input id="'.$id.'__'.$key.'" class="'.$class.'" style="'.$css.'" type="checkbox" name="'.$name.'" value="'.$key.'" '.((in_array($key, $default)) ? 'checked':''). '>';
                    $html .='<label for="'.$id.'__'.$key.'">'.$row.'</label>';
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
                    $html .='<input id="'.$id.'__'.$key.'" class="'.$class.'" style="'.$css.'" type="radio" name="'.$name.'" value="'.$key.'" '.(($default == $key) ? 'checked':''). '>';
                    $html .='<label for="'.$id.'__'.$key.'">'.$row.'</label>';
                    $html .='</div> ';
                }
            } else {
                foreach ($dataFormat as $key => $row) {
                    $html .='<div class="icheck-primary d-inline">';
                    $html .='<input id="'.$id.'__'.$key.'" class="'.$class.'" style="'.$css.'" type="radio" name="'.$name.'" value="'.$key.'" '.(($default == $key) ? 'checked':''). '>';
                    $html .='<label for="'.$id.'__'.$key.'">'.$row.'</label>';
                    $html .='</div> ';
                }
            }

        }
        return $html;
    }
}
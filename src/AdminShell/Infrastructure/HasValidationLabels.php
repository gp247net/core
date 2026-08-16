<?php

namespace GP247\Core\AdminShell\Infrastructure;

/**
 * Shared validation-label wiring for the admin-shell Livewire screens
 * (core/front/shop). The managers validate Livewire state paths ("form.*",
 * "desc.*.*"). Without an attribute map, Laravel leaks those raw paths into the
 * messages ("The form.alias field is required"). This trait routes every rule
 * key through the EXISTING v1 language keys (the same admin.* strings the form
 * labels render) — no new keys are invented — so errors read the real field
 * name in the active locale.
 *
 * A component opts in by declaring attributeLabels(): a map of rule key => v1
 * language key (e.g. 'form.name' => 'admin.user.name').
 *
 * A component that needs its OWN custom messages() may still use this trait: PHP
 * gives the class-defined method precedence over the trait's, so define messages()
 * on the class and it wins; validationAttributes() from the trait still applies.
 *
 * @aidlc-unit admin-shell
 */
trait HasValidationLabels
{
    /**
     * Map of validator rule key (dotted state path) to its v1 language key.
     * Overridden by the using component; empty by default so the trait is inert
     * on screens without rules.
     *
     * @return array<string, string>
     */
    protected function attributeLabels(): array
    {
        return [];
    }

    /**
     * Render a language key as PLAIN TEXT for use as a validator attribute.
     *
     * WHY: some admin.* strings embed presentational markup in the form label
     * (e.g. a trailing "SEO" icon span). Strip tags/entities so the validation
     * message shows the clean field name only; the key itself is unchanged.
     *
     * @param string $key Language key (e.g. 'admin.user.name').
     * @return string Tag-free, trimmed, localized label.
     */
    protected function label(string $key): string
    {
        $rendered = (string) gp247_language_render($key);

        return trim(strip_tags(html_entity_decode($rendered, ENT_QUOTES)));
    }

    /**
     * Friendly attribute names for every rule (Livewire hook), resolved from the
     * component's attributeLabels() to the localized field name instead of the
     * raw "form.*" / "desc.*" path.
     *
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return array_map(fn (string $key): string => $this->label($key), $this->attributeLabels());
    }

    /**
     * Localized validator messages built from the shared validation.* language
     * keys, so non-required rules (max/numeric/email/…) also read the DB
     * translation instead of bypassing it to the framework's English default.
     * Rule-specific placeholders (:min/:max) are left for the framework replacers
     * to fill after :attribute is substituted here.
     *
     * A component with its OWN extra messages should override messages() and merge:
     * `return array_merge($this->localizedRuleMessages(), ['form.x.regex' => ...]);`
     *
     * @return array<string, string>
     */
    protected function localizedRuleMessages(): array
    {
        $rules = ['required', 'min', 'max', 'numeric', 'integer', 'string', 'email', 'url', 'in', 'gt', 'date'];
        $messages = [];
        foreach ($this->attributeLabels() as $field => $key) {
            $label = $this->label($key);
            foreach ($rules as $rule) {
                $messages[$field . '.' . $rule] = gp247_language_render('validation.' . $rule, ['attribute' => $label]);
            }
        }

        return $messages;
    }

    /**
     * Livewire messages hook. Delegates to localizedRuleMessages(); components
     * needing extra rule messages override this and merge (see above).
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return $this->localizedRuleMessages();
    }
}

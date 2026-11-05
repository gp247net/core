<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\ConfigForm;
use GP247\Core\Support\Password\PasswordPolicy;
use GP247\Core\Support\Password\PasswordPolicyRegistry;

/**
 * Password policy screen — the single source of the password policy of every account
 * group registered in PasswordPolicyRegistry (administrators, customers when the shop is
 * installed, groups of plugins), one block per group. Gated by `admin_config` (ADR-001/005).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-UI-005, US-admin-shell-password-policy-audiences
 * @aidlc-adr ADR-001, ADR-005, admin-shell_password-policy-registry
 */
class PasswordPolicyForm extends ConfigForm
{
    protected ?string $permission = 'admin_config';

    /**
     * @return string
     */
    protected function group(): string
    {
        return 'global';
    }

    /**
     * The six keys of every registered group.
     *
     * @return array<int, string>
     */
    protected function keys(): array
    {
        $keys = [];
        foreach (PasswordPolicyRegistry::all() as $audience) {
            array_push($keys, ...PasswordPolicy::keysFor($audience->key));
        }

        // WHY a key no row has: an empty keys() means "the whole group" to ConfigForm, and
        // this screen must never show the other global settings.
        return $keys !== [] ? $keys : ['no_password_policy_group'];
    }

    /**
     * @return array<string, string>
     */
    protected function fieldTypes(): array
    {
        $types = [];
        foreach ($this->keys() as $key) {
            $types[$key] = (str_ends_with($key, '_password_min') || str_ends_with($key, '_password_max')) ? 'number' : 'bool';
        }

        return $types;
    }

    /**
     * One block per registered group, titled with the group's label.
     *
     * @return array<int, array{id: string, title: string, keys: array<int, string>}>
     */
    protected function sections(): array
    {
        $sections = [];
        foreach (PasswordPolicyRegistry::all() as $audience) {
            $sections[] = [
                'id'    => $audience->key,
                'title' => gp247_language_render($audience->labelKey),
                'keys'  => PasswordPolicy::keysFor($audience->key),
            ];
        }

        return $sections;
    }

    /**
     * Refuse values that would make a policy meaningless (min below the floor, max above
     * what bcrypt hashes, min above max) before anything is written.
     *
     * @return void
     */
    public function save(): void
    {
        $this->authorizeAction('update');
        $this->resetErrorBag();

        $problems = [];
        foreach (PasswordPolicyRegistry::all() as $audience) {
            $problems += PasswordPolicy::problems($audience->key, $this->values);
        }
        if ($problems !== []) {
            foreach ($problems as $key => $message) {
                $this->addError('values.' . $key, $message);
            }

            return;
        }

        parent::save();
    }

    /**
     * @return string
     */
    protected function heading(): string
    {
        return gp247_language_render('admin.cfg_password');
    }
}

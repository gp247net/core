<?php

namespace GP247\Core\Tests\Feature\AdminShell\Support;

use GP247\Core\AdminShell\Infrastructure\FormComponent;

/**
 * Concrete FormComponent capturing the persisted payload so the base behaviour
 * (authorize -> validate -> gp247_clean -> persist) can be asserted without a DB.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-003
 * @aidlc-adr ADR-005
 */
class TestFormComponent extends FormComponent
{
    protected ?string $permission = 'admin_test';

    /** @var array<string, mixed> */
    public array $form = ['name' => ''];

    /** @var array<string, mixed> Payload captured by the last persist() call. */
    public array $persisted = [];

    /**
     * @return array<string, string> Validation rules keyed by form field path.
     */
    protected function rules(): array
    {
        return ['form.name' => 'required|string'];
    }

    /**
     * @param array<string, mixed> $data Sanitised form payload.
     * @return void
     */
    protected function persist(array $data): void
    {
        $this->persisted = $data;
    }

    /**
     * @return string Inline markup for the component.
     */
    public function render(): string
    {
        return '<div>admin-shell-form</div>';
    }
}

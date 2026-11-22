<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use Illuminate\Support\Facades\Blade;
use GP247\Core\Tests\TestCase;

/**
 * Renders each <x-gp247::*> component (ADR-005) through Blade to prove the
 * anonymous component namespace is wired and the TailAdmin markup compiles.
 * These components are intentionally helper/DB-free, so they render in isolation.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-001
 * @aidlc-adr ADR-005
 */
class UiComponentTest extends TestCase
{
    public function test_view_and_component_namespaces_are_registered(): void
    {
        $this->assertTrue(view()->exists('gp247-admin::layouts.admin'));
        $this->assertTrue(view()->exists('gp247-admin::components.button'));
    }

    public function test_button_renders_as_button_by_default(): void
    {
        $html = Blade::render('<x-gp247::button>Save</x-gp247::button>');

        $this->assertStringContainsString('<button', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('Save', $html);
        $this->assertStringContainsString('bg-blue-600', $html);
    }

    public function test_button_renders_as_link_when_href_given(): void
    {
        $html = Blade::render('<x-gp247::button href="/admin/users" variant="danger">Go</x-gp247::button>');

        $this->assertStringContainsString('<a href="/admin/users"', $html);
        $this->assertStringContainsString('bg-red-600', $html);
    }

    public function test_card_renders_title_and_body(): void
    {
        $html = Blade::render('<x-gp247::card title="Users">Body here</x-gp247::card>');

        $this->assertStringContainsString('Users', $html);
        $this->assertStringContainsString('Body here', $html);
    }

    public function test_alert_uses_type_color_and_dismiss_when_requested(): void
    {
        $html = Blade::render('<x-gp247::alert type="error" :dismissible="true">Boom</x-gp247::alert>');

        $this->assertStringContainsString('text-red-800', $html);
        $this->assertStringContainsString('x-data', $html);
        $this->assertStringContainsString('Boom', $html);
    }

    public function test_badge_maps_color_to_safelisted_classes(): void
    {
        $html = Blade::render('<x-gp247::badge color="green">Active</x-gp247::badge>');

        $this->assertStringContainsString('bg-green-100', $html);
        $this->assertStringContainsString('text-green-700', $html);
        $this->assertStringContainsString('Active', $html);
    }

    public function test_badge_falls_back_to_gray_for_invalid_color(): void
    {
        // WHY: color often comes from DB; a malformed value must not break utility names.
        $html = Blade::render('<x-gp247::badge color="not a color">X</x-gp247::badge>');

        $this->assertStringContainsString('bg-gray-100', $html);
    }

    public function test_input_renders_label_name_and_error(): void
    {
        $html = Blade::render('<x-gp247::input label="Email" name="email" error="Required" />');

        $this->assertStringContainsString('Email', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('Required', $html);
        $this->assertStringContainsString('text-red-600', $html);
    }

    public function test_input_date_type_upgrades_to_flatpickr_datepicker(): void
    {
        $html = Blade::render('<x-gp247::input type="date" wire:model="expire" label="Expire" />');

        // Upgraded to the TailAdmin flatpickr datepicker, not the browser native control.
        $this->assertStringContainsString('gp247Datepicker(', $html);
        $this->assertStringContainsString('wire:ignore', $html);
        $this->assertStringContainsString('fa-calendar-alt', $html);

        // The visible control is a styled text input; the native date picker is gone.
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringNotContainsString('type="date"', $html);

        // wire:model must not sit on the flatpickr-controlled input (synced via $wire);
        // the bound property name is threaded into the Alpine factory instead.
        $this->assertStringNotContainsString('wire:model', $html);
        $this->assertStringContainsString('expire', $html);
    }

    public function test_input_text_type_stays_native(): void
    {
        // WHY: only date-ish types are upgraded; a plain text field is untouched.
        $html = Blade::render('<x-gp247::input type="text" wire:model="name" label="Name" />');

        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('wire:model="name"', $html);
        $this->assertStringNotContainsString('gp247Datepicker', $html);
    }

    public function test_table_renders_headers_and_empty_message(): void
    {
        $html = Blade::render(
            '<x-gp247::table :headers="[\'Name\', \'Email\']" empty="No rows" />'
        );

        $this->assertStringContainsString('<th', $html);
        $this->assertStringContainsString('Name', $html);
        $this->assertStringContainsString('Email', $html);
        $this->assertStringContainsString('No rows', $html);
    }

    public function test_toast_container_listens_for_notify_event(): void
    {
        $html = Blade::render('<x-gp247::toast />');

        // WHY: the toast subscribes to the browser `notify` event dispatched by
        // GP247AdminComponent::notify(); assert the Alpine wiring is present.
        $this->assertStringContainsString('notify', $html);
        $this->assertStringContainsString('x-data', $html);
    }

    public function test_modal_renders_title_and_body_slot(): void
    {
        $html = Blade::render('<x-gp247::modal name="confirm" title="Are you sure?">Body</x-gp247::modal>');

        $this->assertStringContainsString('Are you sure?', $html);
        $this->assertStringContainsString('Body', $html);
        $this->assertStringContainsString('x-data', $html);
    }

    public function test_modal_opens_from_a_livewire_dispatch_whose_params_arrive_wrapped(): void
    {
        // $this->dispatch('open-modal', 'plan-form') reaches the browser as
        // CustomEvent.detail = ['plan-form'] (Livewire wraps the params), a raw
        // Alpine $dispatch as detail = 'plan-form', a named param as {name}. The
        // modal must answer to all three or every server-opened modal is dead.
        $html = Blade::render('<x-gp247::modal name="plan-form">Body</x-gp247::modal>');

        $this->assertStringContainsString("d === 'plan-form'", $html, 'raw string detail');
        $this->assertStringContainsString("Array.isArray(d) && d[0] === 'plan-form'", $html, 'Livewire positional param');
        $this->assertStringContainsString("d.name === 'plan-form'", $html, 'Livewire named param');
        $this->assertStringContainsString('x-on:open-modal.window="if (isMine($event.detail)) open = true"', $html);
        $this->assertStringContainsString('x-on:close-modal.window="if (isMine($event.detail)) open = false"', $html);
    }

    // ── searchable-select ──────────────────────────────────────────────────────

    public function test_searchable_select_single_renders_alpine_factory_and_wire_ignore(): void
    {
        $html = Blade::render(
            '<x-gp247::searchable-select name="cat" label="Category"
                :options="[[\'id\'=>1,\'label\'=>\'Shoes\'],[\'id\'=>2,\'label\'=>\'Bags\']]" />'
        );

        $this->assertStringContainsString('Category', $html);
        $this->assertStringContainsString('gp247SearchableSelect(', $html);
        $this->assertStringContainsString('wire:ignore', $html);
        // Single mode: input row, no tag-wrap
        $this->assertStringContainsString('gp247-ss-row', $html);
        $this->assertStringNotContainsString('gp247-ss-tags', $html);
        // Options JSON is injected
        $this->assertStringContainsString('Shoes', $html);
        $this->assertStringContainsString('Bags', $html);
    }

    public function test_searchable_select_multiple_renders_tag_ui(): void
    {
        $html = Blade::render(
            '<x-gp247::searchable-select name="colors[]" :multiple="true"
                :options="[[\'id\'=>1,\'label\'=>\'Red\'],[\'id\'=>2,\'label\'=>\'Blue\']]" />'
        );

        $this->assertStringContainsString('gp247-ss-tags', $html);
        $this->assertStringNotContainsString('gp247-ss-row', $html);
        $this->assertStringContainsString('gp247SearchableSelect(', $html);
    }

    public function test_searchable_select_error_adds_error_class_and_message(): void
    {
        $html = Blade::render(
            '<x-gp247::searchable-select name="cat" error="Required field"
                :options="[]" />'
        );

        $this->assertStringContainsString('gp247-error', $html);
        $this->assertStringContainsString('Required field', $html);
        $this->assertStringContainsString('text-red-600', $html);
    }

    public function test_searchable_select_model_prop_threads_into_alpine_factory(): void
    {
        // WHY: the factory must receive the Livewire property name so $wire.set()
        // can write the selected value back — same mechanism as gp247Datepicker.
        $html = Blade::render(
            '<x-gp247::searchable-select model="categoryId"
                :options="[[\'id\'=>3,\'label\'=>\'Hats\']]" />'
        );

        $this->assertStringContainsString("'categoryId'", $html);
        $this->assertStringContainsString('wire:ignore', $html);
        // No seed hidden input when model is set (Livewire owns the value).
        $this->assertStringNotContainsString('data-ss-hidden', $html);
    }

    public function test_searchable_select_seed_value_renders_hidden_input_without_model(): void
    {
        // WHY: without a model prop the component is used in a plain form; the
        // initial value must pre-populate hidden inputs for the first submit.
        $html = Blade::render(
            '<x-gp247::searchable-select name="brand_id" :value="7"
                :options="[[\'id\'=>7,\'label\'=>\'Nike\']]" />'
        );

        $this->assertStringContainsString('data-ss-hidden', $html);
        $this->assertStringContainsString('value="7"', $html);
    }

    public function test_searchable_select_required_shows_asterisk(): void
    {
        $html = Blade::render(
            '<x-gp247::searchable-select name="x" label="Brand" :required="true"
                :options="[]" />'
        );

        $this->assertStringContainsString('text-red-500', $html);
        $this->assertStringContainsString('*', $html);
    }

    public function test_searchable_select_clearable_default_renders_clear_button(): void
    {
        $html = Blade::render(
            '<x-gp247::searchable-select name="lang" :value="1"
                :options="[[\'id\'=>1,\'label\'=>\'English\']]" />'
        );

        $this->assertStringContainsString('clear()', $html);
    }

    public function test_searchable_select_not_clearable_hides_clear_button(): void
    {
        // WHY: fields like store language/currency/template must always hold a
        // value — only switching to another option is allowed, never clearing.
        $html = Blade::render(
            '<x-gp247::searchable-select name="lang" :value="1" :clearable="false"
                :options="[[\'id\'=>1,\'label\'=>\'English\']]" />'
        );

        $this->assertStringNotContainsString('clear()', $html);
    }

    public function test_searchable_select_dispatches_change_event_on_commit(): void
    {
        // WHY: on a Blade+Alpine-only screen (no Livewire) the parent must learn
        // when the value changes to run follow-up logic (e.g. customer auto-fill on
        // the admin order-create screen). _commit() dispatches a bubbling
        // `gp247-ss:change` CustomEvent so the parent can bind @gp247-ss:change.
        // The Alpine factory lives in an @assets block that Livewire emits once per
        // real response (not through standalone Blade::render), so assert the shared
        // component template itself wires the dispatch.
        // Amendment 2026-09-07 — RISK-TECH-searchable-select-no-change-event.
        $source = file_get_contents(view('gp247-admin::components.searchable-select')->getPath());

        $this->assertStringContainsString("\$dispatch('gp247-ss:change'", $source);
    }

    public function test_searchable_select_passes_through_data_testid(): void
    {
        // WHY: the component owns its DOM (wire:ignore) and does not spread
        // $attributes, so a data-testid passed by the caller was previously dropped.
        // It must reach the real interactive input for a stable E2E selector.
        $html = Blade::render(
            '<x-gp247::searchable-select name="customer_id"
                data-testid="shop-admin-order-create-customer-select"
                :options="[[\'id\'=>1,\'label\'=>\'a@b.c\']]" />'
        );

        $this->assertStringContainsString('data-testid="shop-admin-order-create-customer-select"', $html);
    }
}

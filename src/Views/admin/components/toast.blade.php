{{--
    GP247 notifications block (ADR-005) — top-right stacked toasts, one reusable
    container for every screen. Listens for the browser `notify` event emitted by
    GP247AdminComponent::notify($type, $message) and supports the inherited types
    success | error | info | warning (each with its own colour + icon). Toasts
    auto-dismiss and can be closed manually. Place once in the admin layout.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-007
    @aidlc-adr ADR-005
--}}
<div
    x-data="{
        toasts: [],
        styles: {
            success: { ring: 'border-l-green-500',  icon: 'fa-circle-check',        iconColor: 'text-green-500',  title: '{{ gp247_language_render('admin.core.toast_success') }}' },
            error:   { ring: 'border-l-red-500',    icon: 'fa-circle-xmark',        iconColor: 'text-red-500',    title: '{{ gp247_language_render('admin.core.toast_error') }}' },
            warning: { ring: 'border-l-amber-500',  icon: 'fa-triangle-exclamation', iconColor: 'text-amber-500', title: '{{ gp247_language_render('admin.core.toast_warning') }}' },
            info:    { ring: 'border-l-blue-500',   icon: 'fa-circle-info',         iconColor: 'text-blue-500',   title: '{{ gp247_language_render('admin.core.toast_info') }}' },
        },
        push(raw) {
            // Tolerate Livewire detail shapes: {type,message}, [ {..} ] or a string.
            const d = Array.isArray(raw) ? (raw[0] || {}) : (raw || {});
            const type = this.styles[d.type] ? d.type : 'info';
            const message = typeof d === 'string' ? d : (d.message || '');
            const id = Date.now() + Math.random();
            this.toasts.push({ id, type, message });
            setTimeout(() => this.dismiss(id), 4000);
        },
        dismiss(id) {
            this.toasts = this.toasts.filter(t => t.id !== id);
        },
    }"
    x-on:notify.window="push($event.detail)"
    class="fixed right-4 top-4 z-50 flex w-96 max-w-[92vw] flex-col gap-2"
    aria-live="polite"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-x-4"
            x-transition:enter-end="opacity-100 translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="flex items-start gap-3 rounded-lg border border-l-4 border-gray-200 bg-white px-4 py-3 shadow-lg dark:border-gray-700 dark:bg-gray-800"
            :class="styles[toast.type].ring"
            role="alert"
        >
            <i class="fas mt-0.5" :class="[styles[toast.type].icon, styles[toast.type].iconColor]"></i>
            <div class="flex-1">
                <p class="text-sm font-semibold text-gray-800 dark:text-gray-100" x-text="styles[toast.type].title"></p>
                <p class="text-sm text-gray-600 dark:text-gray-300" x-text="toast.message"></p>
            </div>
            <button type="button" class="text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200"
                x-on:click="dismiss(toast.id)" aria-label="{{ gp247_language_render('admin.core.close') }}">&times;</button>
        </div>
    </template>
</div>

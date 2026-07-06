{{--
    Sample admin screen for a GP247 plugin rendered via Livewire inside the
    shared TailAdmin shell. Replace this markup with the plugin's own UI.
    Use <x-gp247::*> components and gp247_language_render() for i18n text;
    do NOT introduce jQuery/AdminLTE widgets (TailAdmin-first).

    @aidlc-unit plugin-manager
    @aidlc-story US-PLG-004
--}}
<div class="space-y-5">
    <x-gp247::card :title="trans('Plugins/Extension_Key::lang.title')">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ trans('Plugins/Extension_Key::lang.title') }} — Your content here!
        </p>
    </x-gp247::card>
</div>

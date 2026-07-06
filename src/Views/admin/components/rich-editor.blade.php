{{--
    GP247 rich-text editor (ADR-005 / ADR-006) — thin wrapper over TinyMCE 6
    (MIT, self-hosted) that keeps the editor library behind a single component, the
    same way <x-gp247::media-input> hides LFM. Image/file management reuses the GP247
    file manager (LFM): TinyMCE's `file_picker_callback` opens the very same LFM popup
    the media picker uses (window.SetUrl callback) and returns the chosen URL — so all
    uploads still go through LFM, exactly like the legacy editor, with no jQuery.

    Why TinyMCE 6: MIT-licensed and free for the community (no license key for
    self-host, no GPL copyleft), simple, and LFM integration is first-class. Pinned
    to v6 (self-hosted asset) because v7+ moved to GPL/commercial.

    Livewire sync: the editor DOM is wrapped in wire:ignore (so re-renders never
    destroy TinyMCE); the value is seeded from the bound property on init and written
    back on blur (mirroring the screens' wire:model.live.blur). TinyMCE is not a
    native input, so the bound property is passed explicitly via the `model` prop
    instead of wire:model.

    The TinyMCE build is self-hosted (published from the package to public/, no CDN)
    per ADR-004 / shared-host constraint.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-004
    @aidlc-adr ADR-005, ADR-006

    @props
      - model (string): the Livewire property path to bind, e.g. "desc.en.maintain_content".
      - label (string|null): field label.
      - type (string): LFM folder category for in-editor uploads (drives folder +
        allowed mime). Must match a key in config('lfm.folder_categories').
        Default "content" (the editor-content folder); there is no "image" category.
      - error (string|null): validation message.
      - help (string|null): muted helper text.
      - required (bool): mark label with asterisk.
--}}
@props([
    'model' => null,
    'label' => null,
    'type' => 'content',
    'error' => null,
    'help' => null,
    'required' => false,
])

@php
    // Same LFM endpoint the legacy admin + media-input use (admin base + lfm prefix).
    $lfmPrefix = gp247_route_admin('admin.home') . '/' . config('lfm.url_prefix');
    // Self-hosted TinyMCE asset root (skins/themes/models/icons/plugins live here).
    $tinymceBase = gp247_file('GP247/Core/AdminShell/vendor/tinymce');
@endphp

{{-- Load TinyMCE once per page and register the Alpine factory before Alpine boots,
     regardless of how many editors a screen renders. --}}
@assets
    <script src="{{ gp247_file('GP247/Core/AdminShell/vendor/tinymce/tinymce.min.js') }}"></script>
    <script>
        // WHY: this asset block evaluates AFTER Alpine boots on Livewire
        // wire:navigate (SPA) visits, so the alpine:init event has already
        // fired and an alpine:init listener would never run — leaving
        // gp247RichEditor undefined until a hard reload. Register the factory
        // immediately when Alpine already exists, otherwise wait for alpine:init
        // (the full-page-load path). This makes registration order-independent.
        //
        // NOTE: never write a literal "at-assets"/"at-endassets" token in this
        // inline script — Blade parses those as directives even inside JS, which
        // truncates the script and breaks the page.
        (function () {
            const define = () => {
                window.Alpine.data('gp247RichEditor', (model, lfmPrefix, baseUrl, mediaType) => ({
                editor: null,

                init() {
                    if (typeof tinymce === 'undefined') {
                        console.error('GP247 rich-editor: TinyMCE build not loaded.');
                        return;
                    }

                    const self = this;
                    tinymce.init({
                        target: this.$refs.editor,
                        base_url: baseUrl,
                        suffix: '.min',
                        menubar: false,
                        promotion: false,
                        branding: false,
                        height: 320,
                        plugins: 'lists link image table code',
                        toolbar: 'undo redo | blocks fontsizeinput | bold italic underline | forecolor backcolor | bullist numlist | link image table | code',
                        file_picker_types: 'image file',

                        // WHY: route every file pick through the same LFM popup the
                        // media picker uses, so image management stays in LFM.
                        file_picker_callback: (callback, value, meta) => {
                            window.SetUrl = (items) => {
                                if (items && items.length) {
                                    callback(items[0].url, { title: items[0].name || '' });
                                }
                            };
                            // WHY: route every pick through the configured LFM folder
                            // category (not the bogus "image"/"file"), so editor
                            // uploads land in the right folder with the right mime rules.
                            window.open(lfmPrefix + '?type=' + mediaType, 'GP247FileManager', 'width=900,height=600');
                        },

                        setup: (editor) => {
                            self.editor = editor;
                            editor.on('init', () => editor.setContent(self.$wire.get(model) || ''));
                            // WHY: persist on blur to match the screens' wire:model.live.blur.
                            editor.on('blur', () => self.$wire.set(model, editor.getContent()));
                        },
                    });
                },

                destroy() {
                    if (this.editor) {
                        this.editor.remove();
                        this.editor = null;
                    }
                },
                }));
            };

            if (window.Alpine) {
                define();
            } else {
                document.addEventListener('alpine:init', define);
            }
        })();
    </script>
@endassets

<div class="space-y-1">
    @if ($label)
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
            {{ $label }}
            @if ($required)<span class="text-red-500">*</span>@endif
        </label>
    @endif

    <div wire:ignore x-data="gp247RichEditor(@js($model), @js($lfmPrefix), @js($tinymceBase), @js($type))">
        <textarea x-ref="editor"></textarea>
    </div>

    @if ($error)
        <p class="text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
    @elseif ($help)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $help }}</p>
    @endif
</div>

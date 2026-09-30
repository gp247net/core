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
    destroy TinyMCE), which also means Livewire never updates it — so, like every
    wire:ignore control in core (datepicker, searchable-select), it syncs both ways
    itself:
      - server → editor: seeded from the bound property on init, then $wire.$watch
        reloads it whenever the property changes server-side (two-panel screens edit
        records in place: editRow / cancel / save-and-reset change the form without
        remounting this component);
      - editor → server: written back on blur and on submit, but ONLY when the author
        changed the content (isDirty). A value loaded from the server is clean, so an
        editor that is somehow showing stale content can never save it over the
        record (RISK-TECH-rich-editor-stale-seed).
    TinyMCE is not a native input, so the bound property is passed explicitly via the
    `model` prop instead of wire:model.

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
            // WHY: TinyMCE proxies focus/blur through its iframe, so its own
            // 'blur' event fires a tick later than the browser's native
            // mousedown→blur→click sequence on a Save button. Clicking Save
            // right after typing races the blur-triggered $wire.set() request:
            // the in-flight request can flip wire:loading.attr="disabled" on
            // the button before its click is processed (first click does
            // nothing), and even when it isn't disabled, wire:submit could
            // still commit the pre-edit (stale) content. Track every live
            // editor here and force-flush content into $wire synchronously
            // during the submit event's CAPTURE phase — before Livewire's own
            // wire:submit (bubble-phase) listener runs — so the fresh content
            // is always part of the save() request, first click, every time.
            //
            // Only editors the author actually changed are flushed: an untouched
            // editor has nothing to add, and writing it back is how a stale editor
            // used to wipe or swap a record's content on Save.
            const liveEditors = new Set();
            document.addEventListener('submit', () => {
                liveEditors.forEach((entry) => {
                    if (entry.editor && !entry.editor.removed && entry.editor.isDirty()) {
                        entry.wire.set(entry.model, entry.editor.getContent());
                    }
                });
            }, true);

            const define = () => {
                window.Alpine.data('gp247RichEditor', (model, lfmPrefix, baseUrl, mediaType) => ({
                editor: null,
                _entry: null,

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
                        // WHY: 480 (not the old fixed 320) gives a roomier default
                        // writing area; combined with the `fullscreen` button below,
                        // long content no longer has to be edited inside a cramped box.
                        height: 480,
                        // WHY: every plugin here ships in the self-hosted TinyMCE build
                        // (no Node/CDN needed — ADR-004 / NFR-AVAIL-001), so enabling them
                        // is config-only. fullscreen = expand to whole viewport;
                        // preview = render preview; emoticons = emoji picker;
                        // charmap = special/mathematical symbols (± × ÷ ∑ √ ∞ ≈ ≤ ≥ …).
                        // Note: a WYSIWYG formula/equation editor is a premium TinyMCE
                        // plugin and is intentionally NOT bundled — charmap covers symbols.
                        plugins: 'lists link image table code fullscreen preview emoticons charmap',
                        // WHY: expose a "Div" block so authors can wrap content in a plain
                        // <div>; the rest mirrors TinyMCE's default block list.
                        block_formats: 'Paragraph=p; Heading 1=h1; Heading 2=h2; Heading 3=h3; Heading 4=h4; Heading 5=h5; Heading 6=h6; Preformatted=pre; Div=div',
                        // WHY: blockquote/align*/copy/removeformat are core TinyMCE buttons
                        // (no plugin); grouped with the new plugin buttons so quote, text
                        // alignment, copy-to-clipboard and clear-formatting are one click away.
                        toolbar: 'undo redo | copy removeformat | blocks fontsizeinput | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist blockquote | link image table charmap emoticons | preview code fullscreen',
                        // WHY: narrow panels (e.g. the 2-column form/list layout) collapse
                        // the toolbar into a "..." overflow menu in floating mode, hiding
                        // the "code" (Source code) button that's the only way to insert
                        // raw HTML — wrap onto multiple rows instead so every button,
                        // including code, stays directly visible and discoverable.
                        toolbar_mode: 'wrap',
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
                            self._entry = { editor, wire: self.$wire, model };
                            liveEditors.add(self._entry);
                            editor.on('init', () => self.load(self.$wire.get(model)));
                            // WHY: persist on blur to match the screens' wire:model.live.blur
                            // (covers non-submit flows, e.g. WebsiteInfo's live inline save).
                            // The submit-capture flush above is the authoritative sync for
                            // form submission — this is a secondary/best-effort path.
                            // The dirty flag is NOT cleared here, so the submit flush still
                            // sends the edit if this blur request is still in flight.
                            editor.on('blur', () => {
                                if (editor.isDirty()) {
                                    self.$wire.set(model, editor.getContent());
                                }
                            });
                        },
                    });

                    // WHY: wire:ignore keeps Livewire from ever touching the editor, so
                    // follow server-side changes of the bound value ourselves (same
                    // contract as the datepicker and searchable-select). Skipped while
                    // the author is typing, and when the value is what the editor
                    // already holds (e.g. the echo of our own blur write-back).
                    self.$wire.$watch(model, (value) => {
                        const editor = self.editor;
                        if (!editor || editor.removed || !editor.initialized || editor.hasFocus()) {
                            return;
                        }
                        if ((value || '') === editor.getContent()) {
                            return;
                        }
                        self.load(value);
                    });
                },

                // Show a server value as the editor's clean starting point: undo must
                // not step back into the previous record, and the value only goes
                // back to the server once the author changes it.
                load(value) {
                    const editor = this.editor;
                    if (!editor || editor.removed) {
                        return;
                    }
                    editor.setContent(value || '');
                    editor.undoManager.clear();
                    editor.setDirty(false);
                },

                destroy() {
                    if (this._entry) {
                        liveEditors.delete(this._entry);
                        this._entry = null;
                    }
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

{{-- WHY: pass caller attributes (e.g. data-testid) through to the wrapper, the same
     way searchable-select does, so E2E can target one editor among several. --}}
<div {{ $attributes->merge(['class' => 'space-y-1']) }}>
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

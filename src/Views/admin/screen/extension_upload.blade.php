@extends('gp247-admin::layouts.plain')

@section('main')
<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">

    {{-- Tab navigation --}}
    <div class="border-b border-gray-200 px-1 dark:border-gray-700">
        <nav class="-mb-px flex flex-wrap">
            <a href="{{ $listUrlAction['urlLocal'] }}"
               class="inline-flex items-center gap-1.5 border-b-2 border-transparent px-5 py-3 text-sm font-medium text-gray-500 transition hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:border-gray-500 dark:hover:text-gray-200">
                <i class="fas fa-puzzle-piece text-xs"></i>
                {{ gp247_language_render('admin.extension.local') }}
            </a>
            @if ($configExtension)
            <a href="{{ $listUrlAction['urlOnline'] }}"
               class="inline-flex items-center gap-1.5 border-b-2 border-transparent px-5 py-3 text-sm font-medium text-gray-500 transition hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:border-gray-500 dark:hover:text-gray-200">
                <i class="fas fa-globe text-xs"></i>
                {{ gp247_language_render('admin.extension.online') }}
            </a>
            @endif
            <span class="inline-flex items-center gap-1.5 border-b-2 border-blue-500 px-5 py-3 text-sm font-medium text-blue-600 dark:border-blue-400 dark:text-blue-400">
                <i class="fas fa-upload text-xs"></i>
                {{ gp247_language_render('admin.extension.import') }}
            </span>
        </nav>
    </div>

    {{-- Upload form --}}
    <div class="p-6">
        <form action="{{ $urlAction }}" method="POST" enctype="multipart/form-data" id="import-form">
            @csrf
            <div class="mx-auto max-w-lg space-y-4">

                {{-- Error / Session alert --}}
                @if ($errors->has('file') || session('error'))
                    <div class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800/50 dark:bg-red-900/20">
                        <i class="fas fa-circle-xmark mt-0.5 text-red-500"></i>
                        <div class="text-sm text-red-700 dark:text-red-300">
                            {{ $errors->first('file') ?: session('error') }}
                        </div>
                    </div>
                @endif

                {{-- Drop zone --}}
                <div x-data="gp247Upload({{ $maxSizeInBytes }})"
                     x-on:dragover.prevent="dragging = true"
                     x-on:dragleave.prevent="dragging = false"
                     x-on:drop.prevent="handleDrop($event)"
                     :class="dragging ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/10' : 'border-gray-300 dark:border-gray-600 hover:border-blue-400'"
                     class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed p-10 text-center transition">

                    <i class="fas fa-file-zipper mb-3 text-4xl text-gray-300 dark:text-gray-600"
                       x-show="!fileName" x-cloak></i>
                    <i class="fas fa-file-zipper mb-3 text-4xl text-blue-500"
                       x-show="fileName && !fileError" x-cloak></i>
                    <i class="fas fa-exclamation-circle mb-3 text-4xl text-red-500"
                       x-show="fileError" x-cloak></i>

                    <p class="mb-1 text-sm font-medium text-gray-700 dark:text-gray-200"
                       x-text="fileName ? fileName : '{{ gp247_language_render('action.choose_file') }}'"></p>

                    <p class="text-xs text-gray-500 dark:text-gray-400"
                       x-show="!fileName" x-cloak>
                        {{ gp247_language_render('admin.extension.import_note') }}
                    </p>
                    <p class="text-xs font-medium"
                       x-show="fileName && !fileError" x-cloak
                       :class="fileError ? 'text-red-600' : 'text-green-600'"
                       x-text="fileSizeLabel"></p>
                    <p class="text-xs text-red-600 dark:text-red-400"
                       x-show="fileError" x-cloak
                       x-text="fileError"></p>

                    <label class="mt-4 cursor-pointer">
                        <span class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                            <i class="fas fa-folder-open"></i>
                            {{ gp247_language_render('action.choose_file') }}
                        </span>
                        <input type="file" id="input-file" name="file" accept=".zip,application/zip,application/x-zip-compressed"
                               class="sr-only" required x-on:change="handleChange($event)">
                    </label>
                </div>

                {{-- File limits info --}}
                <div class="flex items-center gap-3 rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-700/50">
                    <i class="fas fa-circle-info text-blue-500"></i>
                    <div class="text-xs text-gray-600 dark:text-gray-300">
                        <span class="font-medium">Max {{ $maxSizeInMB }} MB</span>
                        <span class="ml-2 text-gray-400">(upload_max={{ $uploadMaxFilesize }}, post_max={{ $postMaxSize }})</span>
                    </div>
                </div>

                {{-- Submit --}}
                <div class="flex justify-end">
                    <button type="button" id="btn-upload"
                        onclick="submitUpload()"
                        class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50">
                        <i class="fas fa-upload"></i>
                        {{ gp247_language_render('admin.extension.import_submit') }}
                    </button>
                </div>

            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
function gp247Upload(maxBytes) {
    return {
        dragging:    false,
        fileName:    '',
        fileSizeLabel: '',
        fileError:   '',
        maxBytes,

        handleChange(e) {
            const file = e.target.files[0];
            if (file) this.processFile(file);
        },

        handleDrop(e) {
            this.dragging = false;
            const file = e.dataTransfer.files[0];
            if (!file) return;
            const dt = new DataTransfer();
            dt.items.add(file);
            document.getElementById('input-file').files = dt.files;
            this.processFile(file);
        },

        processFile(file) {
            this.fileName  = file.name;
            this.fileError = '';
            const isZip = ['application/zip','application/x-zip-compressed','application/x-zip'].includes(file.type)
                       || file.name.toLowerCase().endsWith('.zip');
            if (!isZip) {
                this.fileError     = 'Only ZIP archives are accepted (.zip)';
                this.fileSizeLabel = '';
                document.getElementById('btn-upload').disabled = true;
                return;
            }
            const kb = file.size / 1024;
            const mb = kb / 1024;
            this.fileSizeLabel = mb >= 1 ? mb.toFixed(2) + ' MB' : kb.toFixed(1) + ' KB';
            if (file.size > this.maxBytes) {
                this.fileError = 'File exceeds maximum size of ' + (this.maxBytes / 1048576).toFixed(0) + ' MB';
                document.getElementById('btn-upload').disabled = true;
            } else {
                document.getElementById('btn-upload').disabled = false;
            }
        },
    };
}

function submitUpload() {
    const file = document.getElementById('input-file').files[0];
    if (!file) { alert('{{ gp247_language_render('action.choose_file') }}'); return; }
    document.getElementById('gp247-page-loading').style.display = 'flex';
    document.getElementById('import-form').submit();
}
</script>
@endpush

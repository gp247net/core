{{--
    Read-only server information (ADR-005): PHP runtime settings, loaded
    extensions, installed Composer packages. UI text via gp247_language_render.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-002
    @aidlc-adr ADR-005

    Variables:
      - $phpInfo    (array  label => value)
      - $extensions (string[] loaded extension names)
      - $packages   (array  package => version)
--}}
<div class="space-y-5">

    {{-- PHP runtime --}}
    <x-gp247::card title="PHP">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                    @foreach ($phpInfo as $label => $value)
                        <tr>
                            <td class="w-56 py-2.5 pr-4 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ str_replace('_', ' ', $label) }}
                            </td>
                            <td class="py-2.5 font-mono text-sm text-gray-700 dark:text-gray-200">{{ $value !== '' ? $value : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-gp247::card>

    {{-- Loaded extensions --}}
    <x-gp247::card>
        <x-slot:header>
            <div class="flex w-full items-center justify-between">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">
                    <i class="fas fa-puzzle-piece mr-1.5 text-gray-400"></i>Extensions
                </h3>
                <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                    {{ count($extensions) }}
                </span>
            </div>
        </x-slot:header>
        <div class="flex flex-wrap gap-1.5">
            @foreach ($extensions as $ext)
                <span class="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 font-mono text-xs text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-700/50 dark:text-gray-300 dark:ring-gray-600">{{ $ext }}</span>
            @endforeach
        </div>
    </x-gp247::card>

    {{-- Installed packages --}}
    <x-gp247::card>
        <x-slot:header>
            <div class="flex w-full items-center justify-between">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">
                    <i class="fas fa-box mr-1.5 text-gray-400"></i>Packages
                </h3>
                <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                    {{ count($packages) }}
                </span>
            </div>
        </x-slot:header>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                    @foreach ($packages as $name => $version)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="py-2 pr-4 font-mono text-xs text-gray-700 dark:text-gray-200">{{ $name }}</td>
                            <td class="py-2 text-right">
                                <x-gp247::badge color="blue">{{ $version }}</x-gp247::badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-gp247::card>
</div>

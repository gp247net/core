{{--
    GP247 web installer wizard — multi-step Livewire component.

    No GP247 helpers may be used here (not available before installation).

    @aidlc-unit installer-deploy
    @aidlc-story US-DEP-001
--}}
<div>
    {{-- Step indicator --}}
    <div class="flex items-center justify-center gap-2 mb-6">
        @foreach([1 => 'Environment', 2 => 'Database', 3 => 'Account', 4 => 'Installing', 5 => 'Done'] as $n => $label)
            <div class="flex items-center gap-1">
                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold
                    {{ $step >= $n ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-500' }}">
                    {{ $n }}
                </div>
                @if($n < 5)
                    <div class="w-6 h-0.5 {{ $step > $n ? 'bg-blue-600' : 'bg-gray-200' }}"></div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Step 1: Environment Check --}}
    @if($step === 1)
        <h2 class="text-lg font-semibold text-gray-700 mb-4">Step 1 — Environment Check</h2>

        @if(!empty($envErrors))
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                <p class="text-sm font-medium text-red-700 mb-2">Please fix the following issues:</p>
                <ul class="list-disc list-inside text-sm text-red-600 space-y-1">
                    @foreach($envErrors as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="text-sm text-gray-500 mb-4">
            Click the button to check your server meets GP247's requirements.
        </p>

        <button wire:click="goToStep2"
                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg transition">
            Check Environment &amp; Continue
        </button>
    @endif

    {{-- Step 2: Database --}}
    @if($step === 2)
        <h2 class="text-lg font-semibold text-gray-700 mb-4">Step 2 — Database Configuration</h2>

        @if($dbError)
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4 text-sm text-red-600">
                {{ $dbError }}
            </div>
        @endif

        <div class="space-y-3">
            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">DB Host</label>
                    <input wire:model="dbHost" type="text" placeholder="127.0.0.1"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    @error('dbHost') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Port</label>
                    <input wire:model="dbPort" type="text" placeholder="3306"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    @error('dbPort') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Database Name</label>
                <input wire:model="dbName" type="text" placeholder="gp247"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                @error('dbName') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Username</label>
                <input wire:model="dbUser" type="text"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                @error('dbUser') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Password</label>
                <input wire:model="dbPass" type="password"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
        </div>

        <button wire:click="goToStep3"
                class="w-full mt-5 bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg transition">
            Test Connection &amp; Continue
        </button>
    @endif

    {{-- Step 3: Site & Admin Account --}}
    @if($step === 3)
        <h2 class="text-lg font-semibold text-gray-700 mb-4">Step 3 — Site &amp; Admin Account</h2>

        <div class="space-y-3">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Site Name</label>
                <input wire:model="siteName" type="text"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                @error('siteName') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Admin Name</label>
                <input wire:model="adminName" type="text"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                @error('adminName') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Admin Email</label>
                <input wire:model="adminEmail" type="email"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                @error('adminEmail') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Admin Password (min 8 chars)</label>
                <input wire:model="adminPassword" type="password"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                @error('adminPassword') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
            </div>
        </div>

        <button wire:click="goToStep4"
                class="w-full mt-5 bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg transition">
            Continue to Installation
        </button>
    @endif

    {{-- Step 4: Installing --}}
    @if($step === 4)
        <h2 class="text-lg font-semibold text-gray-700 mb-4">Step 4 — Installation</h2>

        @if($installError)
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4 text-sm text-red-600">
                {{ $installError }}
            </div>
        @endif

        <p class="text-sm text-gray-500 mb-4">
            This will run database migrations, seed default data, and create your admin account.
            <br>
            <span class="text-yellow-600 font-medium">This may take up to a minute — please do not close this page.</span>
        </p>

        <button wire:click="runInstall" wire:loading.attr="disabled"
                class="w-full bg-green-600 hover:bg-green-700 disabled:opacity-60 text-white font-medium py-2.5 rounded-lg transition">
            <span wire:loading.remove>Run Installation</span>
            <span wire:loading>Installing… please wait</span>
        </button>
    @endif

    {{-- Step 5: Done --}}
    @if($step === 5)
        <div class="text-center py-4">
            <div class="text-green-500 text-5xl mb-3">✓</div>
            <h2 class="text-xl font-semibold text-gray-800 mb-2">Installation Complete!</h2>
            <p class="text-sm text-gray-500 mb-6">GP247 has been installed successfully.</p>

            <a href="{{ url(config('gp247.admin_prefix', 'gp247_admin') . '/auth/login') }}"
               class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-medium px-6 py-2.5 rounded-lg transition">
                Go to Admin Login
            </a>
        </div>
    @endif
</div>

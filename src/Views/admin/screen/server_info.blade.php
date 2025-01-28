@extends('gp247-core::layout')

@section('main')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header with-border">
                    <h3 class="card-title">{{ gp247_language_render('admin.server_info') }}</h3>
                </div>

                <div class="card-body">
                    <!-- GP247 Information -->
                    <div class="mb-4 p-4 bg-light rounded">
                        <pre style="line-height: 1.2; color: #000; font-weight: italic; font-size: 1.1em; margin-bottom: 0; background: transparent; border: none;">
          _____  _____     ___  _  _   _____ 
         / ____|  __ \   |__ \| || | |___  |
        | |  __| |__) |     ) | || |_   / / 
        | | |_ |  ___/     / /|__   _| / /  
        | |__| | |        / /_   | |  / /   
         \_____|_|       |____|  |_| /_/    
         Welcome to GP247 {{ config('gp247.core') }} [{{ gp247_composer_get_package_installed()['gp247/core'] ?? '' }}]</pre>
                    </div>

                    <!-- PHP Information -->
                    <h4 class="mb-3">PHP Information</h4>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <tbody>
                                @foreach($phpInfo as $key => $value)
                                    <tr>
                                        <td style="width: 200px"><strong>{{ ucwords(str_replace('_', ' ', $key)) }}</strong></td>
                                        <td>{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- PHP Extensions -->
                    <h4 class="mt-4 mb-3">PHP Extensions</h4>
                    <div class="table-responsive">
                        <div class="p-3">
                            @foreach(array_chunk($extensions, 4) as $chunk)
                                <div class="mb-2">
                                    @foreach($chunk as $extension)
                                        <span class="btn btn-sm btn-outline-secondary me-2 mb-2 p-2">{{ $extension }}</span>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Composer Packages -->
                    <h4 class="mt-4 mb-3">Composer Packages</h4>
                    <div class="table-responsive">
                        <div class="p-3">
                            <div class="list-group">
                                @foreach($packages as $package => $version)
                                    @if(strpos($package, 'gp247/') === 0)
                                        <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                            <div class="fw-medium text-primary">{{ $package }}</div>
                                            <span class="btn btn-sm btn-outline-primary">{{ $version }}</span>
                                        </div>
                                    @else
                                        <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                            <div class="fw-medium">{{ $package }}</div>
                                            @if($version)
                                                <span class="btn btn-sm btn-outline-secondary">{{ $version }}</span>
                                            @else
                                                
                                            @endif
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection 
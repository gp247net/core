@extends('gp247-core::layout')

@section('main')
<div class="row">
    <div class="col-md-12">
        <div class="card card-primary card-outline card-outline-tabs">
            <div class="card-header p-0 border-bottom-0">
                <ul class="nav nav-tabs" id="custom-tabs-four-tab" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link" href="{{ $listUrlAction['urlLocal'] }}">{{ gp247_language_render('admin.extension.local') }}</a>
                    </li>
                    @if ($configExtension)
                    <li class="nav-item">
                        <a class="nav-link" href="{{ $listUrlAction['urlOnline'] }}">{{ gp247_language_render('admin.extension.online') }}</a>
                    </li>
                    @endif
                    <li class="nav-item">
                        <a class="nav-link active" href="#" aria-controls="custom-tabs-four-import" aria-selected="true">
                            <span><i class="fas fa-save"></i> {{ gp247_language_render('admin.extension.import') }}</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="card-body">
                <div class="tab-content" id="custom-tabs-four-tabContent">
                    <form action="{{ $urlAction }}" method="post" accept-charset="UTF-8" id="import-product" enctype="multipart/form-data">
                        @csrf
                        <div class="row justify-content-center">
                            <div class="col-lg-6 col-md-8">
                                
                                <div class="form-group{{ $errors->has('file') ? ' text-red' : '' }}">
                                    <label for="input-file" class="font-weight-bold">
                                        <i class="fas fa-file-archive text-primary"></i> {{ gp247_language_render('action.choose_file') }}
                                    </label>
                                    <div class="input-group">
                                        <div class="custom-file">
                                            <input type="file" id="input-file" class="custom-file-input" accept=".zip,application/zip,application/x-zip-compressed" required="required" name="file">
                                            <label class="custom-file-label" for="input-file">{{ gp247_language_render('action.choose_file') }}</label>
                                        </div>
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-success button-upload">
                                                <i class="fas fa-upload"></i> {{ gp247_language_render('admin.extension.import_submit') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                @if ($errors->has('file'))
                                <div class="alert alert-danger alert-dismissible">
                                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                                    <h5><i class="icon fas fa-ban"></i> Error!</h5>
                                    {{ $errors->first('file') }}
                                </div>
                                @elseif(session('error'))
                                <div class="alert alert-danger alert-dismissible">
                                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                                    <h5><i class="icon fas fa-ban"></i> Error!</h5>
                                    {{ session('error') }}
                                </div>
                                @else
                                <div class="info-box bg-light" id="file-size-info">
                                    <span class="info-box-icon bg-info"><i class="fas fa-info-circle"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">{!! gp247_language_render('admin.extension.import_note') !!}</span>
                                        <span class="info-box-number"><strong>Maximum file size:</strong> {{ $maxSizeInMB }} MB</span>
                                        <span class="progress-description">
                                            <small class="text-muted">Server limits: upload_max={{ $uploadMaxFilesize }}, post_max={{ $postMaxSize }}</small>
                                        </span>
                                    </div>
                                </div>
                                @endif

                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('styles')
<style>
    /* File input and button alignment fix - small */
    .input-group .custom-file,
    .input-group .custom-file-input,
    .input-group .custom-file-label {
        height: 36px;
    }
    
    .input-group .custom-file-label {
        padding: 0.25rem 0.75rem;
        font-size: 0.875rem;
        line-height: 1.5;
    }
    
    .input-group .custom-file-label::after {
        height: 34px;
        padding: 0.25rem 0.75rem;
        line-height: 1.5;
        font-size: 0.85rem;
    }
    
    /* Upload button styling - small */
    .button-upload {
        font-weight: 600;
        padding: 0.25rem 1rem;
        font-size: 0.875rem;
        height: 36px;
        line-height: 1.5;
    }
    
    /* Label styling - small */
    label[for="input-file"] {
        margin-bottom: 0.4rem;
        font-size: 0.875rem;
    }
    
    /* Form group spacing */
    .form-group {
        margin-bottom: 0.75rem;
    }
    
    /* Info box styling - small */
    #file-size-info {
        box-shadow: 0 1px 2px rgba(0,0,0,0.08);
        border-radius: 0.25rem;
        margin-top: 0.75rem;
    }
    
    #file-size-info .info-box-icon {
        width: 60px;
        font-size: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    #file-size-info .info-box-content {
        padding: 0.4rem 0.5rem;
    }
    
    #file-size-info .info-box-text {
        font-size: 0.8rem;
        color: #495057;
        display: block;
        margin-bottom: 0.2rem;
    }
    
    #file-size-info .info-box-number {
        font-size: 0.85rem;
        font-weight: 500;
        color: #212529;
        display: block;
        margin-bottom: 0.2rem;
    }
    
    #file-size-info .progress-description {
        font-size: 0.75rem;
    }
    
    /* Alert spacing - small */
    .alert {
        margin-top: 0.75rem;
        padding: 0.75rem 1rem;
    }
    
    .alert h5 {
        font-size: 0.9rem;
    }
    
    /* Success state for info box */
    #file-size-info.bg-success {
        background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%) !important;
        border-left: 3px solid #28a745;
    }
    
    #file-size-info.bg-success .info-box-icon {
        background: #28a745 !important;
    }
    
    /* Danger state for info box */
    #file-size-info.bg-danger-light {
        background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%) !important;
        border-left: 3px solid #dc3545;
    }
    
    #file-size-info.bg-danger-light .info-box-icon {
        background: #dc3545 !important;
    }
</style>
@endpush

@push('scripts')
    <script>
        // Get server limits from Controller (in bytes for JavaScript comparison)
        const maxAllowedSize = {{ $maxSizeInBytes }};
        
        // Format bytes to human readable
        function formatBytes(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        // Show file size when selected
        $('#input-file').on('change', function() {
            const fileName = $(this).val().split('\\').pop();
            $(this).next('.custom-file-label').html(fileName);
            
            if (this.files && this.files[0]) {
                const fileSize = this.files[0].size;
                const fileType = this.files[0].type;
                const fileSizeFormatted = formatBytes(fileSize);
                const maxSizeFormatted = formatBytes(maxAllowedSize);
                
                // Check file type
                const validTypes = ['application/zip', 'application/x-zip-compressed', 'application/x-zip', 'application/octet-stream'];
                const isValidType = validTypes.includes(fileType) || fileName.toLowerCase().endsWith('.zip');
                
                if (!isValidType) {
                    // Update info-box for invalid file type
                    $('#file-size-info').removeClass('bg-light bg-success').addClass('bg-danger-light');
                    $('#file-size-info .info-box-icon').removeClass('bg-info bg-success').addClass('bg-danger').html('<i class="fas fa-exclamation-triangle"></i>');
                    $('#file-size-info .info-box-text').html('Invalid file type!');
                    $('#file-size-info .info-box-number').html('<strong>' + fileName + '</strong>');
                    $('#file-size-info .progress-description').html('<span style="color: #721c24;">File must be a ZIP archive (.zip)</span>');
                    $('.button-upload').prop('disabled', true);
                    return;
                }
                
                // Check file size BEFORE upload
                if (fileSize > maxAllowedSize) {
                    // Update info-box for oversized file
                    $('#file-size-info').removeClass('bg-light bg-success').addClass('bg-danger-light');
                    $('#file-size-info .info-box-icon').removeClass('bg-info bg-success').addClass('bg-danger').html('<i class="fas fa-exclamation-triangle"></i>');
                    $('#file-size-info .info-box-text').html('File too large!');
                    $('#file-size-info .info-box-number').html('<strong>' + fileName + '</strong> (' + fileSizeFormatted + ')');
                    $('#file-size-info .progress-description').html('<span style="color: #721c24;">Maximum allowed: ' + maxSizeFormatted + ' (upload_max={{ $uploadMaxFilesize }}, post_max={{ $postMaxSize }})</span>');
                    $('.button-upload').prop('disabled', true);
                } else {
                    // Update info-box for valid file
                    $('#file-size-info').removeClass('bg-light bg-danger-light').addClass('bg-success');
                    $('#file-size-info .info-box-icon').removeClass('bg-info bg-danger').addClass('bg-success').html('<i class="fas fa-check-circle"></i>');
                    $('#file-size-info .info-box-text').html('File ready to upload');
                    $('#file-size-info .info-box-number').html('<strong>' + fileName + '</strong> (' + fileSizeFormatted + ')');
                    $('#file-size-info .progress-description').html('<span style="color: #155724;">File size OK. Maximum allowed: ' + maxSizeFormatted + '</span>');
                    $('.button-upload').prop('disabled', false);
                }
            }
        });
        
        // Handle upload button click
        $('.button-upload').click(function(e){
            e.preventDefault();
            
            const fileInput = document.getElementById('input-file');
            if (!fileInput.files || !fileInput.files[0]) {
                alert('Please select a file to upload!');
                return false;
            }
            
            const fileSize = fileInput.files[0].size;
            const fileName = fileInput.files[0].name;
            
            // Final check before submit
            if (fileSize > maxAllowedSize) {
                alert('File "' + fileName + '" (' + formatBytes(fileSize) + ') exceeds the maximum allowed size (' + formatBytes(maxAllowedSize) + ').\n\n' +
                      'Server limits:\n' +
                      '- upload_max_filesize: {{ $uploadMaxFilesize }}\n' +
                      '- post_max_size: {{ $postMaxSize }}\n\n' +
                      'Please choose a smaller file or contact your administrator.');
                return false;
            }
            
            // Show loading and submit
            $('#loading').show();
            $('#import-product').submit();
        });
        
        $('.button-upload-des').click(function(){
            $('#loading').show();
            $('#import-product-des').submit();
        });
    </script>
@endpush
@extends('gp247-core::layout')

@section('main')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title text-primary"><i class="fas fa-exclamation-triangle"></i> {!! gp247_language_render('admin.extension.import_warning') !!}</h3>
            </div>

            <form action="{{ $urlAction }}" method="post" accept-charset="UTF-8" class="form-horizontal" id="import-product" enctype="multipart/form-data">
                @csrf
                <div class="box-body">
                    <div class="fields-group">
                        <div class="form-group{{ $errors->has('file') ? ' text-red' : '' }}">
                            <label for="image" class="col-sm-2 col-form-label">
                            </label>
                            <div class="col-sm-6">
                                <div class="input-group input-group-sm">
                                    <div class="custom-file">
                                      <input type="file" id="input-file" class="custom-file-input" accept=".zip,application/zip,application/x-zip-compressed"  required="required" name="file">
                                      <label class="custom-file-label" for="input-file">{{ gp247_language_render('action.choose_file') }}</label>
                                    </div>
                                    <div class="input-group-append">
                                      <button type="button" class="btn button-upload">{{ gp247_language_render('admin.extension.import_submit') }}</button>
                                    </div>
                                </div>
                                <div>
                                    @if ($errors->has('file'))
                                    <span class="form-text text-red">
                                        <i class="fa fa-info-circle"></i> {{ $errors->first('file') }}
                                    </span>
                                    @elseif(session('error'))
                                    <span class="form-text text-red">
                                        <i class="fa fa-exclamation-circle"></i> {{ session('error') }}
                                    </span>
                                    @else
                                    <span class="form-text" id="file-size-info">
                                        <i class="fa fa-info-circle"></i> {!! gp247_language_render('admin.extension.import_note') !!}
                                        <br><strong>Maximum file size:</strong> {{ $maxSizeInMB }} MB
                                        (Server limits: upload_max={{ $uploadMaxFilesize }}, post_max={{ $postMaxSize }})
                                    </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        
                    </div>
                </div>

                <!-- /.box-footer -->
            </form>  
        </div>
    </div>
</div>


@endsection

@push('styles')
<style>
    .button-upload, .button-upload:hover,
    .button-upload-des, .button-upload-des:hover{
        background: #3c8dbc !important;
        color: #fff;
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
                
                let message = '<i class="fa fa-file-archive"></i> <strong>' + fileName + '</strong> (' + fileSizeFormatted + ')';
                
                // Check file type
                const validTypes = ['application/zip', 'application/x-zip-compressed', 'application/x-zip', 'application/octet-stream'];
                const isValidType = validTypes.includes(fileType) || fileName.toLowerCase().endsWith('.zip');
                
                if (!isValidType) {
                    message += '<br><span style="color: #dc3545;"><i class="fa fa-exclamation-triangle"></i> File must be a ZIP archive!</span>';
                    $('#file-size-info').html(message).removeClass('text-muted').addClass('text-danger');
                    $('.button-upload').prop('disabled', true);
                    return;
                }
                
                // Check file size BEFORE upload
                if (fileSize > maxAllowedSize) {
                    message += '<br><span style="color: #dc3545;"><i class="fa fa-exclamation-triangle"></i> File too large! Maximum allowed: ' + maxSizeFormatted + '</span>';
                    message += '<br><small>Server limits: upload_max={{ $uploadMaxFilesize }}, post_max={{ $postMaxSize }}</small>';
                    message += '<br><strong style="color: #dc3545;">Please choose a smaller file or contact administrator to increase server limits.</strong>';
                    $('#file-size-info').html(message).removeClass('text-muted').addClass('text-danger');
                    $('.button-upload').prop('disabled', true);
                } else {
                    message += '<br><span style="color: #28a745;"><i class="fa fa-check-circle"></i> File size OK. Ready to upload.</span>';
                    $('#file-size-info').html(message).removeClass('text-danger').addClass('text-success');
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
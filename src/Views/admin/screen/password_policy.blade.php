@extends('gp247-core::layout')
@section('main')
      <div class="card card-primary card-outline card-outline-tabs">
        <div class="card-header p-0 border-bottom-0">
          <ul class="nav nav-tabs" id="custom-tabs-four-tab" role="tablist">
            @if ((admin()->user()->isAdministrator() ||  admin()->user()->isViewAll()))
            <li class="nav-item">
              <a class="nav-link active" id="tab-password_policy_admin-tab" data-toggle="pill" href="#tab-password_policy_admin" role="tab" aria-controls="tab-password_policy_admin" aria-selected="false">{{ gp247_language_render('password_policy.admin.title') }}</a>
            </li>
            @endif
          </ul>
        </div>
        
        <div class="card-body">
          <div class="tab-content" id="custom-tabs-four-tabContent">
            {{-- Tab policy --}}
            @if ((admin()->user()->isAdministrator() ||  admin()->user()->isViewAll()))
            <div class="tab-pane fade  fade active show" id="tab-password_policy_admin" role="tabpanel" aria-labelledby="password_policy_admin">
              @include('gp247-core::screen.password_policy_admin')
            </div>
            @endif
            {{-- //End tab policy --}}
          </div>
        </div>
        <!-- /.card -->
</div>

@endsection

@push('styles')
<!-- Ediable -->
<link rel="stylesheet" href="{{ gp247_file('GP247/Core/plugin/bootstrap-editable.css')}}">
<style type="text/css">
  #maintain_content img{
    max-width: 100%;
  }
</style>
@endpush

@if (empty($dataNotFound))
@push('scripts')
<!-- Ediable -->
<script src="{{ gp247_file('GP247/Core/plugin/bootstrap-editable.min.js')}}"></script>

<script type="text/javascript">

  // Editable
$(document).ready(function() {

      //  $.fn.editable.defaults.mode = 'inline';
      $.fn.editable.defaults.params = function (params) {
        params._token = "{{ csrf_token() }}";
        params.storeId = "{{ $storeId }}";
        return params;
      };

      $('.editable-required').editable({
        validate: function(value) {
            if (value == '') {
                return '{{  gp247_language_render('admin.not_empty') }}';
            }
        },
        success: function(data) {
          if(data.error == 0){
            alertJs('success', '{{ gp247_language_render('admin.msg_change_success') }}');
          } else {
            alertJs('error', data.msg);
          }
      }
    });

    $('.editable').editable({
        validate: function(value) {
        },
        success: function(data) {
          console.log(data);
          if(data.error == 0){
            alertJs('success', '{{ gp247_language_render('admin.msg_change_success') }}');
          } else {
            alertMsg('error', data.msg);
          }
      }
    });

});

  $('input.check-data-config-global').iCheck({
    checkboxClass: 'icheckbox_square-blue',
    radioClass: 'iradio_square-blue',
    increaseArea: '20%' /* optional */
  }).on('ifChanged', function(e) {
  var isChecked = e.currentTarget.checked;
  isChecked = (isChecked == false)?0:1;
  var name = $(this).attr('name');
    $.ajax({
      url: '{{ $urlUpdateConfigGlobal }}',
      type: 'POST',
      dataType: 'JSON',
      data: {
          "_token": "{{ csrf_token() }}",
          "name": $(this).attr('name'),
          "value": isChecked
        },
    })
    .done(function(data) {
      if(data.error == 0){
        if (isChecked == 0) {
          $('#smtp-config').hide();
        } else {
          $('#smtp-config').show();
        }
        alertJs('success', '{{ gp247_language_render('admin.msg_change_success') }}');
      } else {
        alertJs('error', data.msg);
      }
    });

    });


</script>




<script>
  // Update store_info

//End update store_info
</script>

@endpush
@endif
@extends('gp247-core::layout')

@section('main')
@php
    $id = empty($id) ? 0 : $id;
@endphp
<div class="row">

    <div class="col-md-5">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">{!! $title_action !!}</h3>
            @if ($layout == 'edit')
            <div class="btn-group float-right" style="margin-right: 5px">
                <a href="{{ gp247_route_admin('admin_api_connection.index') }}" class="btn btn-sm btn-flat btn-default" title="List"><i class="fa fa-list"></i>
                  <span class="hidden-xs"> {{ gp247_language_render('admin.back_list') }}</span>
                </a>
            </div>
          @endif
          </div>
          <!-- /.card-header -->
          <!-- form start -->
          <form action="{{ $url_action }}" method="post" accept-charset="UTF-8" class="form-horizontal" id="form-main">
            <div class="card-body">
    
              <div class="form-group row {{ $errors->has('description') ? ' text-red' : '' }}">
                <label for="description" class="col-sm-12 col-form-label">{{ gp247_language_render('admin.api_connection.description') }}</label>
                <div class="col-sm-12 ">
                  <div class="input-group">
                    <div class="input-group-prepend">
                      <span class="input-group-text"><i class="fas fa-pencil-alt"></i></span>
                    </div>
                    <input type="text" id="description" name="description" value="{{ old()?old('description'):$api_connection['description']??'' }}" class="form-control form-control-sm description {{ $errors->has('description') ? ' is-invalid' : '' }}">
                  </div>
    
                  @if ($errors->has('description'))
                  <span class="text-sm">
                    <i class="fa fa-info-circle"></i> {{ $errors->first('description') }}
                  </span>
                  @endif
                </div>
              </div>
        
              <div class="form-group row {{ $errors->has('apiconnection') ? ' text-red' : '' }}">
                <label for="apiconnection" class="col-sm-12 col-form-label">{{ gp247_language_render('admin.api_connection.connection') }}</label>
                <div class="col-sm-12">
                  <div class="input-group">
                    <div class="input-group-prepend">
                      <span class="input-group-text"><i class="fas fa-pencil-alt"></i></span>
                    </div>
                    <input type="text" id="apiconnection" name="apiconnection" value="{{ old()?old('apiconnection'):$api_connection['apiconnection']??'' }}" class="form-control form-control-sm apiconnection {{ $errors->has('apiconnection') ? ' is-invalid' : '' }}">
                  </div>
    
                  @if ($errors->has('apiconnection'))
                  <span class="text-sm">
                    <i class="fa fa-info-circle"></i> {{ $errors->first('apiconnection') }}
                  </span>
                  @endif
    
                </div>
              </div>

              <div class="form-group row {{ $errors->has('apikey') ? ' text-red' : '' }}">
                <label for="apikey" class="col-sm-12 col-form-label">{{ gp247_language_render('admin.api_connection.apikey') }}</label>
                <div class="col-sm-12">
                  <div class="input-group">
                    <div class="input-group-prepend">
                      <span class="input-group-text"><i class="fas fa-pencil-alt"></i></span>
                    </div>
                    <input type="text" id="apikey" name="apikey" value="{{ old()?old('apikey'):$api_connection['apikey']??'' }}" class="form-control form-control-sm apikey {{ $errors->has('apikey') ? ' is-invalid' : '' }}">
                    <div class="input-group-append">
                        <button class="btn btn-sm btn-default" id="refreshkey" type="button">
                            <i class="fas fa-sync-alt fa-fw"></i>
                        </button>
                      </div>
                  </div>
    
                  @if ($errors->has('apikey'))
                  <span class="text-sm">
                    <i class="fa fa-info-circle"></i> {{ $errors->first('apikey') }}
                  </span>
                  @endif
    
                </div>
              </div>

              <div class="form-group row {{ $errors->has('expire') ? ' text-red' : '' }}">
                <label for="expire" class="col-sm-12 col-form-label">{{ gp247_language_render('admin.api_connection.expire') }}</label>
                <div class="col-sm-12">
                  <div class="input-group">
                    <div class="input-group-prepend">
                      <span class="input-group-text"><i class="fa fa-calendar fa-fw"></i></span>
                    </div>
                    <input type="text" id="expire" name="expire" value="{{ old()?old('expire'):$api_connection['expire']??'' }}" data-date-format="yyyy-mm-dd" class="form-control form-control-sm expire date_time {{ $errors->has('expire') ? ' is-invalid' : '' }}">
                  </div>
    
                  @if ($errors->has('expire'))
                  <span class="text-sm">
                    <i class="fa fa-info-circle"></i> {{ $errors->first('expire') }}
                  </span>
                  @endif
    
                </div>
              </div>

              <div class="form-group row {{ $errors->has('status') ? ' text-red' : '' }}">
                <label for="status" class="col-sm-12 col-form-label">{{ gp247_language_render('admin.api_connection.status') }}</label>
                <div class="col-sm-12">
                  <div class="input-group">
                    <input class="checkbox" type="checkbox" name="status"  {{ old('status',(empty($api_connection['status'])?0:1))?'checked':''}}>
                </div>
                  @if ($errors->has('status'))
                  <span class="text-sm">
                    <i class="fa fa-info-circle"></i> {{ $errors->first('status') }}
                  </span>
                  @endif

                </div>
              </div>

            </div>
            <!-- /.card-body -->
            @csrf
            <div class="card-footer row">
              <div class="col-md-12">
              <div class=" float-left">
              <button type="reset" class="btn btn-sm btn-warning">{{ gp247_language_render('action.reset') }}</button>
              </div>
              <div class=" float-right">
              <button type="submit" class="btn btn-sm btn-primary">{{ gp247_language_render('action.submit') }}</button>
              </div>
              </div>
            </div>
            <!-- /.card-footer -->
          </form>
        </div>
      </div>

      <div class="col-md-7">
        <div class="card">
          <div class="card-header with-border">
            <div class="api-switch-row"><span class="api-switch-label">{!! gp247_language_render('admin.api_connection.service') !!}</span><input class="switch-data-config" data-on-text="ON"  data-off-text="OFF" name="api_connection_required" type="checkbox"  {{ (gp247_config_global('api_connection_required')?'checked':'') }}></div>
              <div class="api-list">
                <b>List API core:</b><br>
                @if (count($listCore) > 0)
                    @foreach ($listCore as $item)
                        <span class="api-list-item">{{ $item }}</span>
                    @endforeach
                @else
                    <span class="api-list-item">{{ gp247_language_render('') }}</span>
                @endif
              </div>
              @if (count($listFront) > 0)
              <div class="api-list">
                <b>List API front:</b><br>
                    @foreach ($listFront as $item)
                        <span class="api-list-item">{{ $item }}</span>
                    @endforeach
              </div>
             @endif
          </div>
          
          <div class="api-help">{!! gp247_language_render('admin.api_connection.api_connection_required_help') !!}
          </div>

          <div class="api-usage">
            <span>curl -X GET "https://your-domain.local/api/your_resource" \<br>
            -H "Content-Type: application/json" \<br>
            -H "Authorization: Bearer your-bearer-token"<br>
            </span>
            <span class="api-use-connection {{ (gp247_config_global('api_connection_required') ? '' : 'd-none') }}">
            -H "<b>apiconnection</b>: your-connection" \<br>
            -H "<b>apikey</b>: your-api-key" \<br> </span>
          </div>
    
          <div class="box-body table-responsive">
            <section class="table-list">
                <div class="card-body table-responsivep-0" >
                  <table class="table table-hover box-body text-wrap table-bordered">
                      <thead class="thead-light text-nowrap">
                         <tr>
                          @if (!empty($removeList))
                          <th></th>
                          @endif
                          @foreach ($listTh as $key => $th)
                              <th class="th-{{ $key }}">{!! $th !!}</th>
                          @endforeach
                         </tr>
                      </thead>
                      <tbody>
                          @foreach ($dataTr as $keyRow => $tr)
                              <tr  class="{{ ($keyRow == $id)? 'active':$id }}">
                                  @if (!empty($removeList))
                                  <td>
                                    <input class="checkbox" type="checkbox" class="grid-row-checkbox" data-id="{{ $keyRow }}">
                                  </td>
                                  @endif
                                  @foreach ($tr as $key => $trtd)
                                      <td>{!! $trtd !!}</td>
                                  @endforeach
                              </tr>
                          @endforeach
                      </tbody>
                   </table>
                </div>
                <div class="block-pagination clearfix m-10">
                    <div class="ml-3 float-left">
                      {!! $resultItems??'' !!}
                    </div>
                    <div class="pagination pagination-sm mr-3 float-right">
                      {!! $pagination??'' !!}
                    </div>
                  </div>
               </section>
        </div>
        </div>
      </div>
</div>

@endsection

@push('styles')
<style>
  /* Style API usage block */
  .api-usage {
    margin: 8px 15px 15px 15px;
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 4px;
    padding: 12px 14px;
  }
  /* Help text and API list */
  .api-help {
    margin: 8px 15px 8px 15px;
    padding: 8px 12px;
    background: #f6f9ff;
    border: 1px solid #e6edff;
    border-left: 3px solid #0d6efd; /* primary accent */
    border-radius: 4px;
    color: #495057;
  }
  .api-help a { color: #0d6efd; text-decoration: underline; font-weight: 600; }
  .api-list {
    margin: 6px 0 10px 10px;
    padding: 8px 10px;
    background: #fdfdfd;
    border: 1px dashed #d3d7db;
    border-radius: 4px;
    color: #6b5a4b;
  }
  .api-list-item {
    display: block;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 12px;
    margin: 2px 0;
    color: #7a7a7a;
  }
  /* Header row for API switch */
  .api-switch-row {
    display: flex;
    align-items: center; /* center vertically */
    gap: 10px;
  }
  .api-switch-label {
    margin: 0;
    line-height: 1; /* avoid extra vertical offset */
  }
  /* Monospace for command lines */
  .api-usage span {
    display: block;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 12px;
    line-height: 1.55;
    color: #212529;
    white-space: normal;
  }
  /* Highlight required headers when connection is required */
  .api-use-connection {
    margin-top: 6px;
    background: #fff5f5;
    border-left: 3px solid #dc3545;
    padding-left: 8px;
  }
  .api-use-connection b { color: #c82333; }
  /* Align label and switch nicely */
  .card-header.with-border .float-left { margin-right: 10px; line-height: 24px; }
  .card-header.with-border .switch-data-config { margin-left: 8px; }
</style>
@endpush

@push('scripts')
<script type="text/javascript">

$(document).ready(function() {
    $('#refreshkey').click(function(){
        $('#loading').show();
        $.ajax({
            method: 'get',
            url: '{{ gp247_route_admin('admin_api_connection.generate_key') }}',
            success: function (data) {
                $('#apikey').val(data.data);
                $('#loading').hide();
            }
        });
    });
});


$("input.switch-data-config").bootstrapSwitch();
  $('input.switch-data-config').on('switchChange.bootstrapSwitch', function (event, state) {
      var valueSet;
      if (state == true) {
          valueSet =  '1';
      } else {
          valueSet = '0';
      }
      $('#loading').show();
      $.ajax({
        type: 'POST',
        dataType:'json',
        url: "{{ gp247_route_admin('admin_config_global.update') }}",
        data: {
          "_token": "{{ csrf_token() }}",
          "name": $(this).attr('name'),
          "value": valueSet
        },
        success: function (response) {
          if(parseInt(response.error) ==0){
            alertMsg('success', '{{ gp247_language_render('admin.msg_change_success') }}');
            // Update visibility of API usage headers when server confirms the change
            if (state === true) {
              $('.api-use-connection').removeClass('d-none');
            } else {
              $('.api-use-connection').addClass('d-none');
            }
          }else{
            alertMsg('error', response.msg);
          }
          $('#loading').hide();
        }
      });
  }); 


  function deleteItem(ids){
  Swal.mixin({
    customClass: {
      confirmButton: 'btn btn-sm btn-success',
      cancelButton: 'btn btn-sm btn-danger'
    },
    buttonsStyling: true,
  }).fire({
    title: '{{ gp247_language_render('action.delete_confirm') }}',
    text: "",
    type: 'warning',
    showCancelButton: true,
    confirmButtonText: '{{ gp247_language_render('action.confirm_yes') }}',
    confirmButtonColor: "#DD6B55",
    cancelButtonText: '{{ gp247_language_render('action.confirm_no') }}',
    reverseButtons: true,

    preConfirm: function() {
        return new Promise(function(resolve) {
            $.ajax({
                method: 'post',
                url: '{{ $urlDeleteItem ?? '' }}',
                data: {
                  ids:ids,
                    _token: '{{ csrf_token() }}',
                },
                success: function (data) {
                    if(data.error == 1){
                      alertMsg('error', data.msg, '{{ gp247_language_render('action.warning') }}');
                      $.pjax.reload('#pjax-container');
                      return;
                    }else{
                      alertMsg('success', data.msg);
                      window.location.replace('{{ gp247_route_admin('admin_api_connection.index') }}');
                    }

                }
            });
        });
    }

  }).then((result) => {
    if (result.value) {
      alertMsg('success', '{{ gp247_language_render('action.delete_confirm_deleted_msg') }}', '{{ gp247_language_render('action.delete_confirm_deleted') }}');
    } else if (
      // Read more about handling dismissals
      result.dismiss === Swal.DismissReason.cancel
    ) {
      // swalWithBootstrapButtons.fire(
      //   'Cancelled',
      //   'Your imaginary file is safe :)',
      //   'error'
      // )
    }
  })
}
</script>
@endpush

@extends('gp247-core::layout')

@section('main')
   <div class="row">
    <div class="col-md-12">
      <div class="card card-primary card-outline card-outline-tabs">
        <div class="card-header p-0 border-bottom-0">
          <ul class="nav nav-tabs" id="custom-tabs-four-tab" role="tablist">
            <li class="nav-item">
              <a class="nav-link active" href="#"  aria-controls="custom-tabs-four-home" aria-selected="true">{{ gp247_language_render('admin.extension.local') }}</a>
            </li>
            @if ($configExtension)
            <li class="nav-item">
              <a class="nav-link" href="{{ $listUrlAction['urlOnline'] }}" >{{ gp247_language_render('admin.extension.online') }}</a>
            </li>
            @endif
            <li class="nav-item">
              <a class="nav-link"  href="{{ $listUrlAction['urlImport'] }}" ><span><i class="fas fa-save"></i> {{ gp247_language_render('admin.extension.import') }}</span></a>
            </li>
          </ul>
        </div>

        <div class="card-body" id="pjax-container">
          <div class="tab-content" id="custom-tabs-four-tabContent">
            <div class="table-responsive">
            <table class="table table-hover text-nowrap table-bordered">
              <thead class="thead-light text-nowrap">
                <tr>
                  <th>{{ gp247_language_render('admin.extension.image') }}</th>
                  <th>{{ gp247_language_render('admin.extension.name') }}</th>
                  <th>{{ gp247_language_render('admin.extension.key') }}</th>
                  <th>{{ gp247_language_render('admin.extension.version') }}</th>
                  <th>{{ gp247_language_render('admin.extension.auth') }}</th>
                  <th>{{ gp247_language_render('admin.extension.link') }}</th>
                  <th>{{ gp247_language_render('admin.extension.sort') }}</th>
                  <th>{{ gp247_language_render('admin.extension.action') }}</th>
                </tr>
              </thead>
              <tbody>
                @if (!$extensions)
                  <tr>
                    <td colspan="8" style="text-align: center;color: red;">
                      {{ gp247_language_render('admin.extension.empty') }}
                    </td>
                  </tr>
                @else

                  @foreach ($extensions as $keyExtension => $extensionClassName)
                  @php
                  //Begin try catch error
                  try {
                    $classConfig = $extensionClassName.'\\AppConfig';
                    $pluginClass = new $classConfig;
                    //Check Plugin installed
                    if (!array_key_exists($keyExtension, $extensionsInstalled->toArray())) {
                      $pluginStatusTitle = gp247_language_render('admin.extension.not_install');
                      $pluginAction = '<span onClick="installExtension($(this),\''.$keyExtension.'\');" title="'.gp247_language_render('admin.extension.install').'" type="button" class="btn btn-sm btn-flat btn-success"><i class="fa fa-plus-circle"></i></span>';
                      //Delete all (include data and source code)
                      $pluginAction .=' <span onClick="removeExtension($(this),\''.$keyExtension.'\');" title="'.gp247_language_render('admin.extension.remove').'" class="btn btn-sm btn-flat btn-danger"><i class="fa fa-trash"></i></span>';

                    } else {
                      //Check plugin enable
                      if($extensionsInstalled[$keyExtension]['value']){
                        $pluginStatusTitle = gp247_language_render('admin.extension.actived');
                        $pluginAction ='<span onClick="disableExtension($(this),\''.$keyExtension.'\');" title="'.gp247_language_render('admin.extension.disable').'" type="button" class="btn btn-sm btn-flat btn-warning btn-flat"><i class="fa fa-power-off"></i></span>&nbsp;';

                          if($pluginClass->clickApp()){
                            $pluginAction .='<a href="'.url()->current().'?action=config&key='.$keyExtension.'"><span title="'.gp247_language_render('admin.extension.config').'" class="btn btn-sm btn-flat btn-primary"><i class="fas fa-cog"></i></span>&nbsp;</a>';
                          }
                          if(!in_array($keyExtension, $extensionProtected)) {
                          //Delete data
                            $pluginAction .='<span onClick="deleteExtension($(this),\''.$keyExtension.'\');" title="'.gp247_language_render('admin.extension.only_delete_data').'" class="btn btn-sm btn-flat btn-danger"><i class="fas fa-times"></i></span>';
                          //Delete all (include data and source code)
                            $pluginAction .=' <span onClick="removeExtension($(this),\''.$keyExtension.'\');" title="'.gp247_language_render('admin.extension.remove').'" class="btn btn-sm btn-flat btn-danger"><i class="fa fa-trash"></i></span>';
                          }
                      }else{
                        $pluginStatusTitle = gp247_language_render('admin.extension.disabled');
                        $pluginAction = '<span onClick="enableExtension($(this),\''.$keyExtension.'\');" title="'.gp247_language_render('admin.extension.enable').'" type="button" class="btn btn-sm btn-flat btn-primary"><i class="fa fa-paper-plane"></i></span>&nbsp;';
                          if($pluginClass->clickApp()){
                            $pluginAction .='<a href="'.url()->current().'?action=config&key='.$keyExtension.'"><span title="'.gp247_language_render('admin.extension.config').'" class="btn btn-sm btn-flat btn-primary"><i class="fas fa-cog"></i></span>&nbsp;</a>';
                          }
                          //You can not remove if plugin is default
                          if(!in_array($keyExtension, $extensionProtected)) {
                            //Delete data
                            $pluginAction .='<span onClick="deleteExtension($(this),\''.$keyExtension.'\');" title="'.gp247_language_render('admin.extension.only_delete_data').'" class="btn btn-sm btn-flat btn-danger"><i class="fas fa-times"></i></span>';
                            //Delete all (include data and source code)
                            $pluginAction .=' <span onClick="removeExtension($(this),\''.$keyExtension.'\');" title="'.gp247_language_render('admin.extension.remove').'" class="btn btn-sm btn-flat btn-danger"><i class="fa fa-trash"></i></span>';
                          }
                      }
                    }
                    @endphp
                    
                    <tr>
                      <td>{!! gp247_image_render($pluginClass->image,'50px', '', $pluginClass->title) !!}</td>
                      <td>{{ $pluginClass->title }}</td>
                      <td>{{ $keyExtension }}</td>
                      <td>{{ $pluginClass->version??'' }}</td>
                      <td>{{ $pluginClass->auth??'' }}</td>
                      <td><a href="{{ $pluginClass->link??'' }}" target=_new><i class="fa fa-link" aria-hidden="true"></i>Link</a></td>
                      <td>{{ $extensionsInstalled[$keyExtension]['sort']??'' }}</td>
                      <td>
                        @if ($keyExtension == GP247_TEMPLATE_FRONT_DEFAULT)
                          
                        @else
                          {!! $pluginAction !!}
                        @endif
                      </td>
                    </tr>

                    @php
                    //End try cacth
                    } catch(\Throwable $e) {
                      $msg = json_encode($extensionClassName)." : ".$e->getMessage();
                      $msg .= "\n*File* `".$e->getFile()."`, *Line:* ".$e->getLine().", *Code:* ".$e->getCode().PHP_EOL.'URL= '.url()->current();
                      gp247_report($msg);
                      echo $msg;
                    }
                    @endphp
                    
                  @endforeach
                @endif
              </tbody>
            </table>
            </div>

          </div>
        </div>
        <!-- /.card -->
      </div>
      </div>
      </div>
@endsection

@push('styles')

@endpush

@push('scripts')



<script type="text/javascript">
  function enableExtension(obj,key) {
      $('#loading').show()
      obj.button('loading');
      $.ajax({
        type: 'POST',
        dataType:'json',
        url: '{{ $listUrlAction['enable'] }}',
        data: {
          "_token": "{{ csrf_token() }}",
          "key":key
        },
        success: function (response) {
          console.log(response);
              if(parseInt(response.error) ==0){
                  $.pjax.reload({container:'#pjax-container'});
                  alertMsg('success', '{{ gp247_language_render('admin.msg_change_success') }}');
              }else{
                alertMsg('error', response.msg);
              }
              $('#loading').hide();
              obj.button('reset');
        }
      });

  }
  function disableExtension(obj,key) {
      $('#loading').show()
      obj.button('loading');
      $.ajax({
        type: 'POST',
        dataType:'json',
        url: '{{ $listUrlAction['disable'] }}',
        data: {
          "_token": "{{ csrf_token() }}",
          "key":key
        },
        success: function (response) {
          console.log(response);
              if(parseInt(response.error) ==0){
                  $.pjax.reload({container:'#pjax-container'});
                  alertMsg('success', '{{ gp247_language_render('admin.msg_change_success') }}');
              }else{
                alertMsg('error', response.msg);
              }
              $('#loading').hide();
              obj.button('reset');
        }
      });
  }
  function installExtension(obj,key) {
      $('#loading').show()
      obj.button('loading');
      $.ajax({
        type: 'POST',
        dataType:'json',
        url: '{{ $listUrlAction['install'] }}',
        data: {
          "_token": "{{ csrf_token() }}",
          "key":key
        },
        success: function (response) {
          console.log(response);
              if(parseInt(response.error) ==0){
              location.reload();
              }else{
                alertMsg('error', response.msg);
              }
              $('#loading').hide();
              obj.button('reset');
        }
      });
  }

  function deleteExtension(obj,key) {
    return uninstallExtension(obj,key, onlyRemoveData = 1);
  }

  function removeExtension(obj,key) {
    return uninstallExtension(obj,key, onlyRemoveData = null);
  }

  function uninstallExtension(obj,key, onlyRemoveData = null) {

      Swal.fire({
        title: '{{ gp247_language_render('action.action_confirm') }}',
        text: '{{ gp247_language_render('action.action_confirm_warning') }}',
        type: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: '{{ gp247_language_render('action.confirm_yes') }}',
      }).then((result) => {
        if (result.value) {
            $('#loading').show()
            obj.button('loading');
            $.ajax({
              type: 'POST',
              dataType:'json',
              url: '{{ $listUrlAction['uninstall'] }}',
              data: {
                "_token": "{{ csrf_token() }}",
                "key":key,
                "onlyRemoveData": onlyRemoveData,
              },
              success: function (response) {
                console.log(response);
              if(parseInt(response.error) ==0){
              location.reload();
              }else{
                alertMsg('error', response.msg);
              }
              $('#loading').hide();
              obj.button('reset');
              }
            });
        }
      })
  }

    $(document).ready(function(){
    // does current browser support PJAX
      if ($.support.pjax) {
        $.pjax.defaults.timeout = 2000; // time in milliseconds
      }
    });

</script>

@endpush

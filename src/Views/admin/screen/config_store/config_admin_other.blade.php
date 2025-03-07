{{-- Use gp247_config with storeId, dont use gp247_config_admin because will switch the store to the specified store Id
--}}
<div class="card">
  <div class="card-body table-responsive">
   <table class="table table-hover box-body text-wrap table-bordered">
     <tbody>
      <tr>
        <th>{{ gp247_language_quickly('admin.admin_custom_config.add_new_detail', 'Key detail') }}</th>
        <th></th>
      </tr>
      <tr>
        <td>{{ gp247_language_render('admin.env.ADMIN_NAME') }}</td>
        <td><a href="#" class="editable-required editable editable-click" data-name="ADMIN_NAME" data-type="text" data-pk="" data-source="" data-url="{{ $urlUpdateConfig }}" data-title="{{ gp247_language_render('admin.env.ADMIN_NAME') }}" data-value="{{ gp247_config('ADMIN_NAME', $storeId) }}" data-original-title="" title=""></a></td>
      </tr>

      <tr>
        <td>{{ gp247_language_render('admin.env.ADMIN_TITLE') }}</td>
        <td><a href="#" class="editable-required editable editable-click" data-name="ADMIN_TITLE" data-type="text" data-pk="" data-source="" data-url="{{ $urlUpdateConfig }}" data-title="{{ gp247_language_render('admin.env.ADMIN_TITLE') }}" data-value="{{ gp247_config('ADMIN_TITLE', $storeId) }}" data-original-title="" title=""></a></td>
      </tr>
      <tr>
        <td>{{ gp247_language_render('admin.env.hidden_copyright_footer_admin') }}</td>
        <td>
          <input class="check-data-config"  data-store="{{ $storeId }}" type="checkbox" name="hidden_copyright_footer_admin" {{ gp247_config('hidden_copyright_footer_admin', $storeId)?"checked":"" }}>
        </td>
      </tr>

      <tr>
        <td>{{ gp247_language_render('admin.env.hidden_copyright_footer') }}</td>
        <td>
          <input class="check-data-config"  data-store="{{ $storeId }}" type="checkbox" name="hidden_copyright_footer" {{ gp247_config('hidden_copyright_footer', $storeId)?"checked":"" }}>
        </td>
      </tr>

     </tbody>
   </table>
  </div>
</div>
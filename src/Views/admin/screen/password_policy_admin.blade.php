<div class="card">

  <div class="card-body table-responsivep-0">
   <table class="table table-hover box-body text-wrap table-bordered">
     <thead class="thead-light text-nowrap">
       <tr>
         <th>{{ gp247_language_render('password_policy.admin.rule') }}</th>
         <th>{{ gp247_language_render('password_policy.admin.rule_value') }}</th>
       </tr>
     </thead>
     <tbody>
       @foreach ($passwordPolicy as $key => $policy)
       @if (in_array($policy['key'], ['admin_password_min','admin_password_max']))
       <tr>
        <td>{{ gp247_language_render($policy['detail']) }}</td>
        <td><a href="#" class="editable-required editable editable-click" data-name="{{ $policy['key'] }}" data-type="text" data-pk="" data-source="" data-url="{{ $urlUpdateConfigGlobal }}" data-title="{{ gp247_language_render($policy['detail']) }}" data-value="{{ $policy['value'] }}" data-original-title="" title=""></a></td>
      </tr>
       @else
       <tr>
        <td>{{ gp247_language_render($policy['detail']) }}</td>
        <td><input class="check-data-config-global" data-store="{{ $storeId }}" type="checkbox" name="{{ $policy['key'] }}"  {{ $policy['value']?"checked":"" }}></td>
      </tr>
       @endif

       @endforeach
     </tbody>
   </table>
  </div>
</div>
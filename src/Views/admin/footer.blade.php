<footer class="main-footer">
  @if (!gp247_config('hidden_copyright_footer_admin'))
    <div class="float-right d-none d-sm-inline-block">
      <strong>Env</strong>
      {{ config('app.env') }}
      &nbsp;&nbsp;
      <strong>Version</strong> 
      {{ config('gp247.sub-version') }} ({{ (gp247_composer_get_package_installed()[0]['versions']['gp247/core']['pretty_version'] ?? '') }})
    </div>
    <strong>
      Copyright &copy; {{ date('Y') }} 
      <a href="{{ config('gp247.github') }}">GP247: {{ config('gp247.name') }}</a>.
    </strong> 
  @endif
</footer>

<div class="card">
    <div class="card-body text-center">
      <h6>{!! gp247_language_render('admin.home_welcome_version', ['version' => (gp247_composer_get_package_installed()[0]['versions']['gp247/core']['pretty_version'] ?? '')]) !!}</h6>
    </div>
  </div>
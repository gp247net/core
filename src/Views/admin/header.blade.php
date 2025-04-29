  <!-- Navbar -->
  <nav class="main-header navbar navbar-expand navbar-dark navbar-lightblue">
    <!-- Left navbar links -->
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
      </li>
      
      @if (is_array(config('gp247-module.module_header_left')))
        @foreach (config('gp247-module.module_header_left') as $module)
          @includeIf($module['view'] ?? '')
        @endforeach
      @endif

    </ul>

    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">
      @if (is_array(config('gp247-module.module_header_right')))
        @foreach (config('gp247-module.module_header_right') as $module)
        @includeIf($module['view'] ?? '')
        @endforeach
      @endif

    </ul>
  </nav>
  <!-- /.navbar -->

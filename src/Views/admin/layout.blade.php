<!DOCTYPE html>
<html lang="{{ config('app.locale') }}">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <link rel="icon" href="{{ gp247_file(gp247_store_info('icon')) }}" type="image/png" sizes="16x16">
  <title>{{gp247_config_admin('ADMIN_TITLE')}} | {{ $title??'' }}</title>
  <!-- Tell the browser to be responsive to screen width -->
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="{{ gp247_file('GP247/Core/LTE/plugins/fontawesome-free/css/all.min.css')}}">
  <!-- Ionicons -->
  <link rel="stylesheet" href="{{ gp247_file('GP247/Core/plugin/ionicons.min.css')}}">
  <!-- JQVMap -->
  <link rel="stylesheet" href="{{ gp247_file('GP247/Core/LTE/plugins/jqvmap/jqvmap.min.css')}}">
  <!-- overlayScrollbars -->
  <link rel="stylesheet" href="{{ gp247_file('GP247/Core/LTE/plugins/overlayScrollbars/css/OverlayScrollbars.min.css')}}">
  <!-- summernote -->
  <link rel="stylesheet" href="{{ gp247_file('GP247/Core/LTE/plugins/summernote/summernote-bs4.css')}}">
  <!-- Google Font: Source Sans Pro -->
  <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">

  <!-- iCheck -->
  <link rel="stylesheet" href="{{ gp247_file('GP247/Core/LTE/plugins/iCheck/square/blue.css')}}">
  <link rel="stylesheet" href="{{ gp247_file('GP247/Core/LTE/plugins/icheck-bootstrap/icheck-bootstrap.min.css')}}">
  <!-- Select2 -->
  <link rel="stylesheet" href="{{ gp247_file('GP247/Core/LTE/plugins/select2/css/select2.min.css')}}">
  <!-- Daterange picker -->
  {{-- <link rel="stylesheet" href="{{ gp247_file('GP247/Core/LTE/plugins/daterangepicker/daterangepicker.css')}}"> --}}
  <!-- Tempusdominus Bbootstrap 4 -->
  {{-- <link rel="stylesheet" href="{{ gp247_file('GP247/Core/LTE/plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css')}}"> --}}

  @section('block_component_css')
    @include('gp247-core::component.css')
  @show

  <link rel="stylesheet" href="{{ gp247_file('GP247/Core/LTE/plugins/jquery-ui/jquery-ui.min.css')}}">

  <link rel="stylesheet" href="{{ gp247_file('GP247/Core/LTE/dist/css/adminlte.min.css')}}">

  @stack('styles')

</head>

<body class="hold-transition sidebar-mini layout-navbar-fixed layout-fixed accent-lightblue">

<div class="wrapper">

  @section('block_header')
    @include('gp247-core::header')
  @show

  @section('block_sidebar')
    @include('gp247-core::sidebar')
  @show

  <!-- Content Wrapper. Contains page content -->
  <div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <div class="content-header">
      <div class="container-fluid">
        <div class="row mb-2">
          <div class="col-sm-6">
            <h1 class="m-0 text-dark">
              @if (!empty($titleHtml))
                  {!! $titleHtml !!}
              @else
                  {!! $title??'' !!}
              @endif
            </h1>
            <div class="more_info">{!! $more_info??'' !!}</div>
          </div><!-- /.col -->
          <div class="col-sm-6">
            @if (\Request::route()->getName() != 'admin.home')
            <ol class="breadcrumb float-sm-right">
              <li class="breadcrumb-item"><a href="{{ gp247_route_admin('admin.home') }}"><i class="fa fa-home fa-1x"></i> {{ gp247_language_render('admin.home') }}</a></li>
              @if (!empty($breadcrumb))
              <li class="breadcrumb-item"><a href="{{ $breadcrumb['url'] }}">{{ $breadcrumb['name'] }}</a></li>
              @endif
              <li class="breadcrumb-item active">{!! $title??'' !!}</li>
            </ol>
            @elseif (admin()->user()->checkUrlAllowAccess(url()->current()))
              @if (admin()->user()->checkUrlAllowAccess(gp247_route_admin('admin_home_layout.index')))
              <ol class="breadcrumb float-sm-right">
                <a href="{{ gp247_route_admin('admin_home_layout.index') }}" class="btn btn-sm  btn-warning  btn-flat" title="{{ gp247_language_render('action.edit') }}">
                  <i class="fa fa-edit"></i>
                </a>
              </ol>
              @endif
            @endif
          </div><!-- /.col -->
        </div><!-- /.row -->
      </div><!-- /.container-fluid -->
    </div>
    <!-- /.content-header -->

    <!-- Main content -->
    <section class="content">
      <div class="container-fluid">
      @yield('main')
      </div>
    </section>
    <!-- /.content -->
  </div>
  <!-- /.content-wrapper -->

  @section('block_footer')
    @include('gp247-core::footer')
  @show

  <!-- Control Sidebar -->
  <aside class="control-sidebar control-sidebar-dark">
    <!-- Control sidebar content goes here -->
  </aside>
  <!-- /.control-sidebar -->


  <div id="loading">
        <div id="overlay" class="overlay"><i class="fa fa-spinner fa-pulse fa-5x fa-fw "></i></div>
 </div>


</div>
<!-- ./wrapper -->

<!-- jQuery -->
<script src="{{ gp247_file('GP247/Core/LTE/plugins/jquery/jquery.min.js')}}"></script>
<!-- jQuery UI 1.11.4 -->
<script src="{{ gp247_file('GP247/Core/LTE/plugins/jquery-ui/jquery-ui.min.js')}}"></script>
<!-- Resolve conflict in jQuery UI tooltip with Bootstrap tooltip -->
{{-- <script>
  $.widget.bridge('uibutton', $.ui.button)
</script> --}}
<!-- Bootstrap 4 -->
<script src="{{ gp247_file('GP247/Core/LTE/plugins/bootstrap/js/bootstrap.bundle.min.js')}}"></script>
<!-- JQVMap -->
<script src="{{ gp247_file('GP247/Core/LTE/plugins/jqvmap/jquery.vmap.min.js')}}"></script>
<script src="{{ gp247_file('GP247/Core/LTE/plugins/jqvmap/maps/jquery.vmap.usa.js')}}"></script>
<!-- Summernote -->
<script src="{{ gp247_file('GP247/Core/LTE/plugins/summernote/summernote-bs4.min.js')}}"></script>
<!-- overlayScrollbars -->
<script src="{{ gp247_file('GP247/Core/LTE/plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js')}}"></script>

<!-- Sparkline -->
<script src="{{ gp247_file('GP247/Core/LTE/plugins/sparklines/sparkline.js')}}"></script>
<!-- FastClick -->
<script src="{{ gp247_file('GP247/Core/LTE/plugins/fastclick/fastclick.js')}}"></script>
<!-- AdminLTE App -->
<script src="{{ gp247_file('GP247/Core/LTE/dist/js/adminlte.js')}}"></script>
{{-- sweetalert2 --}}
<script src="{{ gp247_file('GP247/Core/plugin/sweetalert2.all.min.js')}}"></script>
<!-- Select2 -->
<script src="{{ gp247_file('GP247/Core/LTE/plugins/select2/js/select2.full.min.js')}}"></script>
{{-- switch --}}
<script src="{{ gp247_file('GP247/Core/plugin/bootstrap-switch.min.js')}}"></script>

<script src="{{ gp247_file('GP247/Core/LTE/plugins/iCheck/icheck.min.js')}}"></script>
<script src="{{ gp247_file('GP247/Core/LTE/plugins/bs-custom-file-input/bs-custom-file-input.min.js')}}"></script>

<script>
  $(function () {
    bsCustomFileInput.init();
  });
  </script>

@stack('scripts')

@section('block_component_script')
@include('gp247-core::component.script')
@show

@section('block_component_alerts')
@include('gp247-core::component.alerts')
@show

</body>
</html>
@extends('gp247-core::layout_portable')

@section('main')
@include('gp247-core::component.css_login')
<div class="container-login100">
  <div class="wrap-login100 main-login">

      <div class="card-header text-center">
        <a href="{{ gp247_route_admin('home') }}" class="h1">
          <img src="{{ gp247_file(gp247_store_info('logo')) }}" alt="logo" class="logo">
        </a>
      </div>
      <div class="login-title-des col-md-12 p-b-41">
        <a><b>{{gp247_language_render('admin.password_forgot')}}</b></a>
      </div>
      <div class="card-body">
      <form action="{{ gp247_route_admin('admin.password_request') }}" method="post">

        <input type="hidden" name="_token" value="{{ csrf_token() }}">
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="input-form {!! !$errors->has('email') ?: 'text-red' !!}">
          <div class="input-group mb-3">
            <input class="input100 form-control form-control-sm" type="email" placeholder="{{ gp247_language_render('admin.user.email') }}"
            name="email" value="{{ old('email') }}">
            <span class="focus-input100"></span>
            <span class="symbol-input100">
              <i class="fas fa-envelope"></i>
            </span>
          </div>
          @if($errors->has('email'))
          @foreach($errors->get('email') as $message)
          <i class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</i>
          @endforeach
          @endif
        </div>

        <div class="input-form {!! !$errors->has('password') ?: 'text-red' !!}">
          <div class="input-group mb-3">
            <input class="input100 form-control form-control-sm" type="password" placeholder="{{ gp247_language_render('admin.user.password') }}"
            name="password" value="{{ old('password') }}">
            <span class="focus-input100"></span>
            <span class="symbol-input100">
              <i class="fas fa-lock"></i>
            </span>
          </div>
          @if($errors->has('password'))
          @foreach($errors->get('password') as $message)
          <i class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</i>
          @endforeach
          @endif
        </div>

        <div class="input-form {!! !$errors->has('password_confirmation') ?: 'text-red' !!}">
          <div class="input-group mb-3">
            <input class="input100 form-control form-control-sm" type="password" placeholder="{{ gp247_language_render('admin.user.password_confirmation') }}"
            name="password_confirmation" value="{{ old('password_confirmation') }}">
            <span class="focus-input100"></span>
            <span class="symbol-input100">
              <i class="fas fa-lock"></i>
            </span>
          </div>
          @if($errors->has('password_confirmation'))
          @foreach($errors->get('password_confirmation') as $message)
          <i class="control-label" for="inputError"><i class="fa fa-times-circle-o"></i>{{$message}}</i>
          @endforeach
          @endif
        </div>

        <div class="input-form">
          <div class="col-12">
            <div class="container-login-btn">
              <button class="login-btn" type="submit">
                {{ gp247_language_render('action.submit') }}
              </button>
            </div>
          </div>
        </div>
      </form>
      <p class="mt-3 mb-1">
      <a href="{{ gp247_route_admin('admin.login') }}"><b>{{ gp247_language_render('admin.user.login') }}</b></a>
      </p>
      </div>
  </div>
</div>

    @endsection


    @push('styles')
    <style type="text/css">
      .container-login100 {
        background-image: url({!! gp247_file('GP247/Core/images/bg-system.jpg') !!});
      }
    </style>
    @endpush

    @push('scripts')
    @endpush
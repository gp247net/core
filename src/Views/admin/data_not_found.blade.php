@extends('gp247-core::layout')

@section('main')
  <div class="row">
    <div class="col-md-12">
       <div class="box-body">
        <div class="error-page text-center">
           
           <h4><i class="fas fa-exclamation text-red"></i> {{ gp247_language_render('display.data_not_found_msg') }}</h4>
           <br>
           <h5><span class="text-red">{{ $url }}</span></h5>
          
        </div>
      </div>
    </div>
  </div>
@endsection


@push('styles')
@endpush

@push('scripts')
@if ($url)
<script>
  window.history.pushState("", "", '{{ $url }}');
</script>
@endif
@endpush

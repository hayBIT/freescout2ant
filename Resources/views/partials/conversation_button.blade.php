@if (file_exists(storage_path('user_' . auth()->user()->id . '_ant.txt')))
    <a href="#" data-toggle="modal" data-target="#ameise-modal" title="{{ __('In Ameise archivieren') }}" aria-label="{{ __('In Ameise archivieren') }}"> <img class="ameise-logo" alt="logo"
        src="{{ Module::getPublicPath(AMEISE_MODULE) . '/images/ameise_icon_bold_green.svg' }}"></a>
@else
    @php
        session(['redirect_back' => url()->current()]);
    @endphp
    <a href="{{$url}}" title="{{ __('Mit Ameise verbinden') }}" aria-label="{{ __('Mit Ameise verbinden') }}"> <img class="ameise-logo" alt="logo"
    src="{{ Module::getPublicPath(AMEISE_MODULE) . '/images/ameise_icon_bold_red.svg' }}"></a>
@endif
<link href="{{ asset(Module::getPublicPath(AMEISE_MODULE) . '/css/style.css') }}" rel="stylesheet" type="text/css">
<input type="hidden" id="ameise_base_url" value="{{ (config('ameisemodule.ameise_mode') == 'test' ? 'https://maklerinfo.inte.dionera.dev/' : 'https://www.maklerinfo.biz/') }}">
@section('javascripts')
    @parent
    <link href="{{ asset(Module::getPublicPath(AMEISE_MODULE) . '/css/awesomplete.css') }}" rel="stylesheet"
        type="text/css">
    <script src="{{ Module::getPublicPath(AMEISE_MODULE) . '/js/awesomplete.js' }}"  {!! \Helper::cspNonceAttr() !!}></script>
    <script src="{{ Module::getPublicPath(AMEISE_MODULE) . '/js/crm.js' }}"  {!! \Helper::cspNonceAttr() !!}></script>
    <script {!! \Helper::cspNonceAttr() !!}>
        setInterval(function () {
            fetch('/ameise/refresh-token');
        }, 3300000);
    </script>
@endsection

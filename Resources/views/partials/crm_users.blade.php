<link href="{{ asset(Module::getPublicPath(AMEISE_MODULE) . '/css/style.css') }}" rel="stylesheet" type="text/css">
@section('javascripts')
    @parent
    <input type="hidden" id="ameise_base_url" value="{{ (config('ameisemodule.ameise_mode') == 'test' ? 'https://maklerinfo.inte.dionera.dev/' : 'https://maklerinfo.biz/') }}">
    <script src="{{ Module::getPublicPath(AMEISE_MODULE) . '/js/crm_users.js' }}"  {!! \Helper::cspNonceAttr() !!}></script>
    <script  {!! \Helper::cspNonceAttr() !!}>
            let translations = {
                userName: '{{ __('User Name') }}',
                email: '{{ __('Email') }}',
                address: '{{ __('Address') }}',
                phones: '{{ __('Phones') }}'
            };
    </script>
@endsection
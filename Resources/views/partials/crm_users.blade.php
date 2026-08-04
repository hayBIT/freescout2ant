@php
    // Erzwingt nach einem Modul-Update das Neuladen der Assets statt der Cache-Version.
    $ameise_asset_version = $asset_version ?? '1.0.0';
@endphp
<link href="{{ asset(Module::getPublicPath(AMEISE_MODULE) . '/css/style.css') }}?v={{ $ameise_asset_version }}" rel="stylesheet" type="text/css">
@section('javascripts')
    @parent
    <input type="hidden" id="ameise_base_url" value="{{ (config('ameisemodule.ameise_mode') == 'test' ? 'https://maklerinfo.inte.dionera.dev/' : 'https://ameise.app/') }}">
    <script src="{{ Module::getPublicPath(AMEISE_MODULE) . '/js/crm_users.js' }}?v={{ $ameise_asset_version }}"  {!! \Helper::cspNonceAttr() !!}></script>
    <script  {!! \Helper::cspNonceAttr() !!}>
            let translations = {
                userName: '{{ __('User Name') }}',
                email: '{{ __('Email') }}',
                address: '{{ __('Address') }}',
                phones: '{{ __('Phones') }}'
            };
    </script>
@endsection

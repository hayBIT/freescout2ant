<script>
    const base_url = "{{ (config('ameisemodule.ameise_mode') == 'test' ? 'https://maklerinfo.inte.dionera.dev/' : 'https://maklerinfo.biz/') }}";
</script>
@if (file_exists(storage_path('user_' . auth()->user()->id . '_ant.txt')))
    <span class="conv-add-to-ameise conv-action" data-toggle="tooltip" data-placement="bottom"
        title="{{ __('Add to Ameise') }}" aria-label="{{ __('Add to Ameise') }}" role="button">
        <img class="ameise-logo" alt="logo"
            src="{{ Module::getPublicPath(AMEISE_MODULE) . '/images/ameise_icon_bold.svg' }}">
    </span>
    <link href="{{ asset(Module::getPublicPath(AMEISE_MODULE) . '/css/style.css') }}" rel="stylesheet" type="text/css">
    @section('javascripts')
        @parent
        <link href="{{ asset(Module::getPublicPath(AMEISE_MODULE) . '/css/jquery-ui.min.css') }}" rel="stylesheet"
            type="text/css">
        <script src="{{ Module::getPublicPath(AMEISE_MODULE) . '/js/jquery-ui.min.js' }}"></script>
        <script src="{{ Module::getPublicPath(AMEISE_MODULE) . '/js/crm.js' }}"></script>
    @endsection
@endif

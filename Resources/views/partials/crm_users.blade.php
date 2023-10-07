<link href="{{ asset(Module::getPublicPath(AMEISE_MODULE) . '/css/style.css') }}" rel="stylesheet" type="text/css">
@section('javascripts')
    @parent
    <script src="{{ Module::getPublicPath(AMEISE_MODULE) . '/js/crm_users.js' }}"></script>
    <script>
            let translations = {
                userName: '{{ __('User Name') }}',
                email: '{{ __('Email') }}',
                address: '{{ __('Address') }}',
                phones: '{{ __('Phones') }}'
            };
    </script>
@endsection
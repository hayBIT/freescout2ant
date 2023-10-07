<link href="{{ asset(Module::getPublicPath(AMEISE_MODULE) . '/css/style.css') }}" rel="stylesheet" type="text/css">
@section('javascripts')
    @parent
    <script src="{{ Module::getPublicPath(AMEISE_MODULE) . '/js/crm_users.js' }}"></script>
@endsection
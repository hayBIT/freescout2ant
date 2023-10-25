<?php
return [
    'name' => 'AmeiseModule',
    'ameise_client_secret' => env('AMEISE_CLIENT_SECRET'),
    'ameise_mode' => env('AMEISE_MODE', 'test'), // test or live
    'ameise_response_type' => env('AMEISE_RESPONSE_TYPE', 'code'),
    'ameise_client_id' => env('AMEISE_CLIENT_ID'),
    'ameise_state' => env('AMEISE_STATE', 'freescout'),
    'ameise_scope' => env('AMEISE_SCOPE', 'ameise/mitarbeiterwebservice offline_access'),
    'ameise_redirect_uri' => env('AMEISE_REDIRECT_URI', '/crm/auth'),
];


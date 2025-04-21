<?php

return [

    'name'                => 'AmeiseModule',

    // OAuth‑Grundkonfiguration
    'ameise_client_secret' => env('AMEISE_CLIENT_SECRET'),
    'ameise_client_id'     => env('AMEISE_CLIENT_ID'),
    'ameise_scope'         => env('AMEISE_SCOPE', 'ameise/mitarbeiterwebservice offline'),
    'ameise_state'         => env('AMEISE_STATE', 'freescout'),
    'ameise_response_type' => env('AMEISE_RESPONSE_TYPE', 'code'),
    'ameise_redirect_uri'  => env('AMEISE_REDIRECT_URI', '/crm/auth'),

    // Laufzeit‑Optionen
    'ameise_mode'          => env('AMEISE_MODE', 'test'),                   // test | live
    'ameise_log_status'    => env('AMEISE_LOG_STATUS', true),               // bool

    // NEU – API‑Endpunkte für Client & Refresh
    'ameise_base_uri'      => env('AMEISE_BASE_URI',  'https://api.ameise.local/v1/'),
    'ameise_oauth_token_url' => env('AMEISE_OAUTH_TOKEN_URL', 'https://api.ameise.local/oauth/token'),
];

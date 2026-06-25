<?php
return [
    'name' => 'AmeiseModule',
    'ameise_client_secret' => env('AMEISE_CLIENT_SECRET'),
    'ameise_mode' => env('AMEISE_MODE', 'test'), // test or live
    'ameise_response_type' => env('AMEISE_RESPONSE_TYPE', 'code'),
    'ameise_client_id' => env('AMEISE_CLIENT_ID'),
    'ameise_state' => env('AMEISE_STATE', 'freescout'),
    'ameise_scope' => env('AMEISE_SCOPE', 'ameise/mitarbeiterwebservice offline'),
    'ameise_redirect_uri' => env('AMEISE_REDIRECT_URI', '/crm/auth'),
    // Verbose logging can quickly grow the FreeScout activity_logs table.
    // Disable by default and allow opt-in via AMEISE_LOG_STATUS=true.
    'ameise_log_status' => env('AMEISE_LOG_STATUS', false),

    // Selects which Ameise API stack is used for reading and writing.
    //   'mitarbeiterwebservice' => legacy MitarbeiterWebservice REST API (default)
    //   'customer_archives'     => new stack: Stocks API (reads) + Customer Archives API (writes)
    'ameise_api' => env('AMEISE_API', 'mitarbeiterwebservice'),

    // Visibility of archive entries created via the Customer Archives API.
    'ameise_archive_is_public' => env('AMEISE_ARCHIVE_IS_PUBLIC', false),

    // Base URLs for the Customer Archives API (write), switched by ameise_mode.
    'ameise_archive_base_url_test' => env('AMEISE_ARCHIVE_BASE_URL_TEST', 'https://customer-archives-ameiseapis.inte.dionera.dev'),
    'ameise_archive_base_url_live' => env('AMEISE_ARCHIVE_BASE_URL_LIVE', 'https://customer-archives.ameiseapis.com'),

    // Base URLs for the Stocks API (read), switched by ameise_mode.
    'ameise_stocks_base_url_test' => env('AMEISE_STOCKS_BASE_URL_TEST', 'https://stocks-ameiseapis.inte.dionera.dev'),
    'ameise_stocks_base_url_live' => env('AMEISE_STOCKS_BASE_URL_LIVE', 'https://stocks.ameiseapis.com'),

];


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
    // Host der Archive-API (customer-archives). Leer lassen, um im Test-Modus den
    // Standard-Host zu verwenden; im Live-Modus ist der Wert zwingend zu setzen.
    'ameise_archive_api_url' => env('AMEISE_ARCHIVE_API_URL'),
    // Verbose logging can quickly grow the FreeScout activity_logs table.
    // Disable by default and allow opt-in via AMEISE_LOG_STATUS=true.
    'ameise_log_status' => env('AMEISE_LOG_STATUS', false),

];


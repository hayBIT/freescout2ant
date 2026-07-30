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

    // Automatische Zuordnung eingehender Konversationen. Standardmäßig aus, damit
    // die Trefferquote erst per `ameise:auto-assign --dry-run` geprüft werden kann.
    'ameise_auto_assign' => env('AMEISE_AUTO_ASSIGN', false),
    // Verträge/Sparten mit zuordnen, wenn sie sich eindeutig bestimmen lassen.
    'ameise_auto_assign_contracts' => env('AMEISE_AUTO_ASSIGN_CONTRACTS', true),
    // FreeScout-Nutzer, dessen Ameise-Token die automatischen Archivierungen ausführt.
    'ameise_service_user_id' => env('AMEISE_SERVICE_USER_ID'),
    // Kommagetrennte Mailbox-IDs; leer bedeutet alle Mailboxen.
    'ameise_auto_assign_mailboxes' => env('AMEISE_AUTO_ASSIGN_MAILBOXES', ''),
    // Nur Konversationen berücksichtigen, die nicht älter als X Tage sind.
    'ameise_auto_assign_max_age_days' => env('AMEISE_AUTO_ASSIGN_MAX_AGE_DAYS', 30),

];


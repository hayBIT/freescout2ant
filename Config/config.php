<?php

return [
    'name' => 'AmeiseModule',
    'ameise_client_secret' => env('AMEISE_CLIENT_SECRET', 'KA58FTs6vcBHSTTock1u1KmM3IjP4tGnuch03ZpX2ka5utWBxtl11qQuygal_XJ-4R9WxVa9'),
    'ameise_mode' => env('AMEISE_MODE', 'test'), // test or live
    'ameise_response_type' => env('AMEISE_RESPONSE_TYPE', 'code'),
    'ameise_client_id' => env('AMEISE_CLIENT_ID', '67aa767e-2fe6-46e9-960f-74b871a849d1'),
    'ameise_state' => env('AMEISE_STATE', 'freescout'),
    'ameise_scope' => env('AMEISE_SCOPE', 'ameise/mitarbeiterwebservice offline_access'),
    'ameise_redirect_uri' => env('AMEISE_REDIRECT_URI', '/crm/auth'),

];

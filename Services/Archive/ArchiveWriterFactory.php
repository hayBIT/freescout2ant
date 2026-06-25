<?php

namespace Modules\AmeiseModule\Services\Archive;

use Modules\AmeiseModule\Services\CrmApiClient;
use Modules\AmeiseModule\Services\CustomerArchivesApiClient;
use Modules\AmeiseModule\Services\TokenService;

/**
 * Picks the archive write strategy based on the configured API
 * (config 'ameisemodule.ameise_api').
 */
class ArchiveWriterFactory
{
    public static function make(CrmApiClient $apiClient, TokenService $tokenService): ArchiveWriterInterface
    {
        if (config('ameisemodule.ameise_api') === 'customer_archives') {
            return new CustomerArchivesWriter(new CustomerArchivesApiClient($tokenService), $tokenService);
        }

        return new MitarbeiterWebserviceWriter($apiClient);
    }
}

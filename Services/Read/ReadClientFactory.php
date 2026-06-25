<?php

namespace Modules\AmeiseModule\Services\Read;

use Modules\AmeiseModule\Services\CrmApiClient;
use Modules\AmeiseModule\Services\Stocks\StocksApiClient;
use Modules\AmeiseModule\Services\TokenService;

/**
 * Picks the read strategy based on the configured API
 * (config 'ameisemodule.ameise_api').
 */
class ReadClientFactory
{
    public static function make(CrmApiClient $apiClient, TokenService $tokenService): CrmReadClientInterface
    {
        if (config('ameisemodule.ameise_api') === 'customer_archives') {
            return new StocksReadClient(new StocksApiClient($tokenService));
        }

        return new MitarbeiterWebserviceReadClient($apiClient);
    }
}

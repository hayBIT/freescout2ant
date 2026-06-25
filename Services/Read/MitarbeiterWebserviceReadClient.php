<?php

namespace Modules\AmeiseModule\Services\Read;

use Modules\AmeiseModule\Services\CrmApiClient;

/**
 * Legacy read strategy: delegates straight to the MitarbeiterWebservice client.
 */
class MitarbeiterWebserviceReadClient implements CrmReadClientInterface
{
    private $apiClient;

    public function __construct(CrmApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    public function fetchUserByIdOrName($data)
    {
        return $this->apiClient->fetchUserByIdOrName($data);
    }

    public function fetchUserDetail($id, $endPoints)
    {
        return $this->apiClient->fetchUserDetail($id, $endPoints);
    }

    public function getContracts($customerId)
    {
        return $this->apiClient->getContracts($customerId);
    }

    public function getContactEndPoints($endPoints)
    {
        return $this->apiClient->getContactEndPoints($endPoints);
    }
}

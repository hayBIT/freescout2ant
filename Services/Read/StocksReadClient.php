<?php

namespace Modules\AmeiseModule\Services\Read;

use Modules\AmeiseModule\Services\Stocks\StocksApiClient;

/**
 * Read strategy backed by the Stocks API. Responses are normalised to the legacy
 * MitarbeiterWebservice shape expected by AmeiseController and the frontend.
 */
class StocksReadClient implements CrmReadClientInterface
{
    private $stocks;

    public function __construct(StocksApiClient $stocks)
    {
        $this->stocks = $stocks;
    }

    public function fetchUserByIdOrName($data)
    {
        $customers = $this->stocks->searchCustomersByNameOrId($data);

        // Stocks only returns id + displayName for searches; map onto the legacy
        // shape so the controller's field access keeps working (address/first
        // name are not available via the search endpoint).
        return array_map(function ($customer) {
            $displayName = $customer['displayName'] ?? '';

            return [
                'Id' => $customer['id'] ?? null,
                'Text' => $displayName,
                'Person' => ['Vorname' => '', 'Nachname' => $displayName],
                'Hauptwohnsitz' => ['Strasse' => '', 'Postleitzahl' => '', 'Ort' => '', 'Land' => ''],
            ];
        }, $customers);
    }

    public function fetchUserDetail($id, $endPoints)
    {
        if ($endPoints !== 'kontaktdaten') {
            return [];
        }

        $communications = $this->stocks->getCustomerCommunications($id);
        $details = [];

        foreach ($communications['emails'] ?? [] as $item) {
            $value = $this->commValue($item);
            if ($value !== null) {
                $details[] = ['Typ' => 'email', 'Value' => $value];
            }
        }

        foreach (array_merge($communications['phones'] ?? [], $communications['mobiles'] ?? []) as $item) {
            $value = $this->commValue($item);
            if ($value !== null) {
                $details[] = ['Typ' => 'telefon', 'Value' => $value];
            }
        }

        return $details;
    }

    public function getContracts($customerId)
    {
        $items = $this->stocks->getCustomerContracts($customerId);

        // Pass through the token/redirect error so the controller can react.
        if (is_array($items) && isset($items['error'], $items['url'])) {
            return $items;
        }

        return array_map(function ($contract) {
            return [
                'Id' => $contract['id'] ?? null,
                'Risiko' => $contract['risk'] ?? null,
                'Versicherungsscheinnummer' => $contract['policyNumber'] ?? null,
                'Sparte' => $contract['line']['id'] ?? null,
                'Status' => $contract['state']['id'] ?? null,
            ];
        }, $items);
    }

    public function getContactEndPoints($endPoints)
    {
        if ($endPoints === 'sparten') {
            return array_map(fn($line) => [
                'Value' => $line['id'] ?? ($line['value'] ?? null),
                'Text' => $line['text'] ?? ($line['label'] ?? ($line['name'] ?? null)),
            ], $this->stocks->getContractLines());
        }

        if ($endPoints === 'vertragsstatus') {
            return array_map(fn($state) => [
                'Value' => $state['id'] ?? null,
                'Text' => $state['text'] ?? null,
            ], $this->stocks->getContractStates());
        }

        return [];
    }

    /**
     * Extract a usable string value from an (untyped) communication item.
     */
    private function commValue($item)
    {
        if (is_string($item)) {
            return $item !== '' ? $item : null;
        }

        if (is_array($item)) {
            foreach (['value', 'address', 'email', 'mail', 'number', 'phone', 'text'] as $key) {
                if (!empty($item[$key]) && is_string($item[$key])) {
                    return $item[$key];
                }
            }
        }

        return null;
    }
}

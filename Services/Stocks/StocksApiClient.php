<?php

namespace Modules\AmeiseModule\Services\Stocks;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException as Exception;
use Modules\AmeiseModule\Services\TokenService;

/**
 * Low-level client for the new Stocks API (customer search, communications,
 * contracts and reference data).
 */
class StocksApiClient
{
    private $base_url;
    private $tokenService;
    private $ameiseLogStatus;
    private $client;

    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
        $this->base_url = rtrim(
            config('ameisemodule.ameise_mode') == 'test'
                ? config('ameisemodule.ameise_stocks_base_url_test')
                : config('ameisemodule.ameise_stocks_base_url_live'),
            '/'
        );
        $this->ameiseLogStatus = config('ameisemodule.ameise_log_status');
        $this->client = new Client();
    }

    public function searchCustomersByNameOrId($query): array
    {
        return $this->request('POST', '/api/customers/names', [
            'json' => ['customerIds' => [], 'nameOrId' => (string) $query],
        ], 'stocks_customer_search') ?? [];
    }

    public function getCustomerCommunications($customerId): array
    {
        return $this->request('GET', '/api/customers/' . rawurlencode((string) $customerId) . '/communications', [], 'stocks_communications') ?? [];
    }

    /**
     * @return array list of contract items, or an error/redirect array on token failure
     */
    public function getCustomerContracts($customerId)
    {
        if ($this->tokenHasError($this->tokenService->getAccessToken())) {
            return ['error' => 'redirect', 'url' => $this->tokenService->getAuthUrl()];
        }
        $response = $this->request('GET', '/api/contracts/' . rawurlencode((string) $customerId), [], 'stocks_contracts');

        return is_array($response) && isset($response['items']) ? $response['items'] : [];
    }

    public function getContractLines(): array
    {
        $response = $this->request('GET', '/api/contracts/lines', [], 'stocks_contract_lines') ?? [];

        return isset($response['items']) ? $response['items'] : $response;
    }

    public function getContractStates(): array
    {
        return $this->request('GET', '/api/contracts/states', [], 'stocks_contract_states') ?? [];
    }

    /**
     * @return array|null decoded JSON body, or null on failure
     */
    private function request($method, $path, array $options, $logContext)
    {
        try {
            $token = $this->tokenService->getAccessToken();
            if ($this->tokenHasError($token)) {
                $this->ameiseLogStatus && \Helper::log($logContext, 'Request skipped because token contains an error.');
                return null;
            }

            $url = $this->base_url . $path;
            $this->ameiseLogStatus && \Helper::log($logContext, 'Sending ' . $method . ' request to: ' . $url);

            $requestOptions = array_merge([
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'http_errors' => false,
            ], $options);

            $response = $this->client->request($method, $url, $requestOptions);
            $code = $response->getStatusCode();
            $this->ameiseLogStatus && \Helper::log($logContext, 'Response status: ' . $code);

            if ($code >= 200 && $code < 300) {
                return json_decode($response->getBody(), true);
            }

            if ($code === 401) {
                $this->tokenService->disconnectAmeise();
            } else {
                $this->ameiseLogStatus && \Helper::log($logContext, 'Request failed: ' . substr((string) $response->getBody(), 0, 2000));
            }
        } catch (Exception $e) {
            $this->ameiseLogStatus && \Helper::logException($e, $logContext);
            if ($e->getCode() === 401) {
                $this->tokenService->disconnectAmeise();
            }
        }

        return null;
    }

    private function tokenHasError($token): bool
    {
        return $token && is_string($token)
            && ($decoded = json_decode($token, true))
            && isset($decoded['error']);
    }
}

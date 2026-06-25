<?php

namespace Modules\AmeiseModule\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException as Exception;

/**
 * Low-level client for the new Customer Archives API
 * (POST /api/customers/{customerId}/archive-entries).
 */
class CustomerArchivesApiClient
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
                ? config('ameisemodule.ameise_archive_base_url_test')
                : config('ameisemodule.ameise_archive_base_url_live'),
            '/'
        );
        $this->ameiseLogStatus = config('ameisemodule.ameise_log_status');
        $this->client = new Client();
    }

    /**
     * Create a single archive entry for the given customer.
     */
    public function createArchiveEntry($customerId, array $payload): bool
    {
        try {
            $token = $this->tokenService->getAccessToken();
            if ($this->tokenHasError($token)) {
                $this->ameiseLogStatus && \Helper::log('conversation_archive', 'Archive entry skipped because token contains an error.');
                return false;
            }

            $url = $this->base_url . '/api/customers/' . rawurlencode((string) $customerId) . '/archive-entries';
            $this->ameiseLogStatus && \Helper::log('conversation_archive', 'Sending POST request to: ' . $url);

            $response = $this->client->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
                'http_errors' => false,
            ]);

            $code = $response->getStatusCode();
            $this->ameiseLogStatus && \Helper::log('conversation_archive', 'Response status: ' . $code);

            if ($code >= 200 && $code < 300) {
                return true;
            }

            if ($code === 401) {
                $this->tokenService->disconnectAmeise();
            } else {
                $this->ameiseLogStatus && \Helper::log('conversation_archive', 'Request failed with status code: ' . $code);
                $this->ameiseLogStatus && \Helper::log('conversation_archive', 'Error response: ' . substr((string) $response->getBody(), 0, 2000));
            }
        } catch (Exception $e) {
            $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
            $this->ameiseLogStatus && \Helper::log('conversation_archive', 'Error body: ' . substr($body, 0, 2000));
            $this->ameiseLogStatus && \Helper::logException($e, 'conversation_archive');
            if ($e->getCode() === 401) {
                $this->tokenService->disconnectAmeise();
            }
        }

        return false;
    }

    private function tokenHasError($token): bool
    {
        return $token && is_string($token)
            && ($decoded = json_decode($token, true))
            && isset($decoded['error']);
    }
}

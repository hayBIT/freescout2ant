<?php

namespace Modules\AmeiseModule\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException as Exception;

class CrmApiClient
{
    private $base_url;
    private $tokenService;
    private $ameiseLogStatus;
    private $client;

    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
        $this->base_url = (config('ameisemodule.ameise_mode') == 'test' ? 'https://mitarbeiterwebservice-maklerinfo.inte.dionera.dev/service/bd/employee/1.0/rest/' : 'https://mitarbeiterwebservice.maklerinfo.biz/service/bd/employee/1.0/rest/');
        $this->ameiseLogStatus = config('ameisemodule.ameise_log_status');
        $this->client = new Client();
    }

    private function getAccessToken()
    {
        return $this->tokenService->getAccessToken();
    }

    private function checkTokenError()
    {
        $result = $this->getAccessToken();
        if ($result && ($resultArray = json_decode($result, true)) && isset($resultArray['error'])) {
            return $resultArray;
        }
        return null;
    }

    private function apiGet($path, $logContext, array $options = [], $errorReturn = [], $useBaseOnly = false)
    {
        try {
            $tokenError = $this->checkTokenError();
            if ($tokenError) {
                return $tokenError;
            }
            $url = $useBaseOnly ? $this->base_url . $path : $this->maUrl($path);
            $this->ameiseLogStatus && \Helper::log($logContext, 'Sending GET request to: ' . $url);
            $requestOptions = array_merge([
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                ],
            ], $options);
            $response = $this->client->get($url, $requestOptions);
            $this->ameiseLogStatus && \Helper::log($logContext, 'Response status: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                $this->ameiseLogStatus && \Helper::log($logContext, 'Request completed.');
                return json_decode($response->getBody(), true) ?? $errorReturn;
            } elseif ($response->getStatusCode() === 401) {
                $this->tokenService->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->ameiseLogStatus && \Helper::log($logContext, 'Request failed with status code: ' . $response->getStatusCode());
                $this->ameiseLogStatus && \Helper::log($logContext, 'Error response: ' . json_encode($errorResponse));
            }
            $this->ameiseLogStatus && \Helper::log($logContext, 'Request completed.');
            return $errorReturn;
        } catch (Exception $e) {
            $this->ameiseLogStatus && \Helper::logException($e, $logContext);
            if ($e->getCode() === 401) {
                $this->tokenService->disconnectAmeise();
            }
            if ($e->hasResponse()) {
                $body = (string) $e->getResponse()->getBody();
                $this->ameiseLogStatus && \Helper::log($logContext, 'Error body: ' . $body);
            }
            return $errorReturn;
        }
    }

    private function maUrl($path)
    {
        return $this->base_url . $this->tokenService->getMa() . '/' . $path;
    }

    public function fetchUserByIdOrName($data)
    {
        $searchQuery = rawurlencode($data);
        return $this->apiGet(
            'kunden/_search?q=' . $searchQuery,
            'fetch_user_id_name'
        );
    }

    public function fetchUserDetail($id, $endPoints)
    {
        return $this->apiGet(
            'kunden/' . $id . '/' . $endPoints,
            'user_end_points'
        );
    }

    public function fetchUserByEmail($email)
    {
        return $this->apiGet(
            'kunden/_search',
            'fetch_user_email',
            ['query' => ['mail' => $email]]
        );
    }

    public function getContracts($customerId)
    {
        return $this->apiGet(
            'kunden/' . $customerId . '/vertraege',
            'get_contracts',
            [],
            ['error' => 'redirect', 'url' => $this->tokenService->getAuthUrl()]
        );
    }

    public function getContactEndPoints($end_points)
    {
        return $this->apiGet(
            $end_points,
            'contracts_end_points',
            [],
            [],
            true
        );
    }

    public function archiveConversation($data)
    {
        try {
            $tokenError = $this->checkTokenError();
            if ($tokenError) {
                return $tokenError;
            }
            $this->ameiseLogStatus && \Helper::log('conversation_archive', 'Archive conversation request called.');
            $headers = [
                'X-Dio-Betreff' =>  $data['subject'],
                'x-dio-metadaten' =>  json_encode($data['x-dio-metadaten']),
                'X-Dio-Typ' => $data['type'],
                'Content-Type' => $data['Content-Type'] ??  'text/plain; charset="utf-8"',
                'X-Dio-Zuordnungen' =>  json_encode($data['X-Dio-Zuordnungen']),
                'X-Dio-Datum' =>  $data['X-Dio-Datum'],
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
            ];
            $response = $this->client->request('POST', $this->maUrl('archiveintraege'), [
                'headers' => $headers,
                'body' => $data['body'],
            ]);
            $this->ameiseLogStatus && \Helper::log('conversation_archive', 'Response status: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                return true;
            } elseif ($response->getStatusCode() === 401) {
                $this->tokenService->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->ameiseLogStatus && \Helper::log('conversation_archive', 'Request failed with status code: ' . $response->getStatusCode());
                $this->ameiseLogStatus && \Helper::log('conversation_archive', 'Error response: ' . json_encode($errorResponse));
            }
            return false;
        } catch (Exception $e) {
            $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
            $this->ameiseLogStatus && \Helper::log('conversation_archive', 'Error body: ' . $body);
            $this->ameiseLogStatus && \Helper::logException($e, 'conversation_archive');
            if ($e->getCode() === 401) {
                $this->tokenService->disconnectAmeise();
            }
        }
        return false;
    }
}

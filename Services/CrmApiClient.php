<?php

namespace Modules\AmeiseModule\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException as Exception;

class CrmApiClient
{
    private $base_url;
    private $tokenService;
    private $amesieLogStatus;

    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
        $this->base_url = (config('ameisemodule.ameise_mode') == 'test' ? 'https://mitarbeiterwebservice-maklerinfo.inte.dionera.dev/service/bd/employee/1.0/rest/' : 'https://mitarbeiterwebservice.maklerinfo.biz/service/bd/employee/1.0/rest/');
        $this->amesieLogStatus = config('ameisemodule.ameise_log_status');
    }

    private function getAccessToken()
    {
        return $this->tokenService->getAccessToken();
    }

    public function fetchUserByIdOrName($data)
    {
        try {
            $result = $this->getAccessToken();
            if ($result && ($resultArray = json_decode($result, true)) && isset($resultArray['error'])) {
                return $resultArray;
            }
            $client = new Client();
            $this->amesieLogStatus && \Helper::log('fetch_user_id_name', 'Sending a user search request by id and name with access token: ' . $this->getAccessToken());
            $response = $client->get($this->base_url . $this->tokenService->getMa() . '/kunden/_search?q=' . $data, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                ],
            ]);
            $this->amesieLogStatus && \Helper::log('fetch_user_id_name', 'User search request by id and name response status is: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody(), true);
            } elseif ($response->getStatusCode() === 401) {
                $this->tokenService->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->amesieLogStatus && \Helper::log('fetch_user_id_name', 'User search request failed with status code: ' . $response->getStatusCode());
                $this->amesieLogStatus && \Helper::log('fetch_user_id_name', 'Error response: ' . json_encode($errorResponse));
            }
            $this->amesieLogStatus && \Helper::log('fetch_user_id_name', 'User search request by id and name has been completed.');
        } catch (Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'fetch_user_id_name');
            if ($e->getCode() === 401) {
                $this->tokenService->disconnectAmeise();
            }
        }
        return [];
    }

    public function fetchUserDetail($id, $endPoints)
    {
        try {
            $result = $this->getAccessToken();
            if ($result && ($resultArray = json_decode($result, true)) && isset($resultArray['error'])) {
                return $resultArray;
            }
            $client = new Client();
            $this->amesieLogStatus && \Helper::log('user_end_points', 'User end points request with access token: ' . $this->getAccessToken());
            $response = $client->get($this->base_url . $this->tokenService->getMa() . '/kunden/' . $id . '/' . $endPoints, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                ],
            ]);
            $this->amesieLogStatus && \Helper::log('user_end_points', 'User end points request response status: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody(), true);
            } elseif ($response->getStatusCode() === 401) {
                $this->tokenService->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->amesieLogStatus && \Helper::log('user_end_points', 'User end points request failed with status code: ' . $response->getStatusCode());
                $this->amesieLogStatus && \Helper::log('user_end_points', 'Error response: ' . json_encode($errorResponse));
            }
            $this->amesieLogStatus && \Helper::log('user_end_points', 'User end points request has been completed.');
        } catch (Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'user_end_points');
            if ($e->getCode() === 401) {
                $this->tokenService->disconnectAmeise();
            }
        }
        return [];
    }

    public function fetchUserByEmail($email)
    {
        try {
            $result = $this->getAccessToken();
            if ($result && ($resultArray = json_decode($result, true)) && isset($resultArray['error'])) {
                return $resultArray;
            }
            $client = new Client();
            $this->amesieLogStatus && \Helper::log('fetch_user_email', 'Fetch user by email request with access token: ' . $this->getAccessToken());
            $response = $client->get($this->base_url . $this->tokenService->getMa() . '/kunden/_search', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                ],
                'query' => [
                    'mail' => $email,
                ]
            ]);
            $this->amesieLogStatus && \Helper::log('fetch_user_email', 'fetch user by email request response status: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody(), true);
            } elseif ($response->getStatusCode() === 401) {
                $this->tokenService->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->amesieLogStatus && \Helper::log('fetch_user_email', 'Fetch user by email request failed with status code: ' . $response->getStatusCode());
                $this->amesieLogStatus && \Helper::log('fetch_user_email', 'Error response: ' . json_encode($errorResponse));
            }
            $this->amesieLogStatus && \Helper::log('fetch_user_email', 'fetch user by email has been completed.');
        } catch (Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'fetch_user_email');
            if ($e->getCode() === 401) {
                $this->tokenService->disconnectAmeise();
            }
        }
        return [];
    }

    public function getContracts($customerId)
    {
        try {
            $result = $this->getAccessToken();
            if ($result && ($resultArray = json_decode($result, true)) && isset($resultArray['error'])) {
                return $resultArray;
            }
            $client = new Client();
            $this->amesieLogStatus && \Helper::log('get_contracts', 'get contract request with access token: ' . $this->getAccessToken());
            $response = $client->get($this->base_url . $this->tokenService->getMa() . '/kunden/' . $customerId . '/vertraege', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                ],
            ]);
            $this->amesieLogStatus && \Helper::log('get_contracts', 'get contracts request response status is: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody(), true);
            } elseif ($response->getStatusCode() === 401) {
                $this->tokenService->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->amesieLogStatus && \Helper::log('get_contracts', 'get contract request failed with status code: ' . $response->getStatusCode());
                $this->amesieLogStatus && \Helper::log('get_contracts', 'Error response: ' . json_encode($errorResponse));
            }
            $this->amesieLogStatus && \Helper::log('get_contracts', 'Get contract request has been completed.');
        } catch (Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'get_contracts');
            if ($e->getCode() === 401) {
                $this->tokenService->disconnectAmeise();
            }
            return ['error' => 'redirect' ,'url' => $this->tokenService->getAuthUrl()];
        }
        return [];
    }

    public function getContactEndPoints($end_points)
    {
        try {
            $result = $this->getAccessToken();
            if ($result && ($resultArray = json_decode($result, true)) && isset($resultArray['error'])) {
                return $resultArray;
            }
            $client = new Client();
            $this->amesieLogStatus && \Helper::log('contracts_end_points', 'get contract end points request with access token: ' . $this->getAccessToken());
            $response = $client->get($this->base_url . $end_points, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                ],
            ]);
            $this->amesieLogStatus && \Helper::log('contracts_end_points', 'get contracts end points request response status is: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody(), true);
            } elseif ($response->getStatusCode() === 401) {
                $this->tokenService->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->amesieLogStatus && \Helper::log('contracts_end_points', 'get contract end points request failed with status code: ' . $response->getStatusCode());
                $this->amesieLogStatus && \Helper::log('contracts_end_points', 'Error response: ' . json_encode($errorResponse));
            }
            $this->amesieLogStatus && \Helper::log('contracts_end_points', 'get contract end points  request has been completed.');
            return [];
        } catch (Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'conversation_archive');
            if ($e->getCode() === 401) {
                $this->tokenService->disconnectAmeise();
            }
        }
        return [];
    }

    public function archiveConversation($data)
    {
        $result = $this->getAccessToken();
        if ($result && ($resultArray = json_decode($result, true)) && isset($resultArray['error'])) {
            return $resultArray;
        }
        $client = new Client();
        $this->amesieLogStatus && \Helper::log('conversation_archive', 'archive conversation request called with access token: ' . $this->getAccessToken());
        $headers = [
            'X-Dio-Betreff' =>  $data['subject'],
            'x-dio-metadaten' =>  json_encode($data['x-dio-metadaten']),
            'X-Dio-Typ' => $data['type'],
            'Content-Type' => $data['Content-Type'] ??  'text/plain; charset="utf-8"',
            'X-Dio-Zuordnungen' =>  json_encode($data['X-Dio-Zuordnungen']),
            'X-Dio-Datum' =>  $data['X-Dio-Datum'],
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];
        try {
            $response = $client->request('POST', $this->base_url . $this->tokenService->getMa() . '/archiveintraege', [
                'headers' => $headers,
                'body' => $data['body'],
            ]);
            $this->amesieLogStatus && \Helper::log('conversation_archive', 'archive conversation request response status is: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                return $response->getBody();
            } elseif ($response->getStatusCode() === 401) {
                $this->tokenService->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->amesieLogStatus && \Helper::log('conversation_archive', 'archive conversation request failed with status code: ' . $response->getStatusCode());
                $this->amesieLogStatus && \Helper::log('conversation_archive', 'Error response: ' . json_encode($errorResponse));
            }
        } catch (Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'conversation_archive');
            if ($e->getCode() === 401) {
                $this->tokenService->disconnectAmeise();
            }
        }
        return false;
    }
}

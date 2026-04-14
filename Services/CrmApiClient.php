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
                return json_decode($response->getBody(), true);
            } elseif ($response->getStatusCode() === 401) {
                $this->tokenService->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->ameiseLogStatus && \Helper::log($logContext, 'Request failed with status code: ' . $response->getStatusCode());
                $this->ameiseLogStatus && \Helper::log(
                    $logContext,
                    'Error response: ' . json_encode($this->sanitizeLogData($errorResponse))
                );
            }
            $this->ameiseLogStatus && \Helper::log($logContext, 'Request completed.');
        } catch (Exception $e) {
            $this->ameiseLogStatus && \Helper::logException($e, $logContext);
            if ($e->getCode() === 401) {
                $this->tokenService->disconnectAmeise();
            }
            if ($e->hasResponse()) {
                $body = (string) $e->getResponse()->getBody();
                $this->ameiseLogStatus && \Helper::log($logContext, 'Error body: ' . $this->sanitizeLogText($body));
            }
            return $errorReturn;
        }
        return [];
    }

    private function apiPost($path, $logContext, array $options = [], $errorReturn = [], $useBaseOnly = false)
    {
        try {
            $tokenError = $this->checkTokenError();
            if ($tokenError) {
                return $tokenError;
            }
            $url = $useBaseOnly ? $this->base_url . $path : $this->maUrl($path);
            $this->ameiseLogStatus && \Helper::log($logContext, 'Sending POST request to: ' . $url);
            $requestOptions = array_merge([
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                ],
            ], $options);
            $response = $this->client->post($url, $requestOptions);
            $this->ameiseLogStatus && \Helper::log($logContext, 'Response status: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody(), true);
            } elseif ($response->getStatusCode() === 401) {
                $this->tokenService->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->ameiseLogStatus && \Helper::log($logContext, 'Request failed with status code: ' . $response->getStatusCode());
                $this->ameiseLogStatus && \Helper::log(
                    $logContext,
                    'Error response: ' . json_encode($this->sanitizeLogData($errorResponse))
                );
            }
            $this->ameiseLogStatus && \Helper::log($logContext, 'Request completed.');
        } catch (Exception $e) {
            $this->ameiseLogStatus && \Helper::logException($e, $logContext);
            if ($e->getCode() === 401) {
                $this->tokenService->disconnectAmeise();
            }
            if ($e->hasResponse()) {
                $body = (string) $e->getResponse()->getBody();
                $this->ameiseLogStatus && \Helper::log($logContext, 'Error body: ' . $this->sanitizeLogText($body));
            }
            return $errorReturn;
        }
        return [];
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
        $response = $this->apiPost(
            'kunden/_search',
            'fetch_user_email',
            ['json' => ['mail' => $email]]
        );

        if (!empty($response)) {
            return $response;
        }

        return $this->apiGet(
            'kunden/_search',
            'fetch_user_email_fallback',
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
                return $response->getBody();
            } elseif ($response->getStatusCode() === 401) {
                $this->tokenService->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->ameiseLogStatus && \Helper::log('conversation_archive', 'Request failed with status code: ' . $response->getStatusCode());
                $this->ameiseLogStatus && \Helper::log(
                    'conversation_archive',
                    'Error response: ' . json_encode($this->sanitizeLogData($errorResponse))
                );
            }
        } catch (Exception $e) {
            $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
            $this->ameiseLogStatus && \Helper::log('conversation_archive', 'Error body: ' . $this->sanitizeLogText($body));
            $this->ameiseLogStatus && \Helper::logException($e, 'conversation_archive');
            if ($e->getCode() === 401) {
                $this->tokenService->disconnectAmeise();
            }
        }
        return false;
    }

    private function sanitizeLogData($data)
    {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && $this->isSensitiveKey($key)) {
                    $sanitized[$key] = $this->valueFingerprint($value);
                    continue;
                }
                $sanitized[$key] = $this->sanitizeLogData($value);
            }
            return $sanitized;
        }

        if (is_object($data)) {
            return $this->sanitizeLogData((array) $data);
        }

        if (is_string($data)) {
            return $this->sanitizeLogText($data);
        }

        return $data;
    }

    private function sanitizeLogText(string $text): string
    {
        $pattern = '/(Bearer\s+)([A-Za-z0-9\-\._~\+\/]+=*)/i';
        return preg_replace_callback($pattern, function (array $matches) {
            return $matches[1] . $this->valueFingerprint($matches[2]);
        }, $text) ?? $text;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        return in_array($normalized, ['access_token', 'refresh_token', 'id_token', 'authorization', 'token'], true);
    }

    private function valueFingerprint($value): string
    {
        if (!is_string($value) || $value === '') {
            return '[redacted]';
        }

        return '[fingerprint:sha256:' . substr(hash('sha256', $value), 0, 12) . ']';
    }
}

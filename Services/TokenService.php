<?php

namespace Modules\AmeiseModule\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException as Exception;

class TokenService
{
    private $fileName = '_ant.txt';
    private $access_token;
    private $refresh_token;
    private $clientId;
    private $clientSecret;
    private $scope;
    private $code;
    private $redirectUrl;
    private $amesieLogStatus;
    private $file;
    private $url;
    public $ma;

    public function __construct($code = '', $userId = '')
    {
        $this->url = (config('ameisemodule.ameise_mode') == 'test' ? 'https://auth.inte.dionera.dev' : 'https://auth.dionera.com');
        $this->file = 'user_' . $userId . $this->fileName;
        $this->redirectUrl = config('ameisemodule.ameise_redirect_uri');
        $this->clientId = config('ameisemodule.ameise_client_id');
        $this->clientSecret = config('ameisemodule.ameise_client_secret');
        $this->scope = config('ameisemodule.ameise_scope');
        $this->code = $code;
        $this->amesieLogStatus = config('ameisemodule.ameise_log_status');
    }

    public function getAuthUrl()
    {
        return $this->url . '/oauth2/auth?response_type=code&client_id=' . $this->clientId . '&redirect_uri=' . $this->redirectUrl . '&scope=' . $this->scope . '&state=' . config('ameisemodule.ameise_state');
    }

    public function getAccessToken()
    {
        try {
            if (!file_exists(storage_path($this->file))) {
                $this->amesieLogStatus && \Helper::log('token_end_point', 'Token file does not exist. Creating a new file.');
                $result = $this->createTokenFile();
                if(isset($result)){
                    return $result;
                }
            }
            $tokens = json_decode(file_get_contents(storage_path($this->file)));
            $this->access_token = $tokens->access_token;
            if (!empty($tokens->ma)) {
                $this->ma = $tokens->ma;
            } else {
                $this->amesieLogStatus && \Helper::log('user_info', 'User info missing. Calling userInfo to retrieve it.');
                $this->userInfo();
            }
            if ($this->dateTimePassed($tokens->expires_in)) {
                $this->refresh_token = $tokens->refresh_token;
                $this->amesieLogStatus && \Helper::log('token_end_point', 'Access token has expired. Creating a new token file.');
                $this->createTokenFile();
            }
            $this->amesieLogStatus && \Helper::log('token_end_point', 'Access token retrieved successfully.' . $this->access_token);
            return $this->access_token;
        } catch (\Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'token_end_point');
        }
    }

    public function disconnectAmeise()
    {
        $filePath = storage_path($this->file);
        if (file_exists($filePath)) {
            unlink($filePath);
            return true;
        }
        return false;
    }

    private function dateTimePassed(string $dt_to_check): bool
    {
        $dt1 = strtotime(date('Y-m-d H:i:s', strtotime($dt_to_check)));
        $dt2 = strtotime(date('Y-m-d H:i:s'));
        return $dt1 < $dt2;
    }

    public function createTokenFile()
    {
        $filePath = storage_path($this->file);
        try {
            $headers = [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            ];
            $client = new Client();
            if(empty($this->code)){
                return json_encode(['error' => 'redirect', 'url' => $this->getAuthUrl()]);
            }
            $requestData = [
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUrl,
                'scope' => $this->scope,
                'code' => $this->code
            ];
            if (!empty($this->refresh_token)) {
                $requestData = [
                    'grant_type' => 'refresh_token',
                    'redirect_uri' => $this->redirectUrl,
                    'scope' => $this->scope,
                    'refresh_token' => $this->refresh_token
                ];
            }
            $this->amesieLogStatus && \Helper::log('token_generate', 'Sending a token request to ' . $this->url . '/oauth2/token');
            $response = $client->post($this->url . '/oauth2/token', [
                'headers' => $headers,
                'form_params' => $requestData,
            ]);
            $this->amesieLogStatus && \Helper::log('token_generate', 'Token request sent with status code: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getBody(), true);
                $this->access_token = $responseData['access_token'];
                $responseData['ma'] = '';
                $fp = fopen($filePath, 'w');
                fwrite($fp, json_encode($responseData));
                fclose($fp);
                $this->amesieLogStatus && \Helper::log('token_generate', 'Token file created successfully.');
                $this->userInfo();
            } else {
                unlink($filePath);
                $this->amesieLogStatus && \Helper::log('token_generate', 'Token request failed with status code: ' . $response->getStatusCode());
                $errorResponse = json_decode($response->getBody(), true);
                $this->amesieLogStatus && \Helper::log('token_generate', 'Error response:' . json_encode($errorResponse));
            }
        } catch (Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'token_generate');
        }
    }

    public function userInfo()
    {
        try {
            $tokens = json_decode(file_get_contents(storage_path($this->file)));
            $this->amesieLogStatus && \Helper::log('user_info', 'Sending a userinfo request with access token: ' . $this->access_token);
            $client = new Client();
            $response = $client->get($this->url . '/userinfo', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type' => 'application/json',
                ]
            ]);
            $this->amesieLogStatus && \Helper::log('user_info', 'Userinfo request response data: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getBody(), true);
                $tokens->ma = $responseData['sub'];
                $fp = fopen(storage_path($this->file), 'w');
                fwrite($fp, json_encode($tokens));
                fclose($fp);
            } elseif ($response->getStatusCode() === 401) {
                $this->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->amesieLogStatus && \Helper::log('user_info', 'User info request failed with status code: ' . $response->getStatusCode());
                $this->amesieLogStatus && \Helper::log('user_info', 'Error response:' . json_encode($errorResponse));
            }
            $this->amesieLogStatus && \Helper::log('user_info', 'User info request completed.');
            return $responseData ?? null;
        } catch (Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'user_info');
            if ($e->getCode() === 401) {
                $this->disconnectAmeise();
            }
        }
    }

    public function getMa()
    {
        return $this->ma;
    }
}

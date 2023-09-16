<?php

namespace Modules\AmeiseModule\Services;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException as Exception;

class CrmService
{
    private $fileName = '_ant.txt';
    public $base_url;
    public $ma;
    private $access_token;
    private $clientId;
    private $clientSecret;
    private $scope;
    private $code;
    private $refresh_token;
    private $redirectUrl;
    public $file;
    private $url;


    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct($code = '', $userId = '')
    {
        $this->base_url = (config('ameisemodule.ameise_mode') == 'test' ? 'https://mitarbeiterwebservice-maklerinfo.inte.dionera.dev/service/bd/employee/1.0/rest/' : 'https://mitarbeiterwebservice.maklerinfo.biz/service/bd/employee/1.0/rest/');
        $this->url = (config('ameisemodule.ameise_mode') == 'test' ? 'https://auth.inte.dionera.dev' : 'https://auth.dionera.com');
        $this->file = 'user_' . $userId . $this->fileName;
        $this->redirectUrl = config('ameisemodule.ameise_redirect_uri');
        $this->clientId = config('ameisemodule.ameise_client_id');
        $this->clientSecret = config('ameisemodule.ameise_client_secret');
        $this->scope = config('ameisemodule.ameise_scope'); // e.g., 'read write'
        $this->code = $code;
    }

    public function getAccessToken()
    {
        if (!file_exists(storage_path($this->file))) { //First time use = create the file
            $this->createTokenFile();
        }
        $tokens = json_decode(file_get_contents(storage_path($this->file)));
        $this->access_token = $tokens->access_token;
        if (!empty($tokens->ma)) {
            $this->ma = $tokens->ma;
        } else {
            $this->userInfo();
        }
        if ($this->dateTimePassed($tokens->expires_in)) {
            $this->refresh_token = $tokens->refresh_token;
            $this->createTokenFile();
        }
    }

    public function dateTimePassed(string $dt_to_check): bool
    { //False on passed
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

            // Create a new Guzzle HTTP client instance
            $client = new Client();
            // Define the request data
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

            // Send the POST request
            $response = $client->post($this->url . '/oauth2/token', [
                'headers' => $headers,
                'form_params' => $requestData,
            ]);

            // Check if the request was successful
            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getBody(), true);
                $this->access_token = $responseData['access_token'];
                $responseData['ma'] = '';
                $fp = fopen($filePath, 'w');
                fwrite($fp, json_encode($responseData));
                fclose($fp);
                $this->userInfo();
            } else {
                unlink($filePath);
                $errorResponse = json_decode($response->getBody(), true);
            }
        } catch (Exception $e) {
            // Handle other exceptions
            echo "Exception:\n";
            echo $e->getMessage();
        }
    }

    public function disconnectAmeise()
    {
        // Check if the file exists and delete it
        $filePath = storage_path($this->file);
        if (file_exists($filePath)) {
            // Use unlink to delete the file
            unlink($filePath);
            return true;
        } else {
            // Redirect or perform other actions if the file does not exist
            return false;
        }
    }

    public function userInfo()
    {
        try {
            $tokens = json_decode(file_get_contents(storage_path($this->file)));
            $client = new Client();
            $response = $client->get($this->url . '/userinfo', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type' => 'application/json',
                ]
            ]);
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
            }
            return $responseData;
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                $this->disconnectAmeise();
            }
        }
    }

    public function fetchUserByIdOrName($data)
    {
        try {
            $this->getAccessToken();
            // Make an API request using the access token
            $client = new Client();
            $response = $client->get($this->base_url . $this->ma . '/kunden/_search?q=' . $data, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                ],
            ]);
            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getBody(), true);
            } elseif ($response->getStatusCode() === 401) {
                $this->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
            }
            return $responseData;
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                $this->disconnectAmeise();
            }
        }
    }

    public function getContracts($customerId)
    {
        try {
            $this->getAccessToken();
            $client = new Client();
            $response = $client->get($this->base_url . $this->ma . '/kunden/' . $customerId . '/vertraege', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                ],
            ]);
            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getBody(), true);
            } elseif ($response->getStatusCode() === 401) {
                $this->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
            }
            return $responseData;
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                $this->disconnectAmeise();
            }
        }
    }

    public function getContactEndPoints($end_points)
    {
        try {
            $this->getAccessToken();
            $client = new Client();
            $response = $client->get($this->base_url . $end_points, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                ],
            ]);
            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getBody(), true);
            } elseif ($response->getStatusCode() === 401) {
                $this->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
            }
            return $responseData;
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                $this->disconnectAmeise();
            }
        }
    }

    public function archiveConversation($data)
    {
        $this->getAccessToken();
        $client = new Client();
        $headers = [
            'X-Dio-Betreff' =>  $data['subject'],
            'x-dio-metadaten' =>  json_encode($data['x-dio-metadaten']),
            'X-Dio-Typ' => $data['type'],
            'Content-Type' => 'text/plain',
            'X-Dio-Zuordnungen' =>  json_encode($data['X-Dio-Zuordnungen']),
            'Authorization' => 'Bearer ' . $this->access_token,
        ];

        try {
            $response = $client->request('POST', $this->base_url . $this->ma . '/archiveintraege', [
                'headers' => $headers,
                'body' => $data['body'],
            ]);
            // Process the response data here
            if ($response->getStatusCode() === 200) {
                $responseData = $response->getBody();
            } elseif ($response->getStatusCode() === 401) {
                $this->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
            }
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                $this->disconnectAmeise();
            }
        }
        return $responseData;
    }

    public function getAuthURl()
    {
        return $this->url . '/oauth2/auth?response_type=code&client_id=' . $this->clientId . '&redirect_uri=' . $this->redirectUrl . '&scope=' . $this->scope . '&state=' . config('ameisemodule.ameise_state');
    }
}

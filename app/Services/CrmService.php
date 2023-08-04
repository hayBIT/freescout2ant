<?php

namespace App\Services;

use Illuminate\Support\Facades\Request;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Session;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class CrmService
{
    private $file = 'ANT.txt';
    public $base_url = 'https://mitarbeiterwebservice-maklerinfo.inte.dionera.dev/service/bd/employee/1.0/rest';
    public $ma = '/98A71H_UW7ZQ6';
    private $access_token;
    private $clientId;
    private $clientSecret;
    private $scope;
    private $code;


    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct($code = '')
    {
        // $this->file = 'user_'.auth()->user()->id.'_'.$this->file;
        $this->clientId = '67aa767e-2fe6-46e9-960f-74b871a849d1';
        $this->clientSecret = 'KA58FTs6vcBHSTTock1u1KmM3IjP4tGnuch03ZpX2ka5utWBxtl11qQuygal_XJ-4R9WxVa9';
        $this->scope = 'ameise/mitarbeiterwebservice'; // e.g., 'read write'
        $this->code = $code;
        $this->getAccessToken();
    }

    public function getAccessToken()
    {
        if (!file_exists(storage_path($this->file))) {//First time use = create the file
            $this->createTokenFile();
        }
        $tokens = json_decode(file_get_contents(storage_path($this->file)));
        $this->access_token = $tokens->access_token;
        $this->code = $tokens->code;
        if ($this->dateTimePassed($tokens->expires)) {
            $this->createTokenFile();
            $this->getAccessToken();
        }
    }

    public function dateTimePassed(string $dt_to_check): bool
    {//False on passed
        $dt1 = strtotime(date('Y-m-d H:i:s', strtotime($dt_to_check)));
        $dt2 = strtotime(date('Y-m-d H:i:s'));
        return $dt1 < $dt2;
    }

    public function createTokenFile()
    {
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
        ];

        // Create a new Guzzle HTTP client instance
        $client = new Client();

        // Send a POST request to obtain the access token
        $response = $client->post('https://auth.inte.dionera.dev/oauth2/token', [
            'headers' => $headers,
            'form_params' => [
                'grant_type' => 'authorization_code',
                'redirect_uri' => 'https://freescout2ant.vhostevents.com/',
                'scope' => $this->scope,
                'code' => $this->code

            ],
        ]);
        // Check if the request was successful
        if ($response->getStatusCode() === 200) {
            $responseData = json_decode($response->getBody(), true);
            $this->access_token = $responseData['access_token'];
            $contents = '{"access_token": "' . $responseData['access_token'] . '", "expires": "' . $responseData['expires_in'] . '", "code": "' . $this->code . '"}';
            $fp = fopen(storage_path($this->file), 'w');
            fwrite($fp, $contents);
            fclose($fp);
        } else {
            $errorResponse = json_decode($response->getBody(), true);
        }
    }



    public function fetchUserDetail($email)
    {
        // Make an API request using the access token
        $client = new Client();
        $jsonData = [
            'mail' => $email,
            // Add more key-value pairs as needed
        ];
        $response = $client->get($this->base_url.$this->ma.'/kunden/_search', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/json',
            ],
            'json' => $jsonData, // Add the JSON data to the request body
        ]);
        if ($response->getStatusCode() === 200) {
            $responseData = json_decode($response->getBody(), true);
        } else {
            $errorResponse = json_decode($response->getBody(), true);
        }
        return $responseData;
    }

    public function fetchUserInformation($client_id, $end_point)
    {
        // Make an API request using the access token
        $client = new Client();
        $response = $client->get($this->base_url.$this->ma.'/kunden/'.$client_id.'/'.$end_point, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
            ],
        ]);
        if ($response->getStatusCode() === 200) {
            $responseData = json_decode($response->getBody(), true);
        } else {
            $errorResponse = json_decode($response->getBody(), true);
        }
        return $responseData;
    }

    public function fetchUserByIdOrName($data)
    {
        // Make an API request using the access token
        $client = new Client();
        $response = $client->get($this->base_url.$this->ma.'/kunden/_search?q='.$data, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
            ],
        ]);
        if ($response->getStatusCode() === 200) {
            $responseData = json_decode($response->getBody(), true);
        } else {
            $errorResponse = json_decode($response->getBody(), true);
        }
        return $responseData;
    }

    public function archiveConversation()
    {
        $host = "www.maklerinfo.biz";
        $betreff = "Lorem Ipsum dolor";
        $tags = ["Lorem", "Ipsum"];
        $metadaten = [["Value" => "Bar", "Text" => "Foo"]];
        $typ = "email";
        $content = "Lorem ipsum dolor sit amet..";

        $headers = [
            'Host' => $host,
            'Authorization' => 'Bearer ' . $this->access_token,
            'X-Dio-Betreff' => $betreff,
            'X-Dio-Tags' => json_encode($tags),
            'X-Dio-Metadaten' => json_encode($metadaten),
            'X-Dio-Typ' => $typ,
            'Content-Type' => 'text/plain; charset="utf-8"',
        ];

        // Prepare the request body
        $body = $content;

        // Send the HTTP POST request using Guzzle
        $client = new Client();
        $response = $client->post($this->base_url.$this->ma.'/archiveintraege', [
            'headers' => $headers,
            'body' => $body,
        ]);
        if ($response->getStatusCode() === 200) {
            $responseData = json_decode($response->getBody(), true);
        } else {
            $errorResponse = json_decode($response->getBody(), true);
        }
        return $responseData;
    }

    public function getContracts($customerId)
    {
        $client = new Client();
        $response = $client->get($this->base_url.$this->ma.'/kunden/'.$customerId.'/vertraege', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
            ],
        ]);
        if ($response->getStatusCode() === 200) {
            $responseData = json_decode($response->getBody(), true);
        } else {
            $errorResponse = json_decode($response->getBody(), true);
        }
        return $responseData;

    }

    public function getContactEndPoints($end_points)
    {
        $client = new Client();
        $response = $client->get($this->base_url.$end_points, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
            ],
        ]);
        if ($response->getStatusCode() === 200) {
            $responseData = json_decode($response->getBody(), true);
        } else {
            $errorResponse = json_decode($response->getBody(), true);
        }
        return $responseData;

    }



}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;

class CrmController extends Controller
{
    protected $apiUrl;

    public function __construct()
    {
        $this->apiUrl = 'https://mitarbeiterwebservice-maklerinfo.inte.dionera.dev/service/bd/employee/1.0/rest';

    }

    public function getAccessToken()
    {
        try {
            $clientId = '67aa767e-2fe6-46e9-960f-74b871a849d1';
            $clientSecret = 'KA58FTs6vcBHSTTock1u1KmM3IjP4tGnuch03ZpX2ka5utWBxtl11qQuygal_XJ-4R9WxVa9';
            $scope = 'ameise/mitarbeiterwebservice'; // e.g., 'read write'

            $headers = [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic '.$this->base64UrlEncode($clientId.':'.$clientSecret),
            ];

            // Create a new Guzzle HTTP client instance
            $client = new Client();

            // Send a POST request to obtain the access token
            $response = $client->post('https://auth.inte.dionera.dev/oauth2/token', [
                'headers' => $headers,
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => 'https://freescout2ant.vhostevents.com/',
                    'scope' => $scope,
                   
                ],
            ]);
            // Check if the request was successful
            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getBody(), true);
                $accessToken = $responseData['access_token'];
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                print_r($errorResponse);
               
            }
            die;
            return $accessToken;
            // Process and return the user details
        } catch (\Exception $e) {
            Log::error('fetch_user_detail_api', ['error' => $e]);
            // Handle the error gracefully
            return $this->failResponse();
        }
    }

    public function makeAPIRequest()
    {

        $accessToken = $this->getAccessToken();

        // Make an API request using the access token
        $client = new Client();
        $response = $client->get($this->apiUrl.'/kunden/5001847235', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
        ]);
        print_r($response);

        // Process the API response as needed
        // ...
    }
}

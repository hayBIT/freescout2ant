<?php

namespace Modules\AmeiseModule\Services;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use GuzzleHttp\Client;
use App\Conversation;
use GuzzleHttp\Exception\ClientException as Exception;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log; // Import the Log facade
use Modules\AmeiseModule\Entities\CrmArchive;
use App\Thread;
use App\Customer;
use Modules\AmeiseModule\Entities\CrmArchiveThread;

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
    private $amesieLogStatus;
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
        $this->amesieLogStatus =  config('ameisemodule.ameise_log_status');
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
            if(empty($this->code)){
                return(json_encode(['error' => 'redirect', 'url' => $this->getAuthURl()]));
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

            // Log before sending the POST request
            $this->amesieLogStatus && \Helper::log('token_generate', 'Sending a token request to ' . $this->url . '/oauth2/token');

            // Send the POST request
            $response = $client->post($this->url . '/oauth2/token', [
                'headers' => $headers,
                'form_params' => $requestData,
            ]);

            // Log after sending the POST request
            $this->amesieLogStatus && \Helper::log('token_generate', 'Token request sent with status code: ' . $response->getStatusCode());

            // Check if the request was successful
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
            // Log before sending the request
            $this->amesieLogStatus && \Helper::log('user_info', 'Sending a userinfo request with access token: ' . $this->access_token);
            $client = new Client();
            $response = $client->get($this->url . '/userinfo', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type' => 'application/json',
                ]
            ]);
            // Log after sending the request
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
                // Log the error response
                $this->amesieLogStatus && \Helper::log('user_info', 'User info request failed with status code: ' . $response->getStatusCode());
                $this->amesieLogStatus && \Helper::log('user_info', 'Error response:' . json_encode($errorResponse));
            }
            $this->amesieLogStatus && \Helper::log('user_info', 'User info request completed.');
            return $responseData;
        } catch (Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'user_info');
            if ($e->getCode() === 401) {
                $this->disconnectAmeise();
            }
        }
    }

    public function fetchUserByIdOrName($data)
    {
        try {
            $result = $this->getAccessToken();
            if ($result && ($resultArray = json_decode($result, true)) && isset($resultArray['error'])) {
                return $resultArray;
            }
            // Make an API request using the access token
            $client = new Client();
            // Log before sending the request
            $this->amesieLogStatus && \Helper::log('fetch_user_id_name', 'Sending a user search request by id and name with access token: ' . $this->access_token);
            $response = $client->get($this->base_url . $this->ma . '/kunden/_search?q=' . $data, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                ],
            ]);
            // Log after sending the request
            $this->amesieLogStatus && \Helper::log('fetch_user_id_name', 'User search request by id and name response status is: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getBody(), true);
            } elseif ($response->getStatusCode() === 401) {
                $this->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->amesieLogStatus && \Helper::log('fetch_user_id_name', 'User search request failed with status code: ' . $response->getStatusCode());
                $this->amesieLogStatus && \Helper::log('fetch_user_id_name', 'Error response: ' . json_encode($errorResponse));
            }
            $this->amesieLogStatus && \Helper::log('fetch_user_id_name', 'User search request by id and name has been completed.');
            return $responseData;
        } catch (Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'fetch_user_id_name');
            if ($e->getCode() === 401) {
                $this->disconnectAmeise();
            }
        }
    }

    public function fetchUserDetail($id, $endPoints)
    {
        try {
            $result = $this->getAccessToken();
            if ($result && ($resultArray = json_decode($result, true)) && isset($resultArray['error'])) {
                return $resultArray;
            }
            // Make an API request using the access token
            $client = new Client();
            $this->amesieLogStatus && \Helper::log('user_end_points', 'User end points request with access token: ' . $this->access_token);
            $response = $client->get($this->base_url . $this->ma . '/kunden/' . $id . '/' . $endPoints, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                ],
            ]);
            $this->amesieLogStatus && \Helper::log('user_end_points', 'User end points request response status: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getBody(), true);
            } elseif ($response->getStatusCode() === 401) {
                $this->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->amesieLogStatus && \Helper::log('user_end_points', 'User end points request failed with status code: ' . $response->getStatusCode());
                $this->amesieLogStatus && \Helper::log('user_end_points', 'Error response: ' . json_encode($errorResponse));
            }
            $this->amesieLogStatus && \Helper::log('user_end_points', 'User end points request has been completed.');
            return $responseData;
        } catch (Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'user_end_points');
            if ($e->getCode() === 401) {
                $this->disconnectAmeise();
            }
        }
    }

    public function fetchUserByEamil($email)
    {
        try {
            $result = $this->getAccessToken();
            if ($result && ($resultArray = json_decode($result, true)) && isset($resultArray['error'])) {
                return $resultArray;
            }
            // Make an API request using the access token
            $client = new Client();
            $this->amesieLogStatus && \Helper::log('fetch_user_email', 'Fetch user by email request with access token: ' . $this->access_token);
            $body = json_encode([
                'mail' => $email,
            ]);
            $response = $client->get($this->base_url . $this->ma . '/kunden/_search', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                ],
                'body' => $body
            ]);
            $this->amesieLogStatus && \Helper::log('fetch_user_email', 'fetch user by email request response status: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getBody(), true);
            } elseif ($response->getStatusCode() === 401) {
                $this->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->amesieLogStatus && \Helper::log('fetch_user_email', 'Fetch user by email request failed with status code: ' . $response->getStatusCode());
                $this->amesieLogStatus && \Helper::log('fetch_user_email', 'Error response: ' . json_encode($errorResponse));
            }
            $this->amesieLogStatus && \Helper::log('fetch_user_email', 'fetch user by email has been completed.');
            return $responseData;
        } catch (Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'fetch_user_email');
            if ($e->getCode() === 401) {
                $this->disconnectAmeise();
            }
        }
    }

    public function getContracts($customerId)
    {
        try {
            $result = $this->getAccessToken();
            if ($result && ($resultArray = json_decode($result, true)) && isset($resultArray['error'])) {
                return $resultArray;
            }
            $client = new Client();
            $this->amesieLogStatus && \Helper::log('get_contracts', 'get contract request with access token: ' . $this->access_token);
            $response = $client->get($this->base_url . $this->ma . '/kunden/' . $customerId . '/vertraege', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                ],
            ]);
            $this->amesieLogStatus && \Helper::log('get_contracts', 'get contracts request response status is: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getBody(), true);
            } elseif ($response->getStatusCode() === 401) {
                $this->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->amesieLogStatus && \Helper::log('get_contracts', 'get contract request failed with status code: ' . $response->getStatusCode());
                $this->amesieLogStatus && \Helper::log('get_contracts', 'Error response: ' . json_encode($errorResponse));
            }
            $this->amesieLogStatus && \Helper::log('get_contracts', 'Get contract request has been completed.');
            return $responseData;
        } catch (Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'get_contracts');
            if ($e->getCode() === 401) {
                $this->disconnectAmeise();
            }
            return ['error' => 'redirect' ,'url' => $this->getAuthURl()];
        }
    }

    public function getContactEndPoints($end_points)
    {
        try {
            $result = $this->getAccessToken();
            if ($result && ($resultArray = json_decode($result, true)) && isset($resultArray['error'])) {
                return $resultArray;
            }
            $client = new Client();
            $this->amesieLogStatus && \Helper::log('contracts_end_points', 'get contract end points request with access token: ' . $this->access_token);
            $response = $client->get($this->base_url . $end_points, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                ],
            ]);
            $this->amesieLogStatus && \Helper::log('contracts_end_points', 'get contracts end points request response status is: ' . $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getBody(), true);
            } elseif ($response->getStatusCode() === 401) {
                $this->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->amesieLogStatus && \Helper::log('contracts_end_points', 'get contract end points request failed with status code: ' . $response->getStatusCode());
                $this->amesieLogStatus && \Helper::log('contracts_end_points', 'Error response: ' . json_encode($errorResponse));
            }
            $this->amesieLogStatus && \Helper::log('contracts_end_points', 'get contract end points  request has been completed.');

            return $responseData;
        } catch (Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'conversation_archive');
            if ($e->getCode() === 401) {
                $this->disconnectAmeise();
            }
        }
    }

    public function archiveConversation($data)
    {
        $result = $this->getAccessToken();
        if ($result && ($resultArray = json_decode($result, true)) && isset($resultArray['error'])) {
            return $resultArray;
        }
        $client = new Client();
        $this->amesieLogStatus && \Helper::log('conversation_archive', 'archive conversation request called with access token: ' . $this->access_token);
        $headers = [
            'X-Dio-Betreff' =>  $data['subject'],
            'x-dio-metadaten' =>  json_encode($data['x-dio-metadaten']),
            'X-Dio-Typ' => $data['type'],
            'Content-Type' => $data['Content-Type'] ??  'text/plain; charset="utf-8"',
            'X-Dio-Zuordnungen' =>  json_encode($data['X-Dio-Zuordnungen']),
            'X-Dio-Datum' =>  $data['X-Dio-Datum'],
            'Authorization' => 'Bearer ' . $this->access_token,
        ];
        try {
            $response = $client->request('POST', $this->base_url . $this->ma . '/archiveintraege', [
                'headers' => $headers,
                'body' => $data['body'],
            ]);
            $this->amesieLogStatus && \Helper::log('conversation_archive', 'archive conversation request response status is: ' . $response->getStatusCode());
            // Process the response data here
            if ($response->getStatusCode() === 200) {
                $responseData = $response->getBody();
                return $responseData;
            } elseif ($response->getStatusCode() === 401) {
                $this->disconnectAmeise();
            } else {
                $errorResponse = json_decode($response->getBody(), true);
                $this->amesieLogStatus && \Helper::log('conversation_archive', 'archive conversation request failed with status code: ' . $response->getStatusCode());
                $this->amesieLogStatus && \Helper::log('conversation_archive', 'Error response: ' . json_encode($errorResponse));
            }
        } catch (Exception $e) {
            $this->amesieLogStatus && \Helper::logException($e, 'conversation_archive');
            if ($e->getCode() === 401) {
                $this->disconnectAmeise();
            }
        }
        return false;
    }

    public function getAuthURl()
    {
        return $this->url . '/oauth2/auth?response_type=code&client_id=' . $this->clientId . '&redirect_uri=' . $this->redirectUrl . '&scope=' . $this->scope . '&state=' . config('ameisemodule.ameise_state');
    }

    public function createConversationData($conversation, $crm_user_id, $contracts, $divisions, $thread, $user = null)
    {
        $user = $user ?? auth()->user();
        $userTimezone = $user->timezone;
        $x_dio_metadaten = [];
        $metaData = [
            'An' => !empty($thread->to) ? json_decode($thread->to) : null,
            'Von' => !empty($thread->from) ? $thread->from : ($conversation->mailbox_id ? $conversation->mailbox->email : null),
            'CC' =>   !empty($thread->cc) ? json_decode($thread->cc) : null,
            'BCC' =>    !empty($thread->bcc) ? json_decode($thread->bcc) : null,
        ];
        foreach ($metaData as $key => $value) {
            $text = is_array($value) ? implode(', ', $value) : $value;
            $x_dio_metadaten[] = ['Value' => $key, 'Text' => $text];
        }

        return [
            'type' =>  ($conversation->type == Conversation::TYPE_EMAIL) ? 'email' : 'telefon',
            'x-dio-metadaten' => $x_dio_metadaten,
            'subject' => $conversation->subject,
            'body' => html_entity_decode(strip_tags(str_replace(['<li>', '</li>', '<br>'], ["\n- ", "", "\n"], $thread->body))),
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Dio-Datum' => Carbon::parse($thread->created_at)->setTimezone($userTimezone)->format('Y-m-d\TH:i:s'),
            'X-Dio-Zuordnungen' => array_merge(
                [['Typ' => 'kunde', 'Id' => $crm_user_id]],
                !is_null($contracts) ? array_map(fn($contract) => ['Typ' => 'vertrag', 'Id' => $contract['id']], $contracts) : [],
                !is_null($divisions) ? array_map(fn($division) => ['Typ' => 'sparte', 'Id' => $division['id']], $divisions) : []
            ),
        ];
    }

    public function archiveConversationWithAttachments($thread, $conversation_data, $user = null)
    {
        $allAttachments = $thread->attachments;
        $user = $user ?? auth()->user();
        $userTimezone = $user->timezone;
        if ($allAttachments->count() > 0) {
            foreach ($allAttachments as $attachment) {
                $attachmentData = [
                    'type' => 'dokument',
                    'x-dio-metadaten' => $conversation_data['x-dio-metadaten'],
                    'subject' => $attachment['file_name'],
                    'body' => file_get_contents(storage_path("app/attachment/{$attachment['file_dir']}{$attachment['file_name']}")),
                    'Content-Type' => 'application/pdf; name="freescout.pdf"',
                    'X-Dio-Zuordnungen' => $conversation_data['X-Dio-Zuordnungen'],
                    'X-Dio-Datum' => Carbon::parse($thread->created_at)->setTimezone($userTimezone)->format('Y-m-d\TH:i:s')
                ];
                $this->archiveConversation($attachmentData);
            }
        }
    }

    public function archiveConversationData($conversation, $thread = null, $user = null) {
        $thread =  $thread ?? $conversation->getLastThread();
        $user = $user ?? auth()->user();
        if($thread->type != Thread::TYPE_NOTE){
            $crmArchives = CrmArchive::where('conversation_id', $conversation->id)->get();
            if (count($crmArchives) > 0) {
                foreach ($crmArchives as $crmArchive) {
                    $isArchiveThread = CrmArchiveThread::where('crm_archive_id', $crmArchive->id)->where('thread_id',$thread->id)->first();
                    if(!$isArchiveThread){
                        $contracts = !empty($crmArchive->contracts) ? json_decode($crmArchive->contracts, true) : [];
                        $divisions = !empty($crmArchive->divisions) ? json_decode($crmArchive->divisions, true) : [];
                        $conversation_data = $this->createConversationData($conversation, $crmArchive->crm_user_id, $contracts, $divisions, $thread, $user);
                        if($this->archiveConversation($conversation_data)) {
                            $this->archiveConversationWithAttachments($thread, $conversation_data, $user);
                            CrmArchiveThread::create(['crm_archive_id' => $crmArchive->id,'thread_id' => $thread->id,'conversation_id'=> $conversation->id ]);
                        }
                    }
                }
            } else {
                $response = $this->fetchUserByEamil($conversation->customer_email);
                if (count($response) == 1) {
                    $crm_user_id = $response[0]['Id'];
                    $conversation_data  = $this->createConversationData($conversation, $crm_user_id, [], [], $thread, $user);
                    if($this->archiveConversation($conversation_data)) {
                        $this->archiveConversationWithAttachments($thread, $conversation_data, $user);
                        $crm_archive = CrmArchive::firstOrNew(['conversation_id' => $conversation->id, 'crm_user_id' => $crm_user_id,'archived_by' => $user->id]);
                        $crm_archive->crm_user = json_encode(['id' => $crm_user_id, 'text' => $response[0]['Text']]);
                        $crm_archive->contracts = null;
                        $crm_archive->divisions = null;
                        $crm_archive->save();
                        CrmArchiveThread::create(['crm_archive_id' => $crm_archive->id,'thread_id' => $thread->id,'conversation_id'=> $conversation->id ]);
                    }
                }
            }
        }

    }

    public function getCrmUsers($inputs, $result = []) {
        $response = $this->fetchUserByIdOrName($inputs['search']);
        if (isset($response['error']) && isset($response['url'])) {
            return response()->json(['error' => 'Redirect', 'url' => $response['url']]);
        }
        $crmUsers = [];
        foreach($response as $data) {
            $emails = $phone =  [];
            $contactDetails = $this->fetchUserDetail($data['Id'], 'kontaktdaten');
            foreach ($contactDetails as $item) {
                if ($item["Typ"] === "email") {
                    $emails[] = $item["Value"];
                } elseif($item['Typ'] == 'telefon') {
                    $phone [] = $item['Value'];
                }
            }
            $crmUsers[] = array(
                'id' => $data['Id'],
                'text' => $data['Text'],
                'id_name' => $data['Person']['Vorname'] . " " . $data['Person']['Nachname'] . "(" . $data['Id'] . ")",
                'first_name' => $data['Person']['Vorname'],
                'last_name'  => $data['Person']['Nachname'],
                'address'    => $data['Hauptwohnsitz']['Strasse'],
                'zip'        => $data['Hauptwohnsitz']['Postleitzahl'],
                'city'       => $data['Hauptwohnsitz']['Ort'],
                'country'    => $data['Hauptwohnsitz']['Land'],
                'emails'     => $emails,
                'phones'     => $phone,
            );
        }
        $result['crmUsers'] = $crmUsers;
        return response()->json($result);
    }

    public function getFSUsers($inputs) {
        $response = [];
        $q = $inputs['search'];
        $customers_query = Customer::select(['customers.id', 'first_name', 'last_name', 'emails.email'])->join('emails', 'customers.id', '=', 'emails.customer_id');
        $customers_query->where('emails.email', 'like', '%'.$q.'%');
        $customers_query->orWhere('first_name', 'like', '%'.$q.'%')
            ->orWhere('last_name', 'like', '%'.$q.'%');
        $customers = $customers_query->paginate(20);
        foreach ($customers as $customer) {
            $id = '';
            $text = $customer->getNameAndEmail();
            $id = $customer->email;
             
            $response['fsUsers'][] = [
                'id'   => $id,
                'text' => $text,
            ];
        }
        return $response;
    }
}

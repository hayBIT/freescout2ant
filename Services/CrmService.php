<?php

namespace Modules\AmeiseModule\Services;

class CrmService
{
    protected $tokenService;
    protected $apiClient;
    protected $archiver;

    public function __construct($code = '', $userId = '')
    {
        $this->tokenService = new TokenService($code, $userId);
        $this->apiClient = new CrmApiClient($this->tokenService);
        $this->archiver = new ConversationArchiver($this->apiClient);
    }

    public function getAuthURl()
    {
        return $this->tokenService->getAuthUrl();
    }

    public function getAccessToken()
    {
        return $this->tokenService->getAccessToken();
    }

    public function disconnectAmeise()
    {
        return $this->tokenService->disconnectAmeise();
    }

    public function fetchUserByIdOrName($data)
    {
        return $this->apiClient->fetchUserByIdOrName($data);
    }

    public function fetchUserDetail($id, $endPoints)
    {
        return $this->apiClient->fetchUserDetail($id, $endPoints);
    }

    public function fetchUserByEmail($email)
    {
        return $this->apiClient->fetchUserByEmail($email);
    }

    public function getContracts($customerId)
    {
        return $this->apiClient->getContracts($customerId);
    }

    public function getContactEndPoints($end_points)
    {
        return $this->apiClient->getContactEndPoints($end_points);
    }

    public function archiveConversation($data)
    {
        return $this->apiClient->archiveConversation($data);
    }

    public function createConversationData($conversation, $crm_user_id, $contracts, $divisions, $thread, $user = null)
    {
        return $this->archiver->createConversationData($conversation, $crm_user_id, $contracts, $divisions, $thread, $user);
    }

    public function archiveConversationWithAttachments($thread, $conversation_data, $user = null)
    {
        return $this->archiver->archiveConversationWithAttachments($thread, $conversation_data, $user);
    }

    public function archiveConversationData($conversation, $thread = null, $user = null)
    {
        return $this->archiver->archiveConversationData($conversation, $thread, $user);
    }

    public function getCrmUsers($inputs, $result = [])
    {
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

    public function getFSUsers($inputs)
    {
        $response = [];
        $q = $inputs['search'];
        $customers_query = \App\Customer::select(['customers.id', 'first_name', 'last_name', 'emails.email'])->join('emails', 'customers.id', '=', 'emails.customer_id');
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

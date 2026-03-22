<?php

namespace Modules\AmeiseModule\Services;

use App\Conversation;
use Modules\AmeiseModule\Entities\CrmArchive;
use Modules\AmeiseModule\Entities\CrmArchiveThread;

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

    /**
     * Search CRM users by name/ID and enrich with contact details.
     * Returns data array (no HTTP response).
     */
    public function getCrmUsers($inputs, $result = [])
    {
        $response = $this->fetchUserByIdOrName($inputs['search']);
        if (isset($response['error']) && isset($response['url'])) {
            return ['error' => 'Redirect', 'url' => $response['url']];
        }
        $crmUsers = [];
        foreach ($response as $data) {
            $emails = $phone = [];
            $contactDetails = $this->fetchUserDetail($data['Id'], 'kontaktdaten');
            foreach ($contactDetails as $item) {
                if ($item["Typ"] === "email") {
                    $emails[] = $item["Value"];
                } elseif ($item['Typ'] == 'telefon') {
                    $phone[] = $item['Value'];
                }
            }
            $crmUsers[] = [
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
            ];
        }
        $result['crmUsers'] = $crmUsers;
        return $result;
    }

    /**
     * Search FreeScout customers by name/email.
     * Returns data array (no HTTP response).
     */
    public function getFSUsers($inputs)
    {
        $response = [];
        $q = $inputs['search'];
        $customers_query = \App\Customer::select(['customers.id', 'first_name', 'last_name', 'emails.email'])
            ->join('emails', 'customers.id', '=', 'emails.customer_id');
        $customers_query->where('emails.email', 'like', '%' . $q . '%');
        $customers_query->orWhere('first_name', 'like', '%' . $q . '%')
            ->orWhere('last_name', 'like', '%' . $q . '%');
        $customers = $customers_query->paginate(20);
        foreach ($customers as $customer) {
            $response['fsUsers'][] = [
                'id'   => $customer->email,
                'text' => $customer->getNameAndEmail(),
            ];
        }
        return $response;
    }

    /**
     * Fetch contracts for a client, enriched with division/status labels.
     */
    public function getContractsWithDetails($clientId)
    {
        $response = $this->apiClient->getContracts($clientId);
        if (isset($response['error']) && isset($response['url'])) {
            return ['error' => 'Redirect', 'url' => $response['url']];
        }
        $divisionResponse = $this->apiClient->getContactEndPoints('sparten');
        $statusResponse = $this->apiClient->getContactEndPoints('vertragsstatus');
        $groupedData = collect($response)->groupBy('Status')->map(function ($group) use ($divisionResponse, $statusResponse) {
            return $group->map(function ($items) use ($divisionResponse, $statusResponse) {
                $divisionKey = array_search($items['Sparte'], array_column($divisionResponse, 'Value'));
                $statusKey = array_search($items['Status'], array_column($statusResponse, 'Value'));
                $divisionText = ($divisionKey !== false) ? $divisionResponse[$divisionKey]['Text'] : null;
                $statusText = ($statusKey !== false) ? $statusResponse[$statusKey]['Text'] : null;
                return [
                    'id' => $items['Id'],
                    'Risiko' => $items['Risiko'],
                    'Versicherungsscheinnummer' => $items['Versicherungsscheinnummer'],
                    'Sparte' => $divisionText,
                    'key' => $statusText,
                ];
            });
        });
        return ['contracts' => $groupedData, 'divisions' => $divisionResponse];
    }

    /**
     * Archive a conversation from an AJAX request.
     * Handles CrmArchive creation/update and thread archiving.
     */
    public function archiveConversationFromRequest($inputs, $userId)
    {
        $crm_archive = CrmArchive::where([
            'conversation_id' => $inputs['conversation_id'],
            'crm_user_id' => $inputs['customer_id']
        ])->first();

        if (!$crm_archive) {
            $crm_archive = new CrmArchive();
            $crm_archive->crm_user_id = $inputs['customer_id'];
            $crm_archive->conversation_id = $inputs['conversation_id'];
            $crm_archive->archived_by = $userId;
        }
        $crm_archive->crm_user = $inputs['crm_user_data'];
        $crm_archive->contracts = $inputs['contracts'];
        $crm_archive->divisions = $inputs['divisions_data'];
        $crm_archive->save();

        $conversation = Conversation::with('threads.all_attachments')->find($inputs['conversation_id']);
        $contracts = json_decode($inputs['contracts'], true);
        $divisions = json_decode($inputs['divisions_data'], true);

        foreach ($conversation->threads as $thread) {
            $isArchiveThread = CrmArchiveThread::where('crm_archive_id', $crm_archive->id)
                ->where('thread_id', $thread->id)
                ->first();

            if (!$isArchiveThread && $this->archiver->shouldArchiveThread($conversation, $thread)) {
                $conversation_data = $this->archiver->createConversationData(
                    $conversation, $inputs['customer_id'], $contracts, $divisions, $thread
                );
                $scanOnly = $this->archiver->isScanOnly($conversation);
                $archived = $scanOnly ? true : $this->apiClient->archiveConversation($conversation_data);

                if ($archived) {
                    $this->archiver->archiveConversationWithAttachments($thread, $conversation_data);
                    CrmArchiveThread::create([
                        'crm_archive_id' => $crm_archive->id,
                        'thread_id' => $thread->id,
                        'conversation_id' => $conversation->id,
                    ]);
                }
            }
        }
    }

    /**
     * Get archived contracts for a conversation (for view rendering).
     */
    public function getArchivedContracts($conversationId)
    {
        if (!Conversation::find($conversationId)) {
            return null;
        }
        return CrmArchive::where('conversation_id', $conversationId)
            ->orderBy('id', 'DESC')
            ->get();
    }
}

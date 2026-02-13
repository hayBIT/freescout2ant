<?php

namespace Modules\AmeiseModule\Http\Controllers;

use App\Conversation;
use App\Thread;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\AmeiseModule\Services\TokenService;
use Modules\AmeiseModule\Services\CrmApiClient;
use Modules\AmeiseModule\Services\ConversationArchiver;
use Modules\AmeiseModule\Entities\CrmArchive;
use Modules\AmeiseModule\Entities\CrmArchiveThread;

class AmeiseController extends Controller
{
    protected $tokenService;
    protected $apiClient;
    protected $archiver;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->tokenService = $this->tokenService ?? new TokenService('', auth()->user()->id);
            $this->apiClient = new CrmApiClient($this->tokenService);
            $this->archiver = new ConversationArchiver($this->apiClient);
            return $next($request);
        });

    }

    public function refreshToken()
    {
        $this->tokenService = $this->tokenService ?? new TokenService('', auth()->user()->id);
        $this->tokenService->getAccessToken();
        return response()->json(['status' => 'ok']);
    }
    /**
     *  @return Response Crm ajax controller.
     */
    public function ajax(Request $request)
    {
        $inputs = $request->all();
        switch ($request->action) {
            case 'crm_users_search':
                $results = [];
                if(!empty($inputs['new_conversation'])) {
                    $results = $this->getFSUsers($inputs);
                }
                return $this->getCrmUsers($inputs, $results);
                break;

            case 'get_contract':
                $response = $this->apiClient->getContracts($request->input('client_id'));
                if (isset($response['error']) && isset($response['url'])) {
                    return response()->json(['error' => 'Redirect', 'url' => $response['url']]);
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
                return response()->json(['contracts' => $groupedData, 'divisions' => $divisionResponse]);
                break;
            case 'crm_conversation_archive':
                $crm_archive = CrmArchive::where(
                    ['conversation_id' => $inputs['conversation_id'],
                    'crm_user_id' => $inputs['customer_id']
                    ])->first();
                if(!$crm_archive) {
                    $crm_archive = new CrmArchive();
                    $crm_archive->crm_user_id = $inputs['customer_id'];
                    $crm_archive->conversation_id = $inputs['conversation_id'];
                    $crm_archive->archived_by = auth()->user()->id;
                }
                $crm_archive->crm_user = $inputs['crm_user_data'];
                $crm_archive->contracts = $inputs['contracts'];
                $crm_archive->divisions = $inputs['divisions_data'];
                $crm_archive->save();
                $conversation = Conversation::with('threads.all_attachments')->find($inputs['conversation_id']);
                foreach($conversation->threads as $thread) {
                    $isArchiveThread = CrmArchiveThread::where('crm_archive_id', $crm_archive->id)->where('thread_id',$thread->id)->first();
                    if(!$isArchiveThread){
                        if ($this->archiver->shouldArchiveThread($conversation, $thread)) {
                            $crm_user_id = $inputs['customer_id'];
                            $contracts = json_decode($inputs['contracts'], true);
                            $divisions = json_decode($inputs['divisions_data'], true);
                            $conversation_data = $this->archiver->createConversationData($conversation, $crm_user_id, $contracts, $divisions, $thread);
                            if($this->apiClient->archiveConversation($conversation_data)) {
                                $this->archiver->archiveConversationWithAttachments($thread, $conversation_data);
                                CrmArchiveThread::create(['crm_archive_id' => $crm_archive->id,'thread_id' => $thread->id,'conversation_id'=> $conversation->id ]);
                            }
                        }
                    }
                }
                return response()->json(['status' => true]);
                break;

        }

    }

    public function getContracts($id)
    {
        if(!Conversation::find($id)) {
            return '';
        }
        $archives = CrmArchive::where('conversation_id', $id)->orderBy('id', 'DESC')->get();
        if(!$archives) {
            return false;
        }
        return view('ameise::partials.contracts', [
            'archives' => $archives,
        ])->render();

    }

    private function getCrmUsers($inputs, $result = [])
    {
        $response = $this->apiClient->fetchUserByIdOrName($inputs['search']);
        if (isset($response['error']) && isset($response['url'])) {
            return response()->json(['error' => 'Redirect', 'url' => $response['url']]);
        }
        $crmUsers = [];
        foreach($response as $data) {
            $emails = $phone =  [];
            $contactDetails = $this->apiClient->fetchUserDetail($data['Id'], 'kontaktdaten');
            foreach ($contactDetails as $item) {
                if ($item["Typ"] === "email") {
                    $emails[] = $item["Value"];
                } elseif($item['Typ'] == 'telefon') {
                    $phone [] = $item['Value'];
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
        return response()->json($result);
    }

    private function getFSUsers($inputs)
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

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
use Modules\AmeiseModule\Services\CustomerMatcher;
use Modules\AmeiseModule\Entities\CrmArchive;

class AmeiseController extends Controller
{
    protected $tokenService;
    protected $apiClient;
    protected $archiver;
    protected $customerMatcher;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->tokenService = $this->tokenService ?? new TokenService('', auth()->user()->id);
            $this->apiClient = new CrmApiClient($this->tokenService);
            $this->archiver = new ConversationArchiver($this->apiClient);
            $this->customerMatcher = new CustomerMatcher($this->apiClient);
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
                $this->logCorrectedAutoAssignment($inputs['conversation_id'], $inputs['customer_id']);
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
                // Vom Nutzer vorgenommen bzw. bestätigt – kein Prüfhinweis nötig.
                $crm_archive->auto_assigned = false;
                $crm_archive->confirmed_at = now();
                $crm_archive->save();
                $conversation = Conversation::with('threads.all_attachments')->find($inputs['conversation_id']);
                $allArchived = $this->archiver->archiveThreadsForArchive($conversation, $crm_archive);
                return response()->json(['status' => $allArchived]);
                break;

            case 'crm_confirm_assignment':
                $crm_archive = CrmArchive::where('id', $inputs['archive_id'] ?? 0)
                    ->where('conversation_id', $inputs['conversation_id'] ?? 0)
                    ->first();
                if (!$crm_archive) {
                    return response()->json(['status' => false]);
                }
                $crm_archive->confirmed_at = now();
                $crm_archive->save();
                return response()->json(['status' => true]);
                break;

        }

    }

    /**
     * Wird eine automatische Zuordnung durch eine andere überschrieben, war die
     * Automatik daneben. Das ist die einzige Datenquelle für ihre Trefferqualität –
     * und ein Hinweis darauf, dass in Ameise ein falscher Eintrag steht, den das
     * Modul nicht entfernen kann.
     */
    private function logCorrectedAutoAssignment($conversationId, $crmUserId)
    {
        $wrongAssignments = CrmArchive::where('conversation_id', $conversationId)
            ->where('auto_assigned', true)
            ->whereNull('confirmed_at')
            ->where('crm_user_id', '!=', $crmUserId)
            ->get();

        foreach ($wrongAssignments as $wrongAssignment) {
            \Helper::log(
                'ameise_auto_assign',
                'Automatische Zuordnung korrigiert: Konversation ' . $conversationId
                . ' war Kunde ' . $wrongAssignment->crm_user_id
                . ' (Quelle: ' . $wrongAssignment->match_source . '), Nutzer wählte Kunde ' . $crmUserId
                . '. Der Archiveintrag beim falschen Kunden bleibt in Ameise bestehen.'
            );
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
        $response = [];
        if (!empty($inputs['search_by_mail']) && !empty($inputs['conversation_id'])) {
            $conversation = Conversation::with('threads')->find($inputs['conversation_id']);
            if ($conversation) {
                $match = $this->customerMatcher->match($conversation);
                if (!empty($match['redirect'])) {
                    return response()->json(['error' => 'Redirect', 'url' => $match['redirect']]);
                }
                $response = $match['candidates'];
            }
        }

        if (empty($response)) {
            $search = trim($inputs['search'] ?? '');
            if ($search === '') {
                $result['crmUsers'] = [];
                return response()->json($result);
            }
            $response = $this->apiClient->fetchUserByIdOrName($search);
        }

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

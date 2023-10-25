<?php

namespace Modules\AmeiseModule\Http\Controllers;

use App\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\AmeiseModule\Services\CrmService;
use Modules\AmeiseModule\Entities\CrmArchive;

class CrmController extends Controller
{
    protected $crmService;
    public function __construct()
    {

        $this->middleware(function ($request, $next) {
            $this->crmService = $this->crmService ?? new CrmService('', auth()->user()->id);
            return $next($request);
        });

    }
    /**
     *  @return Response Crm ajax controller.
     */
    public function ajax(Request $request)
    {
        $inputs = $request->all();
        switch ($request->action) {
            case 'crm_users_search':
                $response = $this->crmService->fetchUserByIdOrName($inputs['search']);
                $crmUsers = $emails = $phone =  [];
                foreach($response as $data) {
                    $contactDetails = $this->crmService->fetchUserDetail($data['Id'],'kontaktdaten');
                    foreach ($contactDetails as $item) {
                        if ($item["Typ"] === "email") {
                            $emails[] = $item["Value"];
                        } elseif($item['Typ']== 'telefon'){
                            $phone [] = $item['Value'];
                        }
                    }
                    $crmUsers[] = array(
                        'id' => $data['Id'],
                        'text' => $data['Text'],
                        'id_name' => $data['Person']['Vorname']." ".$data['Person']['Nachname']."(".$data['Id'].")",
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
                return response()->json($crmUsers);
                break;

            case 'get_contract':
                $response = $this->crmService->getContracts($request->input('client_id'));
                $divisionResponse = $this->crmService->getContactEndPoints('sparten');
                $statusResponse = $this->crmService->getContactEndPoints('vertragsstatus');
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
                $conversation = Conversation::find($inputs['conversation_id']);
                $crm_user_id = $inputs['customer_id'];
                $crm_user = json_decode($inputs['crm_user_data'], true);
                $contracts = json_decode($inputs['contracts'], true);
                $divisions = json_decode($inputs['divisions_data'], true);
                $conversation_data = [];
                $crm_user_id = $crm_user['id'];
                if ($conversation && $conversation->type == 1) {
                    $conversation_data = [
                        'type' => 'email',
                        'x-dio-metadaten' => [],
                    ];
                    
                    if (!empty($conversation->cc)) {
                        $conversation_data['x-dio-metadaten'][] = ['Value' => 'cc', 'Text' => implode(', ', json_decode($conversation->cc))];
                    }

                    if (!empty($conversation->bcc)) {
                        $conversation_data['x-dio-metadaten'][] = ['Value' => 'bcc', 'Text' => implode(', ', json_decode($conversation->bcc))];
                    }
                } elseif($conversation && $conversation->type == 2) {
                    $conversation_data = [
                        'type' => 'telefon',
                        'x-dio-metadaten' => [],
                    ];
                }

                $conversation_data['subject'] = $conversation->subject;
                $conversation_data['body'] = $conversation->preview;
                $conversation_data['X-Dio-Zuordnungen']=
                [
                    ['Typ' => 'kunde', 'Id' => $crm_user_id],
                    ...array_map(fn ($contract) => ['Typ' => 'vertrag', 'Id' => $contract['id']], $contracts),
                    ...array_map(fn ($division) => ['Typ' => 'sparte', 'Id' => $division['id']], $divisions),
                ];
                $response = $this->crmService->archiveConversation($conversation_data);
                $crm_archive = CrmArchive::where(
                    ['conversation_id'=> $inputs['conversation_id'],
                    'crm_user_id'=> $inputs['customer_id']
                    ])->first();
                
                if(!$crm_archive) {
                    $crm_archive = new CrmArchive();
                    $crm_archive->crm_user_id = $inputs['customer_id'];
                    $crm_archive->conversation_id = $inputs['conversation_id'];
                }
                $crm_archive->crm_user = $inputs['crm_user_data'];
                $crm_archive->contracts = $inputs['contracts'];
                $crm_archive->divisions = $inputs['divisions_data'];
                $crm_archive->save();

                return response()->json(['status' => true]);
                break;

        }

    }

    public function getContracts($id) {
        if(!Conversation::find($id)) {
            return '';
        }
        $archives = CrmArchive::where('conversation_id', $id)->orderBy('id','DESC')->get();
        if(!$archives){
            return false;
        }
        return view('ameise::partials.contracts', [
            'archives' => $archives,
        ])->render();
        
    }
}

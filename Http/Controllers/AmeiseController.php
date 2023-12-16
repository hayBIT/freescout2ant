<?php

namespace Modules\AmeiseModule\Http\Controllers;

use App\Conversation;
use App\Thread;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\AmeiseModule\Services\CrmService;
use Modules\AmeiseModule\Entities\CrmArchive;
use Modules\AmeiseModule\Entities\CrmArchiveThread;

class AmeiseController extends Controller
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
                $results = [];
                if(!empty($inputs['new_conversation'])) {
                    $results = $this->crmService->getFSUsers($inputs);
                }
                return $this->crmService->getCrmUsers($inputs, $results);
                break;

            case 'get_contract':
                $response = $this->crmService->getContracts($request->input('client_id'));
                if (isset($response['error']) && isset($response['url'])) {
                    return response()->json(['error' => 'Redirect', 'url' => $response['url']]);
                }
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
                        if($thread->type != Thread::TYPE_NOTE) {
                            $crm_user_id = $inputs['customer_id'];
                            $contracts = json_decode($inputs['contracts'], true);
                            $divisions = json_decode($inputs['divisions_data'], true);
                            $conversation_data = $this->crmService->createConversationData($conversation, $crm_user_id, $contracts, $divisions, $thread);
                            $this->crmService->archiveConversation($conversation_data);
                            $this->crmService->archiveConversationWithAttachments($thread, $conversation_data);
                            CrmArchiveThread::create(['crm_archive_id' => $crm_archive->id,'thread_id' => $thread->id,'conversation_id'=> $conversation->id ]);
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
}

<?php

namespace Modules\AmeiseModule\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\AmeiseModule\Services\CrmService;

class AmeiseController extends Controller
{
    protected $crmService;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->crmService = new CrmService('', auth()->user()->id);
            return $next($request);
        });
    }

    public function refreshToken()
    {
        $crmService = new CrmService('', auth()->user()->id);
        $crmService->getAccessToken();
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
                if (!empty($inputs['new_conversation'])) {
                    $results = $this->crmService->getFSUsers($inputs);
                }
                $result = $this->crmService->getCrmUsers($inputs, $results);
                return response()->json($result);
                break;

            case 'get_contract':
                $result = $this->crmService->getContractsWithDetails($request->input('client_id'));
                return response()->json($result);
                break;

            case 'crm_conversation_archive':
                $this->crmService->archiveConversationFromRequest($inputs, auth()->user()->id);
                return response()->json(['status' => true]);
                break;
        }
    }

    public function getContracts($id)
    {
        $archives = $this->crmService->getArchivedContracts($id);
        if (!$archives) {
            return '';
        }
        return view('ameise::partials.contracts', [
            'archives' => $archives,
        ])->render();
    }
}

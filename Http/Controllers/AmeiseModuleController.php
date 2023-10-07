<?php

namespace Modules\AmeiseModule\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\AmeiseModule\Services\CrmService;

class AmeiseModuleController extends Controller
{
    protected $crmService;
    /**
     * Display a listing of the resource.
     * @return Response
     */

    public function auth(Request $request){
        if ($request->has('code')) {
            $this->crmService = $this->crmService ?? new CrmService($request->get('code'), auth()->user()->id);
            $this->crmService->getAccessToken();
        }
        if(session()->has('redirect_back')){
            $url = session()->get('redirect_back');
            session()->forget('redirect_back');
            return redirect($url);
        }
        return redirect('/');
    }
}

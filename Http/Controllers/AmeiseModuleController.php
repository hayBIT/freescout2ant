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

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->crmService = $this->crmService ?? new CrmService('', auth()->user()->id);
            return $next($request);
        });
    }
    public function index()
    {
        return view('ameisemodule::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return Response
     */
    public function create()
    {
        return view('ameisemodule::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param  Request $request
     * @return Response
     */
    public function store(Request $request)
    {
    }

    /**
     * Show the specified resource.
     * @return Response
     */
    public function show()
    {
        return view('ameisemodule::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @return Response
     */
    public function edit()
    {
        return view('ameisemodule::edit');
    }

    /**
     * Update the specified resource in storage.
     * @param  Request $request
     * @return Response
     */
    public function update(Request $request)
    {
    }

    /**
     * Remove the specified resource from storage.
     * @return Response
     */
    public function destroy()
    {
    }

    public function auth(Request $request){
        if ($request->has('code')) {
            $this->crmService = $this->crmService ?? new CrmService($request->get('code'), auth()->user()->id);
            $this->crmService->getAccessToken();
        }
        return redirect('/');
    }

    public function disconnectAmeise(){
        $status = $this->crmService->disconnectAmeise();
        if($status){
            return redirect()->back();
        }
    }
}

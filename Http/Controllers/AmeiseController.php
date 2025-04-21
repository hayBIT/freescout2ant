<?php

namespace Modules\AmeiseModule\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Modules\AmeiseModule\Services\CrmService;

class AmeiseController extends Controller
{
    public function __construct(
        protected CrmService $crm
    ) {}

    /**
     * Zentraler Ajax‑Endpunkt.
     *
     * Erwartet JSON mit { action: string, data: array }
     */
    public function ajax(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|string',
            'data'   => 'array',
        ]);

        return match ($validated['action']) {
            'createContact'   => $this->ok($this->crm->createContact($validated['data'])),
            'updateContact'   => $this->ok($this->crm->updateContact($validated['data']['id'], $validated['data'])),
            'createContract'  => $this->ok($this->crm->createContract($validated['data'])),
            'updateContract'  => $this->ok($this->crm->updateContract($validated['data']['id'], $validated['data'])),
            'uploadAttachment'=> $this->ok(
                $this->crm->uploadAttachment(
                    $validated['data']['name'],
                    $validated['data']['mime'],
                    $validated['data']['path'],
                )
            ),
            default => response()->json(['error' => 'Unbekannte Action'], 400),
        };
    }

    private function ok(mixed $payload): JsonResponse
    {
        return response()->json(['status' => 'ok', 'data' => $payload]);
    }
}

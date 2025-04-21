<?php

namespace Modules\AmeiseModule\Services;

use Illuminate\Support\Collection;
use Modules\AmeiseModule\Services\AmeiseApiClient;

class CrmService
{
    /**
     * API‑Client mit Auto‑Refresh.
     */
    public function __construct(
        protected AmeiseApiClient $api
    ) {}

    /* ---------------------------------------------------------------
     |  Kund:innen
     * ------------------------------------------------------------- */

    public function createContact(array $payload): array
    {
        $response = $this->api->request('POST', 'contacts', ['json' => $payload]);
        return $response->json();
    }

    public function updateContact(int $contactId, array $payload): array
    {
        $response = $this->api->request('PUT', "contacts/{$contactId}", ['json' => $payload]);
        return $response->json();
    }

    public function searchContacts(string $query): Collection
    {
        $response = $this->api->request('GET', 'contacts/search', ['query' => ['q' => $query]]);
        return collect($response->json('data'));
    }

    /* ---------------------------------------------------------------
     |  Verträge
     * ------------------------------------------------------------- */

    public function createContract(array $payload): array
    {
        $response = $this->api->request('POST', 'contracts', ['json' => $payload]);
        return $response->json();
    }

    public function updateContract(int $contractId, array $payload): array
    {
        $response = $this->api->request('PUT', "contracts/{$contractId}", ['json' => $payload]);
        return $response->json();
    }

    /* ---------------------------------------------------------------
     |  Anhänge
     * ------------------------------------------------------------- */

    public function uploadAttachment(string $name, string $mime, string $path): array
    {
        $response = $this->api->request('POST', 'attachments', [
            'multipart' => [
                [
                    'name'     => 'file',
                    'filename' => $name,
                    'Mime‑Type'=> $mime,
                    'contents' => fopen($path, 'r'),
                ],
            ],
        ]);

        return $response->json();
    }

    /* ---------------------------------------------------------------
     |  Archivierung (Beispiel)
     * ------------------------------------------------------------- */

    public function archiveConversation(int $conversationId, array $payload): void
    {
        $this->api->request('POST', "archive/conversations/{$conversationId}", ['json' => $payload]);
    }
}

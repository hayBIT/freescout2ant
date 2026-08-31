<?php

namespace Modules\AmeiseModule\Services;

use Carbon\Carbon;
use Modules\AmeiseModule\Entities\CrmArchiveEntry;

/**
 * Ändert Archiveinträge in der Ameise und hält den lokalen Spiegel nach.
 *
 * Jede Änderung läuft als Read-Modify-Write: der Eintrag wird frisch gelesen,
 * die Änderung darauf angewendet und der vollständige Zustand gesendet. Sonst
 * würden weggelassene Array-Felder Tags und Zuordnungen leeren.
 */
class ArchiveEntryEditor
{
    private $client;
    private $resolver;
    private $lastError;

    public function __construct(ArchiveApiClient $client)
    {
        $this->client = $client;
        $this->resolver = new ArchiveEntryResolver($client);
    }

    public function getLastError()
    {
        return $this->lastError ?: $this->client->getLastError();
    }

    /**
     * Holt die UUID nach, falls sie noch fehlt. Ein Aufruf je Konversation.
     */
    public function ensureResolved(CrmArchiveEntry $entry): bool
    {
        if ($entry->archive_entry_id) {
            return true;
        }

        return $this->resolver->resolve($entry) === CrmArchiveEntry::STATE_OK;
    }

    /**
     * Löst die offenen Einträge einer Konversation gemeinsam auf — der Resolver
     * hält das Zeitfenster im Cache, es bleibt also bei wenigen Aufrufen.
     */
    public function resolveConversation($conversationId): int
    {
        $open = CrmArchiveEntry::where('conversation_id', $conversationId)
            ->whereNull('archive_entry_id')
            ->whereIn('sync_state', [CrmArchiveEntry::STATE_PENDING, CrmArchiveEntry::STATE_UNMAPPED])
            ->get();

        $resolved = 0;
        foreach ($open as $entry) {
            if ($this->resolver->resolve($entry) === CrmArchiveEntry::STATE_OK) {
                $resolved++;
            }
        }

        return $resolved;
    }

    /**
     * Liest den Eintrag frisch aus der Ameise und gleicht den Spiegel an.
     */
    public function load(CrmArchiveEntry $entry)
    {
        if (!$this->ensureResolved($entry)) {
            $this->lastError = __('Dieser Archiveintrag ist in der Ameise nicht eindeutig auffindbar.');
            return null;
        }

        $remote = $this->client->getCustomerArchiveEntry($entry->customer_id, $entry->archive_entry_id);
        if ($remote === null) {
            if ($this->client->getLastStatusCode() === 404) {
                $this->mark($entry, CrmArchiveEntry::STATE_MISSING, __('Der Eintrag ist in der Ameise nicht mehr vorhanden.'));
            }
            return null;
        }

        $this->syncMirror($entry, $remote);

        return $remote;
    }

    /**
     * @param array $changes nur die geänderten Felder, z. B. ['contracts' => [12]]
     */
    public function update(CrmArchiveEntry $entry, array $changes): bool
    {
        $remote = $this->load($entry);
        if ($remote === null) {
            return false;
        }

        $payload = ArchiveEntryPayload::fromEntry($remote, $changes);

        if (!$this->client->updateArchiveEntry($entry->customer_id, $entry->archive_entry_id, $payload)) {
            $this->mark($entry, CrmArchiveEntry::STATE_CONFLICT, $this->client->getLastError());
            return false;
        }

        $this->syncMirror($entry, array_merge($remote, $this->remoteShapeOf($payload)));
        $entry->sync_state = CrmArchiveEntry::STATE_OK;
        $entry->last_error = null;
        $entry->save();

        return true;
    }

    /**
     * Löschen geht nur als Soft-Delete: DELETE beantwortet die API mit 403.
     */
    public function softDelete(CrmArchiveEntry $entry): bool
    {
        return $this->update($entry, ['isDeleted' => true]);
    }

    public function restore(CrmArchiveEntry $entry): bool
    {
        return $this->update($entry, ['isDeleted' => false]);
    }

    public function logs(CrmArchiveEntry $entry, $module = null)
    {
        if (!$this->ensureResolved($entry)) {
            return null;
        }

        return $this->client->getArchiveEntryLogs($entry->customer_id, $entry->archive_entry_id, $module, 1, 50);
    }

    /**
     * Setzt Verträge und Sparten auf mehreren Einträgen.
     *
     * @param string $mode replace|add
     * @return array{updated:int,failed:int,skipped:int,errors:array}
     */
    public function applyRelations($entries, array $contracts, array $contractLines, $mode = 'replace'): array
    {
        $result = ['updated' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($entries as $entry) {
            if (!$this->ensureResolved($entry)) {
                $result['skipped']++;
                continue;
            }

            $changes = $mode === 'add'
                ? [
                    'contracts' => $this->merge($entry->contracts, $contracts),
                    'contractLines' => $this->merge($entry->contract_lines, $contractLines),
                ]
                : [
                    'contracts' => array_values($contracts),
                    'contractLines' => array_values($contractLines),
                ];

            if ($this->update($entry, $changes)) {
                $result['updated']++;
                continue;
            }

            $result['failed']++;
            $result['errors'][] = mb_substr((string) $entry->subject, 0, 60) . ': ' . $this->getLastError();
        }

        return $result;
    }

    private function merge($existing, array $additional): array
    {
        $merged = array_merge((array) $existing, $additional);

        return array_values(array_unique($merged, SORT_REGULAR));
    }

    /**
     * Die Antwort der API nutzt andere Feldnamen als das Update — für den
     * Spiegel wird der gesendete Zustand in die Form der Antwort gebracht.
     */
    private function remoteShapeOf(array $payload): array
    {
        return [
            'type' => $payload['archiveType'] ?? null,
            'subject' => $payload['subject'] ?? null,
            'text' => $payload['text'] ?? null,
            'tags' => $payload['tags'] ?? [],
            'contracts' => array_map(function ($id) {
                return ['id' => $id];
            }, $payload['contracts'] ?? []),
            'contractLines' => array_map(function ($id) {
                return ['id' => $id];
            }, $payload['contractLines'] ?? []),
            'requiresReview' => $payload['requiresReview'] ?? false,
            'isPublic' => $payload['isPublic'] ?? false,
            'isDeleted' => $payload['isDeleted'] ?? false,
            'date' => $payload['date'] ?? null,
        ];
    }

    private function syncMirror(CrmArchiveEntry $entry, array $remote)
    {
        $entry->subject = $remote['subject'] ?? $entry->subject;
        $entry->entry_type = $remote['type'] ?? $entry->entry_type;
        $entry->is_public = $remote['isPublic'] ?? null;
        $entry->requires_review = $remote['requiresReview'] ?? null;
        $entry->is_deleted = $remote['isDeleted'] ?? null;
        $entry->tags = array_values((array) ($remote['tags'] ?? []));
        $entry->contracts = $this->ids($remote['contracts'] ?? []);
        $entry->contract_lines = $this->ids($remote['contractLines'] ?? []);
        $entry->remote_synced_at = Carbon::now();
        $entry->save();
    }

    private function ids($items): array
    {
        $ids = [];
        foreach ((array) $items as $item) {
            if (is_array($item) && isset($item['id'])) {
                $ids[] = $item['id'];
            } elseif (is_scalar($item)) {
                $ids[] = $item;
            }
        }

        return $ids;
    }

    private function mark(CrmArchiveEntry $entry, $state, $error)
    {
        $entry->sync_state = $state;
        $entry->last_error = $error;
        $entry->save();
        $this->lastError = $error;
    }
}

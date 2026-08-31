<?php

namespace Modules\AmeiseModule\Http\Controllers;

use App\Conversation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\AmeiseModule\Entities\CrmArchiveEntry;
use Modules\AmeiseModule\Services\ArchiveApiClient;
use Modules\AmeiseModule\Services\ArchiveEntryEditor;
use Modules\AmeiseModule\Services\CrmApiClient;
use Modules\AmeiseModule\Services\TokenService;

/**
 * Bearbeiten einzelner Archiveinträge aus der Konversation heraus.
 *
 * Gearbeitet wird mit dem Token des angemeldeten Benutzers, nicht mit dem des
 * ursprünglich Archivierenden — so protokolliert die Ameise den echten Autor.
 */
class ArchiveEntryController extends Controller
{
    protected $tokenService;
    protected $editor;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->tokenService = new TokenService('', auth()->user()->id);
            $this->editor = new ArchiveEntryEditor(new ArchiveApiClient($this->tokenService));

            return $next($request);
        });
    }

    public function ajax(Request $request)
    {
        if (!$this->isConnected()) {
            return response()->json([
                'status' => false,
                'message' => __('Bitte zuerst mit der Ameise verbinden.'),
            ]);
        }

        switch ($request->input('action')) {
            case 'entry_load':
                return $this->entryLoad($request);
            case 'entry_update':
                return $this->entryUpdate($request);
            case 'entry_logs':
                return $this->entryLogs($request);
            case 'bulk_relations':
                return $this->bulkRelations($request);
            case 'resolve_conversation':
                return $this->resolveConversation($request);
        }

        return response()->json(['status' => false, 'message' => __('Unbekannte Aktion.')]);
    }

    /**
     * Eintrag frisch aus der Ameise laden, dazu Verträge und Sparten des Kunden.
     */
    private function entryLoad(Request $request)
    {
        $entry = $this->findEntry($request->input('entry_id'));
        if (!$entry) {
            return response()->json(['status' => false, 'message' => __('Archiveintrag nicht gefunden.')]);
        }

        $remote = $this->editor->load($entry);
        if ($remote === null) {
            return response()->json([
                'status' => false,
                'message' => $this->editor->getLastError() ?: __('Der Archiveintrag konnte nicht geladen werden.'),
            ]);
        }

        return response()->json([
            'status' => true,
            'entry' => $this->present($entry, $remote),
            'relations' => $this->relationsFor($entry->customer_id),
            'tagSuggestions' => $this->tagSuggestions($entry->customer_id),
        ]);
    }

    private function entryUpdate(Request $request)
    {
        $entry = $this->findEntry($request->input('entry_id'));
        if (!$entry) {
            return response()->json(['status' => false, 'message' => __('Archiveintrag nicht gefunden.')]);
        }

        $changes = $this->changesFrom($request);

        if ($request->boolean('apply_to_conversation')
            && array_key_exists('contracts', $changes)
            && array_key_exists('contractLines', $changes)) {
            // Der geöffnete Eintrag bekommt seine eigenen Änderungen …
            if (!$this->editor->update($entry, $changes)) {
                return response()->json([
                    'status' => false,
                    'message' => $this->editor->getLastError() ?: __('Die Änderung wurde nicht gespeichert.'),
                ]);
            }

            // … die übrigen nur die Zuordnung. Betreff, Text und Datum bleiben
            // dort unangetastet.
            $others = CrmArchiveEntry::where('conversation_id', $entry->conversation_id)
                ->where('customer_id', $entry->customer_id)
                ->where('id', '!=', $entry->id)
                ->get();

            $result = $this->editor->applyRelations(
                $others,
                $changes['contracts'],
                $changes['contractLines'],
                'replace'
            );
            $result['updated']++;

            return response()->json([
                'status' => $result['failed'] === 0,
                'message' => $this->bulkMessage($result),
                'entry' => $this->present($entry->fresh()),
            ]);
        }

        if (!$this->editor->update($entry, $changes)) {
            return response()->json([
                'status' => false,
                'message' => $this->editor->getLastError() ?: __('Die Änderung wurde nicht gespeichert.'),
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => __('Archiveintrag aktualisiert.'),
            'entry' => $this->present($entry->fresh()),
        ]);
    }

    private function entryLogs(Request $request)
    {
        $entry = $this->findEntry($request->input('entry_id'));
        if (!$entry) {
            return response()->json(['status' => false, 'message' => __('Archiveintrag nicht gefunden.')]);
        }

        $module = $request->input('module');
        $logs = $this->editor->logs($entry, $module ?: null);

        if ($logs === null) {
            return response()->json([
                'status' => false,
                'message' => $this->editor->getLastError() ?: __('Der Verlauf konnte nicht geladen werden.'),
            ]);
        }

        return response()->json(['status' => true, 'items' => $logs['items'] ?? []]);
    }

    /**
     * Zuordnung für alle Einträge einer Konversation setzen.
     */
    private function bulkRelations(Request $request)
    {
        $conversation = Conversation::find($request->input('conversation_id'));
        if (!$conversation || !$this->mayEdit($conversation)) {
            return response()->json(['status' => false, 'message' => __('Konversation nicht gefunden.')]);
        }

        $entries = CrmArchiveEntry::where('conversation_id', $conversation->id)->get();
        if ($entries->isEmpty()) {
            return response()->json(['status' => false, 'message' => __('Zu dieser Konversation sind keine Archiveinträge bekannt.')]);
        }

        $result = $this->editor->applyRelations(
            $entries,
            $this->idsFrom($request->input('contracts')),
            $this->idsFrom($request->input('contract_lines')),
            $request->input('mode') === 'add' ? 'add' : 'replace'
        );

        return response()->json([
            'status' => $result['failed'] === 0,
            'message' => $this->bulkMessage($result),
            'errors' => $result['errors'],
        ]);
    }

    /**
     * Offene Einträge der Konversation auflösen — beim Öffnen der Seitenleiste.
     */
    private function resolveConversation(Request $request)
    {
        $conversation = Conversation::find($request->input('conversation_id'));
        if (!$conversation || !$this->mayEdit($conversation)) {
            return response()->json(['status' => false]);
        }

        return response()->json([
            'status' => true,
            'resolved' => $this->editor->resolveConversation($conversation->id),
        ]);
    }

    // --- Hilfsfunktionen ----------------------------------------------------

    private function changesFrom(Request $request): array
    {
        $changes = [];

        foreach (['subject' => 'subject', 'text' => 'text'] as $input => $field) {
            if ($request->has($input)) {
                $value = trim((string) $request->input($input));
                $changes[$field] = $value === '' ? null : $value;
            }
        }

        if ($request->filled('archive_type')) {
            $changes['archiveType'] = $request->input('archive_type');
        }
        if ($request->filled('date')) {
            $date = $this->parseDate($request->input('date'));
            if ($date !== null) {
                $changes['date'] = $date;
            }
        }
        if ($request->has('is_public')) {
            $changes['isPublic'] = $request->boolean('is_public');
        }
        if ($request->has('requires_review')) {
            $changes['requiresReview'] = $request->boolean('requires_review');
        }
        if ($request->has('is_deleted')) {
            $changes['isDeleted'] = $request->boolean('is_deleted');
        }
        if ($request->has('tags')) {
            $changes['tags'] = array_values(array_filter((array) $request->input('tags')));
        }
        if ($request->has('contracts')) {
            $changes['contracts'] = $this->idsFrom($request->input('contracts'));
        }
        if ($request->has('contract_lines')) {
            $changes['contractLines'] = $this->idsFrom($request->input('contract_lines'));
        }

        return $changes;
    }

    /**
     * Nimmt sowohl "2026-08-30 14:02" als auch "30.08.2026 14:02" entgegen und
     * sendet einen eindeutigen Zeitstempel mit Zeitzone.
     */
    private function parseDate($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // Das Ausrufezeichen setzt nicht genannte Bestandteile auf null,
        // sonst erbt ein reines Datum die aktuelle Uhrzeit.
        foreach (['!d.m.Y H:i', '!d.m.Y H:i:s', '!d.m.Y', '!Y-m-d H:i', '!Y-m-d H:i:s', '!Y-m-d'] as $format) {
            $date = \DateTime::createFromFormat($format, $value, new \DateTimeZone(config('app.timezone')));
            if ($date !== false) {
                return $date->format(\DateTime::ATOM);
            }
        }

        try {
            return \Carbon\Carbon::parse($value, config('app.timezone'))->toIso8601String();
        } catch (\Exception $e) {
            return null;
        }
    }

    private function idsFrom($value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true) ?: [];
        }

        $ids = [];
        foreach ((array) $value as $item) {
            if (is_array($item) && isset($item['id'])) {
                $ids[] = $item['id'];
            } elseif (is_scalar($item) && $item !== '') {
                $ids[] = $item;
            }
        }

        return array_values(array_unique($ids, SORT_REGULAR));
    }

    /**
     * Verträge und Sparten kommen weiterhin aus der Mitarbeiter-API — die
     * Archive-API kennt sie nicht.
     */
    private function relationsFor($customerId): array
    {
        $crmClient = new CrmApiClient($this->tokenService);
        $contracts = $crmClient->getContracts($customerId);
        $divisions = $crmClient->getContactEndPoints('sparten');

        if (isset($contracts['error'])) {
            return ['contracts' => [], 'contractLines' => []];
        }

        $divisionTexts = [];
        foreach ((array) $divisions as $division) {
            $divisionTexts[$division['Value']] = $division['Text'];
        }

        $items = [];
        foreach ((array) $contracts as $contract) {
            $items[] = [
                'id' => $contract['Id'],
                'text' => trim(($divisionTexts[$contract['Sparte']] ?? $contract['Sparte'])
                    . ' · ' . ($contract['Versicherungsscheinnummer'] ?: '—')
                    . ' · ' . ($contract['Risiko'] ?: '—')),
            ];
        }

        $lines = [];
        foreach ($divisionTexts as $value => $text) {
            $lines[] = ['id' => $value, 'text' => $text];
        }

        return ['contracts' => $items, 'contractLines' => $lines];
    }

    private function tagSuggestions($customerId): array
    {
        $client = new ArchiveApiClient($this->tokenService);

        return array_values(array_unique(array_merge(
            $client->getCustomerTags($customerId),
            $client->getContractsTags()
        )));
    }

    /**
     * Der lokale Spiegel für die Anzeige, ergänzt um Felder, die nur die
     * Antwort der Ameise enthält.
     */
    private function present(CrmArchiveEntry $entry, ?array $remote = null): array
    {
        $data = [
            'id' => $entry->id,
            'archive_entry_id' => $entry->archive_entry_id,
            'conversation_id' => $entry->conversation_id,
            'customer_id' => $entry->customer_id,
            'kind' => $entry->kind,
            'subject' => $entry->subject,
            'type' => $entry->entry_type,
            'date' => $entry->entry_date ? $entry->entry_date->format('Y-m-d H:i') : null,
            'is_public' => (bool) $entry->is_public,
            'requires_review' => (bool) $entry->requires_review,
            'is_deleted' => (bool) $entry->is_deleted,
            'tags' => (array) $entry->tags,
            'contracts' => (array) $entry->contracts,
            'contract_lines' => (array) $entry->contract_lines,
            'sync_state' => $entry->sync_state,
        ];

        if ($remote !== null) {
            $data['text'] = $remote['text'] ?? null;
            $data['files'] = $this->filesFrom($remote);
            // Der Typ lässt sich nicht ändern, solange Dateien am Eintrag hängen.
            $data['type_locked'] = ($remote['type'] ?? null) === 'email' && !empty($data['files']);
            $data['author'] = $remote['author']['displayName'] ?? null;
        }

        return $data;
    }

    private function filesFrom(array $remote): array
    {
        $files = $remote['files'] ?? [];
        if (isset($files['iterator'])) {
            $files = $files['iterator'];
        }

        $list = [];
        foreach ((array) $files as $file) {
            if (is_array($file) && isset($file['id'])) {
                $list[] = [
                    'id' => $file['id'],
                    'name' => $file['fileName'] ?? $file['subject'] ?? $file['id'],
                ];
            }
        }

        return $list;
    }

    private function bulkMessage(array $result): string
    {
        $parts = [__(':count Einträge aktualisiert.', ['count' => $result['updated']])];
        if ($result['failed'] > 0) {
            $parts[] = __(':count fehlgeschlagen.', ['count' => $result['failed']]);
        }
        if ($result['skipped'] > 0) {
            $parts[] = __(':count übersprungen, weil sie in der Ameise nicht eindeutig auffindbar sind.', ['count' => $result['skipped']]);
        }

        return implode(' ', $parts);
    }

    private function findEntry($id)
    {
        $entry = CrmArchiveEntry::find($id);
        if (!$entry) {
            return null;
        }

        $conversation = Conversation::find($entry->conversation_id);

        return $conversation && $this->mayEdit($conversation) ? $entry : null;
    }

    /**
     * Bearbeiten darf, wer die Konversation sehen darf.
     */
    private function mayEdit(Conversation $conversation): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        if (method_exists($user, 'can') && $user->can('view', $conversation)) {
            return true;
        }

        return method_exists($user, 'hasAccessToMailbox')
            ? $user->hasAccessToMailbox($conversation->mailbox_id)
            : false;
    }

    private function isConnected(): bool
    {
        return auth()->user() && file_exists(storage_path('user_' . auth()->user()->id . '_ant.txt'));
    }
}

<?php

namespace Modules\AmeiseModule\Services;

use Carbon\Carbon;
use Modules\AmeiseModule\Entities\CrmArchiveEntry;

/**
 * Hält fest, welcher Archiveintrag zu welchem Thread bzw. Anhang gehört.
 *
 * Läuft direkt nach einer erfolgreichen Archivierung, ruft dabei aber selbst
 * keine API auf: die Auflösung der UUID übernimmt später der
 * ArchiveEntryResolver. Scheitert das Festhalten, darf das die Archivierung
 * nicht beeinträchtigen — deshalb fängt store() alle Fehler ab.
 */
class ArchiveEntryRecorder
{
    /**
     * Die Mitarbeiter-API kennt deutsche Typbezeichnungen, die Archive-API englische.
     */
    private const TYPE_MAP = [
        'email' => 'email',
        'telefon' => 'phone',
        'dokument' => 'document',
    ];

    public function recordThread($conversation, $thread, array $conversationData, $legacyId, $userId, $timezone, $crmArchiveId = null)
    {
        return $this->store([
            'crm_archive_id' => $crmArchiveId,
            'conversation_id' => $conversation->id,
            'thread_id' => $thread->id,
            'attachment_id' => null,
            'kind' => CrmArchiveEntry::KIND_THREAD,
            'archived_by' => $userId,
            'customer_id' => $this->customerIdFrom($conversationData),
            'legacy_id' => $legacyId,
            'subject' => $conversationData['subject'] ?? null,
            'entry_type' => $conversationData['type'] ?? null,
            'entry_date' => $this->toAppTimezone($conversationData['X-Dio-Datum'] ?? null, $timezone),
        ]);
    }

    public function recordAttachment($conversation, $thread, $attachmentId, array $attachmentData, $legacyId, $userId, $timezone, $crmArchiveId = null)
    {
        return $this->store([
            'crm_archive_id' => $crmArchiveId,
            'conversation_id' => $conversation->id,
            'thread_id' => $thread->id,
            'attachment_id' => $attachmentId,
            'kind' => CrmArchiveEntry::KIND_ATTACHMENT,
            'archived_by' => $userId,
            'customer_id' => $this->customerIdFrom($attachmentData),
            'legacy_id' => $legacyId,
            'subject' => $attachmentData['subject'] ?? null,
            'entry_type' => $attachmentData['type'] ?? null,
            'entry_date' => $this->toAppTimezone($attachmentData['X-Dio-Datum'] ?? null, $timezone),
        ]);
    }

    /**
     * Trägt die Zuordnung nach, wenn sie erst nach der Archivierung entsteht.
     */
    public function linkArchive($conversationId, $customerId, $crmArchiveId)
    {
        try {
            CrmArchiveEntry::where('conversation_id', $conversationId)
                ->where('customer_id', (string) $customerId)
                ->whereNull('crm_archive_id')
                ->update(['crm_archive_id' => $crmArchiveId]);
        } catch (\Exception $e) {
            \Helper::log('conversation_archive', 'Zuordnung der Archiveinträge fehlgeschlagen: ' . $e->getMessage());
        }
    }

    /**
     * Der Typ, unter dem die Archive-API denselben Eintrag führt.
     */
    public static function apiType($legacyType)
    {
        return self::TYPE_MAP[$legacyType] ?? $legacyType;
    }

    /**
     * Der Kunde steckt in den Zuordnungen, die mit der Archivierung gesendet wurden.
     */
    private function customerIdFrom(array $data)
    {
        foreach ($data['X-Dio-Zuordnungen'] ?? [] as $relation) {
            if (($relation['Typ'] ?? '') === 'kunde' && !empty($relation['Id'])) {
                return (string) $relation['Id'];
            }
        }

        return null;
    }

    /**
     * X-Dio-Datum trägt keine Zeitzone, wurde aber in der des Nutzers erzeugt.
     *
     * Gespeichert wird in der Zeitzone der Anwendung, weil Laravel Datumsfelder
     * beim Lesen genau so interpretiert. Ein in UTC abgelegter Wert käme sonst
     * beim Lesen um den Offset verschoben zurück.
     */
    private function toAppTimezone($value, $timezone)
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone ?: config('app.timezone'))
                ->setTimezone(config('app.timezone'));
        } catch (\Exception $e) {
            return null;
        }
    }

    private function store(array $row)
    {
        if (empty($row['customer_id'])) {
            return null;
        }

        try {
            $entry = CrmArchiveEntry::firstOrNew([
                'conversation_id' => $row['conversation_id'],
                'thread_id' => $row['thread_id'],
                'attachment_id' => $row['attachment_id'],
                'customer_id' => $row['customer_id'],
            ]);

            // Eine erneute Archivierung darf eine bereits aufgelöste UUID nicht verlieren.
            if ($entry->exists && $entry->archive_entry_id) {
                return $entry;
            }

            $entry->fill($row);
            $entry->sync_state = CrmArchiveEntry::STATE_PENDING;
            $entry->save();

            return $entry;
        } catch (\Exception $e) {
            \Helper::log('conversation_archive', 'Archiveintrag konnte nicht vermerkt werden: ' . $e->getMessage());
            return null;
        }
    }
}

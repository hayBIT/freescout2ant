<?php

namespace Modules\AmeiseModule\Services;

use Carbon\Carbon;
use Modules\AmeiseModule\Entities\CrmArchiveEntry;

/**
 * Ermittelt die UUID eines Archiveintrags über das Zeitfenster.
 *
 * Der direkte Weg — /api/archive-entries/id-mapping — ist mit dem Scope
 * ameise.customer-archives nicht zugänglich (403). Stattdessen werden die
 * Einträge des Kunden um den Archivierungszeitpunkt gelesen und über das Tripel
 * aus Datum, Betreff und Typ zugeordnet.
 *
 * Grundsatz bei Mehrdeutigkeit: lieber nicht bearbeitbar als falsch zugeordnet.
 * Ein PATCH auf den falschen Eintrag wäre der schlimmere Ausgang.
 */
class ArchiveEntryResolver
{
    /** Zeitfenster um den Archivierungszeitpunkt. */
    private const WINDOW_SECONDS = 120;

    /** Zulässige Abweichung beim Datumsvergleich. */
    private const DATE_TOLERANCE_SECONDS = 2;

    /** Danach gilt ein Eintrag als endgültig nicht auffindbar. */
    private const GIVE_UP_AFTER_HOURS = 48;

    private $client;
    private $listCache = [];

    public function __construct(ArchiveApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * @return string Der neue sync_state des Eintrags.
     */
    public function resolve(CrmArchiveEntry $entry): string
    {
        if ($entry->archive_entry_id) {
            return $entry->sync_state;
        }

        if (empty($entry->entry_date) || empty($entry->customer_id)) {
            return $this->mark($entry, CrmArchiveEntry::STATE_UNMAPPED, 'Ohne Datum oder Kunde ist keine Zuordnung möglich.');
        }

        $items = $this->entriesAround($entry->customer_id, $entry->entry_date);
        if ($items === null) {
            // Die Antwort der API mitschreiben — bei einem 422 nennt sie das
            // beanstandete Feld, und daran hängt die Fehlersuche.
            $message = $this->client->getLastError();
            $body = trim((string) $this->client->getLastResponseBody());
            if ($body !== '') {
                $message .= ' — ' . mb_substr($body, 0, 300);
            }

            return $this->mark($entry, CrmArchiveEntry::STATE_PENDING, $message);
        }

        $candidates = $this->candidatesFor($entry, $items);

        if (count($candidates) === 1) {
            return $this->adopt($entry, reset($candidates));
        }

        if (count($candidates) > 1) {
            return $this->mark(
                $entry,
                CrmArchiveEntry::STATE_UNMAPPED,
                count($candidates) . ' Einträge passen gleich gut — keine eindeutige Zuordnung.'
            );
        }

        // Noch nicht da: die Ameise kann den Eintrag verzögert bereitstellen.
        if (Carbon::parse($entry->entry_date)->diffInHours(Carbon::now()) >= self::GIVE_UP_AFTER_HOURS) {
            return $this->mark($entry, CrmArchiveEntry::STATE_MISSING, 'Kein passender Eintrag im Zeitfenster gefunden.');
        }

        return $this->mark($entry, CrmArchiveEntry::STATE_PENDING, 'Noch kein passender Eintrag im Zeitfenster.');
    }

    /**
     * Einträge des Kunden um den Zeitpunkt herum, je Lauf nur einmal geladen.
     */
    private function entriesAround($customerId, $date)
    {
        $from = Carbon::parse($date)->subSeconds(self::WINDOW_SECONDS);
        $to = Carbon::parse($date)->addSeconds(self::WINDOW_SECONDS);
        $key = $customerId . '|' . $from->format('YmdHis') . '|' . $to->format('YmdHis');

        if (array_key_exists($key, $this->listCache)) {
            return $this->listCache[$key];
        }

        $list = $this->client->listArchiveEntries($customerId, [
            'pageSize' => 200,
            'dateMin' => $from->toIso8601String(),
            'dateMax' => $to->toIso8601String(),
        ]);

        $this->listCache[$key] = $list === null ? null : ($list['items'] ?? []);

        return $this->listCache[$key];
    }

    /**
     * Passende Einträge: gleicher Zeitpunkt, gleicher Betreff, gleicher Typ —
     * und noch von keinem anderen Datensatz beansprucht.
     */
    private function candidatesFor(CrmArchiveEntry $entry, array $items): array
    {
        $taken = CrmArchiveEntry::whereNotNull('archive_entry_id')
            ->where('customer_id', $entry->customer_id)
            ->where('id', '!=', $entry->id)
            ->pluck('archive_entry_id')
            ->all();

        $type = ArchiveEntryRecorder::apiType($entry->entry_type);
        $found = [];
        foreach (self::subjectAlternatives($entry->subject, $entry->kind) as $subject) {
            foreach (self::candidates($items, $type, $subject, $entry->entry_date, $taken) as $candidate) {
                $found[$candidate['id']] = $candidate;
            }
        }

        return array_values($found);
    }

    /**
     * Bildanhänge werden vor der Archivierung in PDF gewandelt und umbenannt.
     * Für nachträglich angelegte Datensätze ist deshalb auch der PDF-Name zu prüfen.
     */
    public static function subjectAlternatives($subject, $kind): array
    {
        $subject = (string) $subject;
        $alternatives = [$subject];

        if ($kind !== CrmArchiveEntry::KIND_ATTACHMENT) {
            return $alternatives;
        }

        $extension = strtolower((string) pathinfo($subject, PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff', 'webp', 'heic'], true)) {
            $alternatives[] = pathinfo($subject, PATHINFO_FILENAME) . '.pdf';
        }

        return $alternatives;
    }

    /**
     * Die eigentliche Zuordnungsregel — ohne Datenbank, damit sie prüfbar bleibt.
     *
     * Ein Eintrag passt, wenn Typ, Betreff und Zeitpunkt übereinstimmen und ihn
     * nicht bereits ein anderer Datensatz beansprucht.
     */
    public static function candidates(array $items, $expectedType, $expectedSubject, $expectedDate, array $taken = []): array
    {
        $expectedSubject = self::normalize($expectedSubject);
        $expectedDate = Carbon::parse($expectedDate);

        $candidates = [];
        foreach ($items as $item) {
            if (empty($item['id']) || in_array($item['id'], $taken, true)) {
                continue;
            }
            if (($item['type'] ?? null) !== $expectedType) {
                continue;
            }
            if (self::normalize($item['subject'] ?? null) !== $expectedSubject) {
                continue;
            }
            if (empty($item['date'])) {
                continue;
            }
            if (abs(Carbon::parse($item['date'])->diffInSeconds($expectedDate, false)) > self::DATE_TOLERANCE_SECONDS) {
                continue;
            }

            $candidates[] = $item;
        }

        return $candidates;
    }

    /**
     * Der Betreff wird beim Archivieren auf 128 Zeichen gekürzt; verglichen wird
     * deshalb auf dieser Länge.
     */
    private static function normalize($subject): string
    {
        return mb_substr(trim((string) $subject), 0, 128);
    }

    private function adopt(CrmArchiveEntry $entry, array $item): string
    {
        $entry->archive_entry_id = $item['id'];
        $entry->subject = $item['subject'] ?? $entry->subject;
        $entry->is_public = $item['isPublic'] ?? null;
        $entry->requires_review = $item['requiresReview'] ?? null;
        $entry->is_deleted = $item['isDeleted'] ?? null;
        $entry->tags = array_values((array) ($item['tags'] ?? []));
        $entry->contracts = $this->ids($item['contracts'] ?? []);
        $entry->contract_lines = $this->ids($item['contractLines'] ?? []);
        $entry->remote_synced_at = Carbon::now();
        $entry->last_error = null;
        $entry->sync_state = CrmArchiveEntry::STATE_OK;
        $entry->save();

        return CrmArchiveEntry::STATE_OK;
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

    private function mark(CrmArchiveEntry $entry, $state, $error = null): string
    {
        $entry->sync_state = $state;
        $entry->last_error = $error;
        $entry->save();

        return $state;
    }
}

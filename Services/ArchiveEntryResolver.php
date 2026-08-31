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
    /**
     * Zeitfenster um den Archivierungszeitpunkt — bewusst grob.
     *
     * dateMin/dateMax werden ohne Zeitzone gesendet (die API verlangt
     * "Y-m-d H:i:s"), die Serverzeitzone ist also nicht sicher bekannt. Das
     * Fenster deckt deshalb auch eine Verschiebung um zwei Stunden ab. Genau
     * wird erst lokal verglichen, dort liegen die Zeitstempel mit Offset vor.
     */
    private const WINDOW_SECONDS = 10800;

    /**
     * Kandidaten für das Datumsformat der Liste.
     *
     * Die OpenAPI-Datei beschreibt dateMin/dateMax nur als "string", und sowohl
     * ISO 8601 mit Offset als auch "Y-m-d H:i:s" werden mit 422 abgelehnt.
     * Deshalb probiert der Resolver die gängigen Formate durch und merkt sich
     * das erste, das durchgeht.
     */
    private const API_DATE_FORMATS = [
        // Auf der Live-Umgebung geprüft: die API nimmt ausschließlich das reine
        // Datum. Alle Varianten mit Uhrzeit enden in einem 422. dateMin/dateMax
        // sind also Tages-, keine Zeitfilter.
        'Y-m-d',
        'Y-m-d\TH:i:s',
        'Y-m-d H:i:s',
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s\Z',
        'U',
    ];

    /** Das Format, das sich in diesem Prozess bewährt hat. */
    private static $workingDateFormat = null;

    /** Sicherheitsgrenze beim Blättern durch ein Zeitfenster. */
    private const MAX_PAGES = 5;

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
        $from = Carbon::parse($date)->setTimezone('UTC')->subSeconds(self::WINDOW_SECONDS);
        $to = Carbon::parse($date)->setTimezone('UTC')->addSeconds(self::WINDOW_SECONDS);
        $key = $customerId . '|' . $from->format('YmdHis') . '|' . $to->format('YmdHis');

        if (array_key_exists($key, $this->listCache)) {
            return $this->listCache[$key];
        }

        foreach ($this->dateFormats() as $format) {
            $items = $this->fetchWindow($customerId, $from, $to, $format);

            if ($items !== null) {
                self::$workingDateFormat = $format;
                return $this->listCache[$key] = $items;
            }

            // Nur ein Formatfehler rechtfertigt den nächsten Versuch.
            if ($this->client->getLastStatusCode() !== 422) {
                return $this->listCache[$key] = null;
            }
        }

        return $this->listCache[$key] = null;
    }

    /**
     * Die Einträge, die im Fenster tatsächlich gefunden wurden — für die
     * Fehlersuche, wenn eine Zuordnung nicht aufgeht.
     */
    public function windowItems(CrmArchiveEntry $entry)
    {
        if (empty($entry->entry_date) || empty($entry->customer_id)) {
            return [];
        }

        return $this->entriesAround($entry->customer_id, $entry->entry_date) ?: [];
    }

    /**
     * Welches Datumsformat die API in diesem Lauf akzeptiert hat.
     */
    public static function workingDateFormat()
    {
        return self::$workingDateFormat;
    }

    /**
     * Ein bewährtes Format zuerst, danach die übrigen Kandidaten.
     */
    private function dateFormats(): array
    {
        if (self::$workingDateFormat === null) {
            return self::API_DATE_FORMATS;
        }

        return array_merge(
            [self::$workingDateFormat],
            array_diff(self::API_DATE_FORMATS, [self::$workingDateFormat])
        );
    }

    /**
     * @return array|null null, wenn die API die Anfrage abgelehnt hat.
     */
    private function fetchWindow($customerId, $from, $to, $format)
    {
        $items = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $list = $this->client->listArchiveEntries($customerId, [
                'page' => $page,
                'pageSize' => 200,
                'dateMin' => $from->format($format),
                'dateMax' => $to->format($format),
            ]);

            if ($list === null) {
                return null;
            }

            $items = array_merge($items, $list['items'] ?? []);

            if ($page >= ($list['numberOfPages'] ?? 1)) {
                break;
            }
        }

        return $items;
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

<?php

namespace Modules\AmeiseModule\Console\Commands;

use App\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\AmeiseModule\Console\Concerns\WritesReport;
use Modules\AmeiseModule\Services\ArchiveApiClient;
use Modules\AmeiseModule\Services\ArchiveEntryPayload;
use Modules\AmeiseModule\Services\CrmApiClient;
use Modules\AmeiseModule\Services\TokenService;

/**
 * Beantwortet die Frage, ob die Mitarbeiter-API beim Archivieren eine ID
 * zurückgibt und ob sich diese über die Archive-API auflösen lässt.
 *
 * Legt dafür einen echten Archiveintrag beim angegebenen Kunden an — deshalb
 * die Rückfrage vor dem Senden und --cleanup zum anschließenden Löschen.
 */
class ProbeArchiveId extends Command
{
    use WritesReport;

    protected $signature = 'ameise:archive-probe
        {--customer= : Ameise-Kundennummer, bei der der Testeintrag angelegt wird}
        {--user= : ID des FreeScout-Benutzers, dessen Ameise-Verbindung genutzt wird}
        {--type=email : Wert für X-Dio-Typ}
        {--cleanup : Den Testeintrag anschließend über die Archive-API löschen}
        {--cleanup-only : Nichts anlegen, nur liegengebliebene Testeinträge des Kunden entfernen}
        {--force : Ohne Rückfrage ausführen}
        {--out= : Die vollständige Ausgabe zusätzlich in diese Datei schreiben}';

    protected $description = 'Prüft, ob die Ameise beim Archivieren eine Eintrags-ID zurückgibt';

    /** Betreff, an dem die Testeinträge dieses Befehls erkennbar sind. */
    private const PROBE_SUBJECT = 'FreeScout Verbindungstest';

    public function handle()
    {
        $exitCode = $this->runProbe();
        $this->writeReport();

        return $exitCode;
    }

    private function runProbe()
    {
        $customerId = $this->option('customer');
        if (!$customerId) {
            $this->error('Bitte --customer=<Ameise-Kundennummer> angeben.');
            return 1;
        }

        $userId = $this->option('user') ?: $this->firstConnectedUserId();
        if (!$userId || !file_exists(storage_path('user_' . $userId . '_ant.txt'))) {
            $this->error('Kein Benutzer mit bestehender Ameise-Verbindung gefunden. Bitte --user angeben.');
            return 1;
        }
        $user = User::find($userId);
        if (!$user) {
            $this->error('Benutzer ' . $userId . ' existiert nicht.');
            return 1;
        }

        $tokenService = new TokenService('', $userId);
        $archiveClient = new ArchiveApiClient($tokenService);

        $this->line('Modus:    ' . config('ameisemodule.ameise_mode'));
        $this->line('Benutzer: ' . $user->getFullName() . ' (' . $userId . ')');
        $this->line('Kunde:    ' . $customerId);
        $this->line('Archive-API: ' . ($archiveClient->isConfigured() ? $archiveClient->getBaseUrl() : 'nicht konfiguriert'));
        $this->line('');

        // Ohne Archive-API bliebe der Testeintrag stehen — das vor dem Anlegen klären.
        if (($this->option('cleanup') || $this->option('cleanup-only')) && !$archiveClient->isConfigured()) {
            $this->error('--cleanup verlangt eine hinterlegte Archive-API-URL, sonst lässt sich der Testeintrag nicht wieder löschen.');
            $this->line('Bitte unter Einstellungen → Ameise eintragen oder --cleanup weglassen und den Eintrag von Hand entfernen.');
            return 1;
        }

        if ($this->option('cleanup-only')) {
            return $this->removeLeftovers($archiveClient, $customerId);
        }

        $this->warn('Es wird ein echter Archiveintrag beim genannten Kunden angelegt.');
        if (config('ameisemodule.ameise_mode') == 'live') {
            $this->warn('Achtung: Modus "live" — der Eintrag landet in der echten Kundenakte.');
        }
        if (!$this->option('cleanup')) {
            $this->warn('Ohne --cleanup bleibt der Eintrag bestehen und muss von Hand entfernt werden.');
        }

        if (!$this->option('force')) {
            if (!$this->input->isInteractive()) {
                $this->error('Die Rückfrage kann in dieser Umgebung nicht beantwortet werden. Bitte --force ergänzen.');
                return 1;
            }
            if (!$this->confirm('Fortfahren?', false)) {
                $this->line('Abgebrochen.');
                return 0;
            }
        }

        $crmClient = new CrmApiClient($tokenService);

        $archived = $crmClient->archiveConversation([
            'type' => $this->option('type'),
            'subject' => self::PROBE_SUBJECT,
            'body' => "Testeintrag aus FreeScout zur Prüfung der Archivierungsschnittstelle.\r\nKann gelöscht werden.",
            'Content-Type' => 'text/plain; charset=utf-8',
            'x-dio-metadaten' => [['Value' => 'Quelle', 'Text' => 'ameise:archive-probe']],
            'X-Dio-Zuordnungen' => [['Typ' => 'kunde', 'Id' => $customerId]],
            'X-Dio-Datum' => Carbon::now()->format('Y-m-d\TH:i:s'),
        ]);

        if (!$archived) {
            $this->error('Die Archivierung ist fehlgeschlagen. Mit AMEISE_LOG_STATUS=true stehen Details im Log.');
            return 1;
        }

        $meta = $crmClient->getLastArchiveResponseMeta();
        $legacyId = $crmClient->getLastArchiveEntryId();

        // Das Ergebnis zuerst: in abgeschnittenen Konsolen zählen die ersten Zeilen.
        $this->info('Archivierung erfolgreich (Status ' . ($meta['status'] ?? '—') . ').');
        if ($legacyId) {
            $this->info('Erkannte ID: ' . $legacyId);
        } else {
            $this->warn('In der Antwort war keine ID zu erkennen.');
        }

        $uuid = null;
        $exitCode = 0;

        if ($legacyId) {
            $this->line('Löse die ID über /api/archive-entries/id-mapping auf …');
            $mapping = $archiveClient->mapArchiveEntryId($legacyId);
            if ($mapping === null) {
                $this->error('Mapping fehlgeschlagen: ' . $archiveClient->getLastError());
                $this->line('Möglicherweise ist die erkannte ID nicht die Legacy-ID des Archiveintrags.');
                $exitCode = 1;
            } else {
                $uuid = $this->extractUuid($mapping);
                $this->info('Mapping erfolgreich: ' . $legacyId . ' → ' . ($uuid ?: json_encode($mapping)));
                $this->info('Ergebnis: Der Weg "Legacy-POST → ID → id-mapping → PATCH" funktioniert.');
            }
        } else {
            $this->line('Die Header unten zeigen, ob die ID an anderer Stelle steht. Falls nicht, führt der Weg');
            $this->line('über den Backfill (Abgleich per GET /archive-entries) oder den neuen Schreibpfad.');
            $exitCode = 1;
        }

        $this->cleanUp($archiveClient, $customerId, $uuid);

        $this->line('');
        $this->line('Vollständige Antwort der Mitarbeiter-API:');
        $this->line('  Status: ' . ($meta['status'] ?? '—'));
        $this->line('  Header:');
        foreach ($meta['headers'] ?? [] as $name => $value) {
            $this->line('    ' . $name . ': ' . $value);
        }
        $body = trim((string) ($meta['body'] ?? ''));
        $this->line('  Body: ' . ($body === '' ? '(leer)' : $body));

        return $exitCode;
    }

    /**
     * Der Testeintrag muss auch dann verschwinden, wenn die Auswertung scheitert.
     * Ohne UUID aus dem Mapping wird er über die Eintragsliste gesucht.
     */
    private function cleanUp(ArchiveApiClient $archiveClient, $customerId, $uuid)
    {
        if (!$this->option('cleanup')) {
            $this->comment('Der Testeintrag bleibt bestehen. Mit --cleanup wird er direkt wieder gelöscht.');
            return;
        }

        $uuid = $uuid ?: $this->findProbeEntryId($archiveClient, $customerId);
        if (!$uuid) {
            $this->error('Der Testeintrag konnte nicht gefunden werden.');
            $this->warn('Bitte den Eintrag "' . self::PROBE_SUBJECT . '" beim Kunden ' . $customerId . ' von Hand entfernen.');
            return;
        }

        if ($archiveClient->deleteArchiveEntry($customerId, $uuid)) {
            $this->info('Testeintrag gelöscht.');
            return;
        }

        $this->error('Löschen fehlgeschlagen: ' . $archiveClient->getLastError());
        $this->warn('Bitte den Eintrag "' . self::PROBE_SUBJECT . '" beim Kunden ' . $customerId . ' von Hand entfernen.');
    }

    /**
     * Entfernt alle Testeinträge dieses Befehls beim Kunden — für Läufe, die
     * vorzeitig abgebrochen sind und ihren Eintrag hinterlassen haben.
     */
    private function removeLeftovers(ArchiveApiClient $archiveClient, $customerId)
    {
        $list = $archiveClient->listArchiveEntries($customerId, [
            'pageSize' => 100,
            'name' => self::PROBE_SUBJECT,
        ]);

        if ($list === null) {
            $this->error('Die Eintragsliste konnte nicht gelesen werden: ' . $archiveClient->getLastError());
            return 1;
        }

        $found = [];
        foreach ($list['items'] ?? [] as $item) {
            if (($item['subject'] ?? '') === self::PROBE_SUBJECT && !empty($item['id'])) {
                $found[$item['id']] = $item['date'] ?? '';
            }
        }

        if (empty($found)) {
            $this->info('Keine Testeinträge gefunden.');
            return 0;
        }

        $this->line('Gefundene Testeinträge: ' . count($found));
        $failed = 0;
        foreach ($found as $id => $date) {
            if ($archiveClient->deleteArchiveEntry($customerId, $id)) {
                $this->info('  gelöscht (DELETE): ' . $id . ($date ? ' (' . $date . ')' : ''));
                continue;
            }

            $deleteError = $archiveClient->getLastError();
            $deleteStatus = $archiveClient->getLastStatusCode();

            // Fällt DELETE aus, bleibt der Weg über isDeleted — und die Antwort
            // darauf sagt zugleich, ob die Archive-API überhaupt Schreibzugriff gewährt.
            if ($this->softDelete($archiveClient, $customerId, $id)) {
                $this->info('  als gelöscht markiert (PATCH): ' . $id . ($date ? ' (' . $date . ')' : ''));
                $this->line('        DELETE war nicht möglich (' . $deleteStatus . ': ' . $deleteError . ').');
                continue;
            }

            $this->error('  fehlgeschlagen: ' . $id);
            $this->line('        DELETE (' . $deleteStatus . '): ' . $deleteError);
            $this->line('        PATCH  (' . $archiveClient->getLastStatusCode() . '): ' . $archiveClient->getLastError());
            $failed++;
        }

        if ($failed > 0) {
            $this->warn('Bitte die verbliebenen Einträge "' . self::PROBE_SUBJECT . '" in der Ameise von Hand entfernen.');
            return 1;
        }

        return 0;
    }

    /**
     * Markiert einen Eintrag über PATCH als gelöscht — Read-Modify-Write, damit
     * Tags und Zuordnungen dabei nicht verloren gehen.
     */
    private function softDelete(ArchiveApiClient $archiveClient, $customerId, $entryId): bool
    {
        $entry = $archiveClient->getCustomerArchiveEntry($customerId, $entryId);
        if ($entry === null) {
            return false;
        }

        return $archiveClient->updateArchiveEntry(
            $customerId,
            $entryId,
            ArchiveEntryPayload::fromEntry($entry, ['isDeleted' => true])
        );
    }

    /**
     * Sucht den eben angelegten Testeintrag über die Eintragsliste des Kunden.
     */
    private function findProbeEntryId(ArchiveApiClient $archiveClient, $customerId)
    {
        $list = $archiveClient->listArchiveEntries($customerId, [
            'pageSize' => 20,
            'name' => self::PROBE_SUBJECT,
        ]);

        foreach ($list['items'] ?? [] as $item) {
            if (($item['subject'] ?? '') === self::PROBE_SUBJECT && !empty($item['id'])) {
                return $item['id'];
            }
        }

        return null;
    }

    /**
     * archiveApiId kommt je nach Serialisierung als String oder als UUID-Objekt.
     */
    private function extractUuid(array $mapping)
    {
        $value = $mapping['archiveApiId'] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            foreach (['urn', 'hex'] as $key) {
                if (!empty($value[$key]) && is_string($value[$key])) {
                    return str_replace('urn:uuid:', '', $value[$key]);
                }
            }
        }

        return null;
    }

    private function firstConnectedUserId()
    {
        foreach (glob(storage_path('user_*_ant.txt')) ?: [] as $file) {
            if (preg_match('/user_(\d+)_ant\.txt$/', $file, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }
}

<?php

namespace Modules\AmeiseModule\Console\Commands;

use App\User;
use Illuminate\Console\Command;
use Modules\AmeiseModule\Console\Concerns\WritesReport;
use Modules\AmeiseModule\Services\ArchiveApiClient;
use Modules\AmeiseModule\Services\TokenService;

/**
 * Listet die Archiveinträge eines Kunden — rein lesend.
 *
 * Zeigt vor allem, welche ID die Archive-API selbst führt. Solange das
 * ID-Mapping nicht zugänglich ist, ist diese Liste der Weg von der ID aus der
 * Mitarbeiter-API zum bearbeitbaren Eintrag.
 */
class ListArchiveEntries extends Command
{
    use WritesReport;

    protected $signature = 'ameise:archive-entries
        {--customer= : Ameise-Kundennummer}
        {--user= : ID des FreeScout-Benutzers, dessen Ameise-Verbindung genutzt wird}
        {--name= : Nur Einträge, deren Betreff diesen Text enthält (mindestens 3 Zeichen)}
        {--entry= : Statt der Liste einen einzelnen Eintrag über GET /archive-entries/{id} abrufen}
        {--limit=20 : Anzahl der Einträge}
        {--raw : Den ersten Eintrag zusätzlich als JSON ausgeben}
        {--probe-dates= : Zeitpunkt, mit dem verschiedene Datumsformate für dateMin/dateMax geprüft werden}
        {--out= : Die vollständige Ausgabe zusätzlich in diese Datei schreiben}';

    protected $description = 'Listet Archiveinträge eines Kunden aus der Archive-API';

    public function handle()
    {
        $exitCode = $this->listEntries();
        $this->writeReport();

        return $exitCode;
    }

    private function listEntries()
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

        $client = new ArchiveApiClient(new TokenService('', $userId));
        if (!$client->isConfigured()) {
            $this->error('Keine URL für die Archive-API hinterlegt.');
            return 1;
        }

        $this->line('Benutzer: ' . (optional($user)->getFullName() ?: 'unbekannt') . ' (' . $userId . ')');
        $this->line('Kunde:    ' . $customerId);

        $entryId = trim((string) $this->option('entry'));
        if ($entryId !== '') {
            return $this->showEntry($client, $customerId, $entryId);
        }

        $probe = trim((string) $this->option('probe-dates'));
        if ($probe !== '') {
            return $this->probeDateFormats($client, $customerId, $probe);
        }

        $filters = ['pageSize' => (int) $this->option('limit')];
        $name = trim((string) $this->option('name'));
        if ($name !== '') {
            $filters['name'] = $name;
            $this->line('Filter:   Betreff enthält "' . $name . '"');
        }

        $list = $client->listArchiveEntries($customerId, $filters);
        if ($list === null) {
            $this->error('Abruf fehlgeschlagen: ' . $client->getLastError());
            $body = trim((string) $client->getLastResponseBody());
            if ($body !== '') {
                $this->line('Antwort: ' . mb_substr($body, 0, 500));
            }
            return 1;
        }

        $items = $list['items'] ?? [];
        $this->line('Treffer:  ' . ($list['numberOfResults'] ?? count($items)));
        $this->line('');

        if (empty($items)) {
            $this->info('Keine Einträge.');
            return 0;
        }

        foreach ($items as $item) {
            $this->line('  ' . ($item['id'] ?? '—'));
            $this->line('      ' . ($item['date'] ?? '—') . ' · ' . ($item['type'] ?? '—')
                . ' · ' . ($item['subject'] ?? '(kein Betreff)'));

            $flags = [];
            if (!empty($item['requiresReview'])) {
                $flags[] = 'Prüfung';
            }
            if (isset($item['isPublic']) && !$item['isPublic']) {
                $flags[] = 'intern';
            }
            if (!empty($item['isDeleted'])) {
                $flags[] = 'gelöscht';
            }
            if (!empty($item['tags'])) {
                $flags[] = 'Tags: ' . implode(', ', (array) $item['tags']);
            }
            if (!empty($item['contracts'])) {
                $flags[] = count($item['contracts']) . ' Vertrag/Verträge';
            }
            if (!empty($flags)) {
                $this->line('      ' . implode(' · ', $flags));
            }
        }

        if ($this->option('raw')) {
            $this->line('');
            $this->line('Erster Eintrag vollständig:');
            $this->line(json_encode($items[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return 0;
    }

    /**
     * Die OpenAPI-Datei beschreibt dateMin/dateMax nur als "string". Welches
     * Format die API tatsächlich akzeptiert, klärt dieser Durchlauf.
     */
    private function probeDateFormats(ArchiveApiClient $client, $customerId, $reference)
    {
        try {
            $date = \Carbon\Carbon::parse($reference);
        } catch (\Exception $e) {
            $this->error('Zeitpunkt nicht lesbar: ' . $reference);
            return 1;
        }

        $from = $date->copy()->subMinutes(2);
        $to = $date->copy()->addMinutes(2);

        $formats = [
            'ISO 8601 mit Zeitzone' => [$from->toIso8601String(), $to->toIso8601String()],
            'ISO 8601 in UTC (Z)' => [$from->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'), $to->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z')],
            'ohne Zeitzone (T)' => [$from->format('Y-m-d\TH:i:s'), $to->format('Y-m-d\TH:i:s')],
            'mit Leerzeichen' => [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')],
            'nur Datum' => [$from->format('Y-m-d'), $to->format('Y-m-d')],
            'Unix-Zeitstempel' => [(string) $from->getTimestamp(), (string) $to->getTimestamp()],
        ];

        $this->line('Zeitpunkt: ' . $date->toIso8601String());
        $this->line('');

        $worked = [];
        foreach ($formats as $label => $range) {
            $result = $client->listArchiveEntries($customerId, [
                'pageSize' => 5,
                'dateMin' => $range[0],
                'dateMax' => $range[1],
            ]);

            $status = $client->getLastStatusCode();
            if ($result !== null) {
                $count = $result['numberOfResults'] ?? count($result['items'] ?? []);
                $this->line('  <info>OK</info>    ' . str_pad($label, 24) . $range[0] . '  (' . $status . ', ' . $count . ' Treffer)');
                $worked[] = $label;
                continue;
            }

            $this->line('  <error>FEHL</error>  ' . str_pad($label, 24) . $range[0] . '  (' . $status . ')');
            $body = trim((string) $client->getLastResponseBody());
            if ($body !== '') {
                $this->line('        ' . mb_substr($body, 0, 300));
            }
        }

        $this->line('');
        if (empty($worked)) {
            $this->error('Kein Format wurde akzeptiert.');
            return 1;
        }

        $this->info('Akzeptiert: ' . implode(', ', $worked));

        return 0;
    }

    /**
     * Ruft einen einzelnen Eintrag ab — einmal ohne und einmal mit Kundenkontext.
     * Damit lässt sich prüfen, ob die ID aus der Mitarbeiter-API hier direkt greift.
     */
    private function showEntry(ArchiveApiClient $client, $customerId, $entryId)
    {
        $this->line('Eintrag:  ' . $entryId);
        $this->line('');

        $routes = [
            'GET /archive-entries/' . $entryId => function () use ($client, $entryId) {
                return $client->getArchiveEntry($entryId);
            },
            'GET /customers/' . $customerId . '/archive-entries/' . $entryId => function () use ($client, $customerId, $entryId) {
                return $client->getCustomerArchiveEntry($customerId, $entryId);
            },
        ];

        $found = null;
        foreach ($routes as $label => $call) {
            $result = $call();
            if ($result !== null) {
                $this->line('  <info>OK</info>    ' . $label . ' (' . $client->getLastStatusCode() . ')');
                $found = $found ?: $result;
                continue;
            }
            $this->line('  <error>FEHL</error>  ' . $label . ' (' . ($client->getLastStatusCode() ?: 'kein Status') . ') — ' . $client->getLastError());
        }

        if ($found === null) {
            $this->line('');
            $this->warn('Die ID ist über keinen der beiden Wege abrufbar.');
            return 1;
        }

        $this->line('');
        $this->info('Die ID adressiert einen Eintrag der Archive-API.');
        $this->line(json_encode($found, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return 0;
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

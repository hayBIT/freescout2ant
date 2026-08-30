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
        {--limit=20 : Anzahl der Einträge}
        {--raw : Den ersten Eintrag zusätzlich als JSON ausgeben}
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

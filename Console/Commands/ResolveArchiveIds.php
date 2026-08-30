<?php

namespace Modules\AmeiseModule\Console\Commands;

use Illuminate\Console\Command;
use Modules\AmeiseModule\Console\Concerns\WritesReport;
use Modules\AmeiseModule\Entities\CrmArchiveEntry;
use Modules\AmeiseModule\Services\ArchiveApiClient;
use Modules\AmeiseModule\Services\ArchiveEntryResolver;
use Modules\AmeiseModule\Services\TokenService;

/**
 * Holt die UUIDs der Archiveinträge nach.
 *
 * Läuft bewusst getrennt vom Archivieren: schlägt die Auflösung fehl, bleibt
 * die Archivierung selbst davon unberührt (Abschnitt 8a des Konzepts).
 */
class ResolveArchiveIds extends Command
{
    use WritesReport;

    protected $signature = 'ameise:resolve-archive-ids
        {--limit=200 : Höchstzahl der Einträge je Lauf}
        {--conversation= : Nur Einträge dieser Konversation}
        {--user= : Nur Einträge, die dieser Benutzer archiviert hat}
        {--retry-unmapped : Auch bereits als nicht zuordenbar markierte Einträge erneut versuchen}
        {--list : Nur zeigen, welche Konversationen offene Einträge haben — ohne API-Aufruf}
        {--out= : Die vollständige Ausgabe zusätzlich in diese Datei schreiben}';

    protected $description = 'Ermittelt die UUIDs archivierter Einträge über die Archive-API';

    public function handle()
    {
        $exitCode = $this->resolveAll();
        $this->writeReport();

        return $exitCode;
    }

    private function resolveAll()
    {
        $states = [CrmArchiveEntry::STATE_PENDING];
        if ($this->option('retry-unmapped')) {
            $states[] = CrmArchiveEntry::STATE_UNMAPPED;
            $states[] = CrmArchiveEntry::STATE_MISSING;
        }

        // Neueste zuerst: frisch archivierte Einträge sind die, die jemand
        // bearbeiten will, und ihre Zeitstempel sind am verlässlichsten.
        $query = CrmArchiveEntry::whereNull('archive_entry_id')
            ->whereIn('sync_state', $states)
            ->orderByDesc('entry_date');

        if ($conversationId = $this->option('conversation')) {
            $query->where('conversation_id', $conversationId);
        }
        if ($userId = $this->option('user')) {
            $query->where('archived_by', $userId);
        }

        if ($this->option('list')) {
            return $this->listConversations($query);
        }

        $entries = $query->limit((int) $this->option('limit'))->get();

        if ($entries->isEmpty()) {
            $this->info('Keine offenen Einträge.');
            return 0;
        }

        $this->line('Offene Einträge: ' . $entries->count());
        $this->line('');

        $counts = [];
        $resolvers = [];

        foreach ($entries as $entry) {
            $resolver = $this->resolverFor($entry->archived_by, $resolvers);
            if ($resolver === null) {
                $counts['ohne Verbindung'] = ($counts['ohne Verbindung'] ?? 0) + 1;
                continue;
            }

            $state = $resolver->resolve($entry);
            $counts[$state] = ($counts[$state] ?? 0) + 1;

            $label = '  ' . str_pad($state, 9) . ' Konversation ' . $entry->conversation_id
                . ' · ' . ($entry->kind === CrmArchiveEntry::KIND_ATTACHMENT ? 'Anhang' : 'Nachricht')
                . ' · ' . mb_substr((string) $entry->subject, 0, 60);

            if ($state === CrmArchiveEntry::STATE_OK) {
                $this->line($label . ' → ' . $entry->archive_entry_id);
            } else {
                $this->line($label);
                if ($entry->last_error) {
                    $this->line('            ' . $entry->last_error);
                }
            }
        }

        $this->line('');
        foreach ($counts as $state => $count) {
            $this->line('  ' . str_pad($state, 16) . $count);
        }

        if ($format = ArchiveEntryResolver::workingDateFormat()) {
            $this->line('');
            $this->line('Akzeptiertes Datumsformat der API: ' . $format);
        }

        return 0;
    }

    /**
     * Zeigt, welche Konversationen offene Einträge haben — als Startpunkt für
     * einen gezielten Lauf. Rein lokal, ohne Zugriff auf die Ameise.
     */
    private function listConversations($query)
    {
        $rows = (clone $query)
            ->selectRaw('conversation_id, customer_id, count(*) as anzahl, max(entry_date) as letzte, min(subject) as betreff')
            ->groupBy('conversation_id', 'customer_id')
            ->orderByDesc('letzte')
            ->limit(20)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Keine offenen Einträge.');
            return 0;
        }

        $this->line('Konversationen mit offenen Einträgen (neueste zuerst):');
        $this->line('');
        foreach ($rows as $row) {
            $this->line('  --conversation=' . str_pad($row->conversation_id, 8)
                . str_pad($row->anzahl . ' Einträge', 14)
                . substr((string) $row->letzte, 0, 16)
                . '  ' . mb_substr((string) $row->betreff, 0, 50));
        }

        $this->line('');
        $this->line('Insgesamt offen: ' . CrmArchiveEntry::whereNull('archive_entry_id')->count());

        return 0;
    }

    /**
     * Je Benutzer ein eigener Resolver, weil jeder sein eigenes Token hat.
     */
    private function resolverFor($userId, array &$resolvers)
    {
        if (array_key_exists($userId, $resolvers)) {
            return $resolvers[$userId];
        }

        if (!$userId || !file_exists(storage_path('user_' . $userId . '_ant.txt'))) {
            $this->warn('Benutzer ' . ($userId ?: '—') . ' ist nicht mit der Ameise verbunden — Einträge bleiben offen.');
            return $resolvers[$userId] = null;
        }

        $client = new ArchiveApiClient(new TokenService('', $userId));
        if (!$client->isConfigured()) {
            $this->error('Keine URL für die Archive-API hinterlegt.');
            return $resolvers[$userId] = null;
        }

        return $resolvers[$userId] = new ArchiveEntryResolver($client);
    }
}

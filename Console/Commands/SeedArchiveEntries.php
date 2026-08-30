<?php

namespace Modules\AmeiseModule\Console\Commands;

use App\Thread;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\AmeiseModule\Console\Concerns\WritesReport;
use Modules\AmeiseModule\Entities\CrmArchive;
use Modules\AmeiseModule\Entities\CrmArchiveEntry;
use Modules\AmeiseModule\Entities\CrmArchiveThread;

/**
 * Legt für bereits archivierte Threads die fehlenden Einträge an.
 *
 * Bis zu dieser Ausbaustufe hat FreeScout nur vermerkt, *dass* ein Thread
 * archiviert wurde. Dieser Befehl rekonstruiert daraus die einzelnen
 * Archiveinträge — Nachricht und je Anhang einen — damit der Resolver
 * anschließend ihre UUIDs ermitteln kann.
 *
 * Er ruft dabei keine API auf und legt nichts in der Ameise an.
 */
class SeedArchiveEntries extends Command
{
    use WritesReport;

    private const MAX_SUBJECT_LENGTH = 128;

    /** Threads je Durchgang — begrenzt Speicher und Abfragen. */
    private const CHUNK_SIZE = 500;

    /** Abstand der Fortschrittsmeldungen. */
    private const PROGRESS_EVERY = 5000;

    protected $signature = 'ameise:seed-archive-entries
        {--conversation= : Nur diese Konversation}
        {--limit=1000 : Höchstzahl der Threads je Lauf; 0 für alle}
        {--offset=0 : Threads am Anfang überspringen, um in Etappen zu arbeiten}
        {--dry-run : Nur zeigen, was angelegt würde}
        {--out= : Die vollständige Ausgabe zusätzlich in diese Datei schreiben}';

    protected $description = 'Legt für bereits archivierte Threads die Archiveinträge lokal an';

    public function handle()
    {
        $exitCode = $this->seed();
        $this->writeReport();

        return $exitCode;
    }

    private function seed()
    {
        $query = CrmArchiveThread::orderBy('id');
        if ($conversationId = $this->option('conversation')) {
            $query->where('conversation_id', $conversationId);
        }

        $total = (clone $query)->count();
        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');

        if ($limit > 0) {
            $query->limit($limit);
        } elseif ($offset > 0) {
            // MySQL erlaubt OFFSET nur zusammen mit LIMIT.
            $query->limit(PHP_INT_MAX);
        }
        if ($offset > 0) {
            $query->skip($offset);
        }
        // Bei zehntausenden Threads werden nur die IDs geladen und dann
        // scheibchenweise mit Vorabladen der Beziehungen abgearbeitet.
        $ids = $query->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('Keine archivierten Threads gefunden.');
            return 0;
        }

        $this->line('Archivierte Threads insgesamt: ' . $total);
        $this->line('Bereits erfasste Einträge:     ' . CrmArchiveEntry::count());
        $this->line('In diesem Lauf betrachtet:     ' . $ids->count()
            . ($offset > 0 ? ' (ab ' . $offset . ')' : ''));
        if ($this->option('dry-run')) {
            $this->comment('Probelauf — es wird nichts gespeichert.');
        }
        $this->line('');

        $created = 0;
        $skipped = 0;
        $incomplete = 0;
        $done = 0;

        foreach ($ids->chunk(self::CHUNK_SIZE) as $chunk) {
            $archiveThreads = CrmArchiveThread::whereIn('id', $chunk)->get();

            $archives = CrmArchive::whereIn('id', $archiveThreads->pluck('crm_archive_id')->unique())
                ->get()
                ->keyBy('id');
            $threads = Thread::with(['attachments', 'conversation'])
                ->whereIn('id', $archiveThreads->pluck('thread_id')->unique())
                ->get()
                ->keyBy('id');
            $known = $this->knownKeys($archiveThreads->pluck('thread_id')->unique());

            $batch = [];
            foreach ($archiveThreads as $archiveThread) {
                $archive = $archives->get($archiveThread->crm_archive_id);
                $thread = $threads->get($archiveThread->thread_id);

                if (!$archive || !$thread || !$thread->conversation) {
                    $incomplete++;
                    continue;
                }

                foreach ($this->rowsFor($archive, $thread) as $row) {
                    $key = $this->keyFor($row);
                    if (isset($known[$key])) {
                        $skipped++;
                        continue;
                    }
                    $known[$key] = true;
                    $batch[] = $row;
                    $created++;
                }
            }

            if (!empty($batch) && !$this->option('dry-run')) {
                CrmArchiveEntry::insert($batch);
            }

            $done += $archiveThreads->count();
            // Sparsam melden: Aufgabenplaner kürzen die Anzeige, und die
            // Zusammenfassung am Ende ist wichtiger als der Fortschritt.
            if ($ids->count() > self::PROGRESS_EVERY && $done % self::PROGRESS_EVERY < self::CHUNK_SIZE) {
                $this->line('  … ' . $done . ' von ' . $ids->count() . ' Threads');
            }
        }

        $this->line('  angelegt:        ' . $created);
        $this->line('  bereits bekannt: ' . $skipped);
        if ($incomplete > 0) {
            $this->line('  übersprungen:    ' . $incomplete . ' (Thread oder Zuordnung nicht mehr vorhanden)');
        }

        $rest = $total - ($offset + $ids->count());
        if ($rest > 0) {
            $this->line('');
            $this->comment('Noch offen: ' . $rest . ' Threads. Nächste Etappe mit --offset='
                . ($offset + $ids->count()) . ' oder --limit=0 für alle auf einmal.');
        }

        if ($created > 0 && !$this->option('dry-run')) {
            $this->line('');
            $this->info('Weiter mit: php artisan ameise:resolve-archive-ids --conversation=<ID>');
        }

        return 0;
    }

    /**
     * Ein Datensatz für die Nachricht, je Anhang ein weiterer — so, wie die
     * Archivierung sie damals erzeugt hat.
     */
    private function rowsFor(CrmArchive $archive, Thread $thread): array
    {
        $conversation = $thread->conversation;
        $now = Carbon::now()->toDateTimeString();

        $base = [
            'crm_archive_id' => $archive->id,
            'conversation_id' => $conversation->id,
            'thread_id' => $thread->id,
            'archived_by' => $archive->archived_by,
            'customer_id' => (string) $archive->crm_user_id,
            'entry_date' => Carbon::parse($thread->created_at)->setTimezone('UTC')->toDateTimeString(),
            'sync_state' => CrmArchiveEntry::STATE_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $rows = [];

        // Bei #scanonly wurde nur der Anhang archiviert, nicht die Nachricht.
        if (stripos($conversation->subject ?? '', '#scanonly') === false) {
            $rows[] = $base + [
                'attachment_id' => null,
                'kind' => CrmArchiveEntry::KIND_THREAD,
                'subject' => $this->conversationSubject($conversation),
                'entry_type' => $conversation->type == \App\Conversation::TYPE_EMAIL ? 'email' : 'telefon',
            ];
        }

        foreach ($thread->attachments as $attachment) {
            $rows[] = $base + [
                'attachment_id' => $attachment->id,
                'kind' => CrmArchiveEntry::KIND_ATTACHMENT,
                'subject' => $attachment->file_name,
                'entry_type' => 'dokument',
            ];
        }

        return $rows;
    }

    private function conversationSubject($conversation): string
    {
        $subject = trim((string) ($conversation->subject ?? ''));
        if ($subject === '') {
            $subject = '(Kein Betreff)';
        }

        return mb_substr($subject, 0, self::MAX_SUBJECT_LENGTH);
    }

    /**
     * Bereits erfasste Einträge dieser Threads als Schlüsselmenge — eine Abfrage
     * statt einer je Zeile.
     */
    private function knownKeys($threadIds): array
    {
        $known = [];
        foreach (CrmArchiveEntry::whereIn('thread_id', $threadIds)->get(['thread_id', 'attachment_id', 'customer_id']) as $entry) {
            $known[$this->keyFor([
                'thread_id' => $entry->thread_id,
                'attachment_id' => $entry->attachment_id,
                'customer_id' => $entry->customer_id,
            ])] = true;
        }

        return $known;
    }

    private function keyFor(array $row): string
    {
        return $row['thread_id'] . '|' . ($row['attachment_id'] ?? '-') . '|' . $row['customer_id'];
    }
}

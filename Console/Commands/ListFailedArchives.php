<?php

namespace Modules\AmeiseModule\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\AmeiseModule\Entities\CrmArchiveAttempt;

class ListFailedArchives extends Command
{
    protected $signature = 'ameise:list-failed-archives {--since=30d : Zeitraum, z. B. 24h, 7d, 30d} {--status= : Filter auf einen konkreten Status}';

    protected $description = 'Listet unaufgeloeste Archivierungs-Fehlversuche aus crm_archive_attempts';

    public function handle()
    {
        $since = $this->parseSince((string) $this->option('since'));
        $status = $this->option('status');

        $query = CrmArchiveAttempt::whereNull('resolved_at')
            ->whereIn('status', CrmArchiveAttempt::FAILURE_STATUSES)
            ->where('created_at', '>=', $since);

        if ($status) {
            $query->where('status', $status);
        }

        $attempts = $query->orderBy('created_at', 'desc')->get();

        if ($attempts->isEmpty()) {
            $this->info('Keine offenen Fehlversuche im Zeitraum.');
            return 0;
        }

        $rows = $attempts->map(function ($a) {
            return [
                'id' => $a->id,
                'conv' => $a->conversation_id,
                'thread' => $a->thread_id,
                'user' => $a->user_id,
                'status' => $a->status,
                'try' => $a->attempt_no,
                'reason' => mb_strimwidth((string) $a->reason, 0, 60, '...'),
                'when' => $a->created_at ? $a->created_at->toDateTimeString() : '',
            ];
        })->all();

        $this->table(['ID', 'Conv', 'Thread', 'User', 'Status', 'Try', 'Reason', 'Created'], $rows);
        $this->info(sprintf('%d offene Fehlversuche.', $attempts->count()));

        return 0;
    }

    private function parseSince(string $since): Carbon
    {
        if (preg_match('/^(\d+)\s*([hd])$/i', trim($since), $m)) {
            $n = (int) $m[1];
            return strtolower($m[2]) === 'h' ? Carbon::now()->subHours($n) : Carbon::now()->subDays($n);
        }
        return Carbon::now()->subDays(30);
    }
}

<?php

namespace Modules\AmeiseModule\Console\Commands;

use App\Conversation;
use App\Thread;
use App\User;
use Illuminate\Console\Command;
use Modules\AmeiseModule\Entities\CrmArchiveAttempt;
use Modules\AmeiseModule\Jobs\ArchiveThreadsJob;

class RetryFailedArchives extends Command
{
    protected $signature = 'ameise:retry-failed-archives
        {--id=* : crm_archive_attempts.id, mehrfach erlaubt}
        {--conversation=* : conversation_id, mehrfach erlaubt}
        {--all : Alle offenen Fehlversuche erneut anstossen}';

    protected $description = 'Setzt fehlgeschlagene Archivierungen erneut in die Queue (ArchiveThreadsJob)';

    public function handle()
    {
        $ids = $this->option('id');
        $conversationIds = $this->option('conversation');
        $all = (bool) $this->option('all');

        if (!$ids && !$conversationIds && !$all) {
            $this->error('Bitte --id, --conversation oder --all angeben.');
            return 1;
        }

        $query = CrmArchiveAttempt::whereNull('resolved_at')
            ->whereIn('status', CrmArchiveAttempt::FAILURE_STATUSES);
        if ($ids) {
            $query->whereIn('id', $ids);
        } elseif ($conversationIds) {
            $query->whereIn('conversation_id', $conversationIds);
        }

        $attempts = $query->get();
        if ($attempts->isEmpty()) {
            $this->info('Keine passenden Fehlversuche gefunden.');
            return 0;
        }

        $dispatched = 0;
        $seen = [];
        foreach ($attempts as $attempt) {
            $key = $attempt->thread_id . '-' . $attempt->user_id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $thread = Thread::find($attempt->thread_id);
            $conversation = Conversation::find($attempt->conversation_id);
            $user = User::find($attempt->user_id);
            if (!$thread || !$conversation || !$user) {
                $this->warn(sprintf('Skip attempt %d: thread/conversation/user fehlt', $attempt->id));
                continue;
            }

            ArchiveThreadsJob::dispatch($conversation, $thread, $user);
            CrmArchiveAttempt::record([
                'conversation_id' => $conversation->id,
                'thread_id' => $thread->id,
                'user_id' => $user->id,
                'status' => CrmArchiveAttempt::STATUS_PENDING,
                'reason' => 'Manuell neu eingereiht via CLI',
            ]);
            $dispatched++;
        }

        $this->info(sprintf('%d Job(s) erneut eingereiht.', $dispatched));
        return 0;
    }
}

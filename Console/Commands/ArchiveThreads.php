<?php

namespace Modules\AmeiseModule\Console\Commands;

use App\Thread;
use App\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\AmeiseModule\Jobs\ArchiveThreadsJob;

class ArchiveThreads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ameise:archive-threads';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archiviere neue Threads ins CRM für alle Nutzer, die die Konversation archiviert haben';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // (archive_id, thread_id)-Paare, für die noch kein erfolgreicher Archivierungseintrag existiert.
        $pendingPairs = DB::table('crm_archives')
            ->join('threads', function ($join) {
                $join->on('threads.conversation_id', '=', 'crm_archives.conversation_id')
                    ->where('threads.state', '=', Thread::STATE_PUBLISHED);
            })
            ->leftJoin('crm_archive_threads', function ($join) {
                $join->on('crm_archive_threads.crm_archive_id', '=', 'crm_archives.id')
                    ->on('crm_archive_threads.thread_id', '=', 'threads.id');
            })
            ->whereNotNull('crm_archives.archived_by')
            ->whereNull('crm_archive_threads.archived_at')
            ->select('crm_archives.id as archive_id', 'crm_archives.archived_by', 'threads.id as thread_id')
            ->get();

        if ($pendingPairs->isEmpty()) {
            return;
        }

        $users = User::whereIn('id', $pendingPairs->pluck('archived_by')->unique())->get()->keyBy('id');
        $threads = Thread::with(['conversation', 'attachments'])
            ->whereIn('id', $pendingPairs->pluck('thread_id')->unique())
            ->get()
            ->keyBy('id');

        foreach ($pendingPairs as $pair) {
            $user = $users->get($pair->archived_by);
            $thread = $threads->get($pair->thread_id);
            if (!$user || !$thread || !$thread->conversation) {
                continue;
            }

            ArchiveThreadsJob::dispatch($thread->conversation, $thread, $user);
        }
    }
}

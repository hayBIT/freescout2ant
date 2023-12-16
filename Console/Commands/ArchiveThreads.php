<?php
namespace Modules\AmeiseModule\Console\Commands;

use App\Thread;
use App\User;
use Illuminate\Console\Command;
use Modules\AmeiseModule\Entities\CrmArchive;
use Modules\AmeiseModule\Entities\CrmArchiveThread;
use Modules\AmeiseModule\Jobs\ArcheiveThreads;

class ArchiveThreads extends Command {
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
    protected $description = 'Run commands to archive threads';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
      $crmArchiveThreads = CrmArchiveThread::where('created_at','>=', now()->subDays(60))
      ->get();
      $convesation_ids = $crmArchiveThreads->pluck('conversation_id')->toArray();
      $thread_ids = $crmArchiveThreads->pluck('thread_id')->toArray();
      $threads = Thread::where('threads.created_at', '>=', now()->subDays(60))
      ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_CUSTOMER])
      ->where('state', Thread::STATE_PUBLISHED)
        ->whereNotIn('threads.id', $thread_ids)
        ->whereIn('threads.conversation_id', $convesation_ids)
        ->with(['conversation', 'attachments'])->get();
      foreach ($threads as $thread) {
        $archives = CrmArchive::where('conversation_id', $thread->conversation_id)
        ->groupBy('archived_by')->pluck('archived_by')->toArray();
        $users = User::whereIn('id', $archives)->get();
        foreach ($users as $user) {
          ArcheiveThreads::dispatch($thread->conversation, $thread, $user);
        }
      }
               
    }
}

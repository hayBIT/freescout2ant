<?php
namespace Modules\AmeiseModule\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ArchiveThreadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $thread;
    protected $conversation;
    protected $user;
    public $timeout = 120;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($conversation, $thread, $user)
    {
        $this->conversation = $conversation;
        $this->thread = $thread;
        $this->user = $user;
    }
   
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
      config('ameisemodule.ameise_log_status') && \Helper::log('Ameise Cron Log', 'Job Dispatched For Thread ID: '.$this->thread->id.' Conversation ID: '.$this->conversation->id.' User ID: '.$this->user->id.'');

      $tokenService = new \Modules\AmeiseModule\Services\TokenService('', $this->user->id);
      $apiClient = new \Modules\AmeiseModule\Services\CrmApiClient($tokenService);
      $archiver = new \Modules\AmeiseModule\Services\ConversationArchiver($apiClient);
      $archiver->archiveConversationData($this->conversation, $this->thread, $this->user);
    }
}

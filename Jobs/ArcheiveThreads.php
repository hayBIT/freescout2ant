<?php
namespace Modules\AmeiseModule\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ArcheiveThreads implements ShouldQueue
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
      $crmService = new \Modules\AmeiseModule\Services\CrmService('', $this->user->id);
      $crmService->archiveConversationData($this->conversation, $this->thread, $this->user);
    }
}

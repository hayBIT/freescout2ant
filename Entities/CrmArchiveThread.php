<?php

namespace Modules\AmeiseModule\Entities;

use Illuminate\Database\Eloquent\Model;
use App\Conversation;
use App\Thread;


class CrmArchiveThread extends Model
{
    protected $guarded = ['id'];
    protected $table = 'crm_archive_threads';
    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function thread()
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    //
}

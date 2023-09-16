<?php

namespace Modules\AmeiseModule\Entities;

use Illuminate\Database\Eloquent\Model;
use App\Conversation;

class CrmArchive extends Model
{
    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    //
}

<?php

namespace Modules\AmeiseModule\Entities;

use Illuminate\Database\Eloquent\Model;
use App\Conversation;


class CrmArchive extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'auto_assigned' => 'boolean',
        'confirmed_at'  => 'datetime',
    ];

    /**
     * Automatisch zugeordnet und noch von niemandem geprüft.
     */
    public function needsReview()
    {
        return $this->auto_assigned && is_null($this->confirmed_at);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    //
}

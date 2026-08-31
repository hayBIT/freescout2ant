<?php

namespace Modules\AmeiseModule\Entities;

use App\Conversation;
use App\Thread;
use Illuminate\Database\Eloquent\Model;

/**
 * Ein einzelner Archiveintrag der Ameise, gespiegelt in FreeScout.
 */
class CrmArchiveEntry extends Model
{
    const KIND_THREAD = 'thread';
    const KIND_ATTACHMENT = 'attachment';

    /** Noch keine UUID ermittelt, Auflösung steht aus. */
    const STATE_PENDING = 'pending';
    /** UUID bekannt, Eintrag ist bearbeitbar. */
    const STATE_OK = 'ok';
    /** Nicht eindeutig zuzuordnen — sichtbar, aber nicht bearbeitbar. */
    const STATE_UNMAPPED = 'unmapped';
    /** Lokaler Spiegel und Ameise gehen auseinander. */
    const STATE_CONFLICT = 'conflict';
    /** In der Ameise nicht mehr auffindbar. */
    const STATE_MISSING = 'missing';

    protected $table = 'crm_archive_entries';
    protected $guarded = ['id'];

    protected $casts = [
        'contracts' => 'array',
        'contract_lines' => 'array',
        'tags' => 'array',
        'is_public' => 'boolean',
        'requires_review' => 'boolean',
        'is_deleted' => 'boolean',
    ];

    protected $dates = ['entry_date', 'remote_synced_at'];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function thread()
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    public function archive()
    {
        return $this->belongsTo(CrmArchive::class, 'crm_archive_id');
    }

    /**
     * Nur ein Eintrag mit bekannter UUID lässt sich in der Ameise ändern.
     */
    public function isEditable(): bool
    {
        return !empty($this->archive_entry_id) && $this->sync_state !== self::STATE_MISSING;
    }
}

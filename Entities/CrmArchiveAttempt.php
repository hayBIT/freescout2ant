<?php

namespace Modules\AmeiseModule\Entities;

use App\Conversation;
use App\Thread;
use App\User;
use Illuminate\Database\Eloquent\Model;

class CrmArchiveAttempt extends Model
{
    const STATUS_SUCCESS = 'success';
    const STATUS_PENDING = 'pending';
    const STATUS_FAILED_NO_CUSTOMER = 'failed_no_customer';
    const STATUS_FAILED_AMBIGUOUS_CUSTOMER = 'failed_ambiguous_customer';
    const STATUS_FAILED_API = 'failed_api';
    const STATUS_FAILED_TOKEN = 'failed_token';
    const STATUS_FAILED_ATTACHMENT = 'failed_attachment';
    const STATUS_FAILED_EXCEPTION = 'failed_exception';

    const FAILURE_STATUSES = [
        self::STATUS_FAILED_NO_CUSTOMER,
        self::STATUS_FAILED_AMBIGUOUS_CUSTOMER,
        self::STATUS_FAILED_API,
        self::STATUS_FAILED_TOKEN,
        self::STATUS_FAILED_ATTACHMENT,
        self::STATUS_FAILED_EXCEPTION,
    ];

    protected $table = 'crm_archive_attempts';
    protected $guarded = ['id'];
    protected $dates = ['next_retry_at', 'resolved_at', 'created_at', 'updated_at'];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function thread()
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function record(array $data): self
    {
        $threadId = $data['thread_id'] ?? null;
        $userId = $data['user_id'] ?? null;

        $maxAttempt = static::where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->max('attempt_no');

        $data['attempt_no'] = (int) ($maxAttempt ?? 0) + 1;

        $attempt = static::create($data);

        if (($data['status'] ?? null) === self::STATUS_SUCCESS) {
            static::where('thread_id', $threadId)
                ->where('user_id', $userId)
                ->whereNull('resolved_at')
                ->where('id', '!=', $attempt->id)
                ->update(['resolved_at' => now()]);
        }

        return $attempt;
    }
}

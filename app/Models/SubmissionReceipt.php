<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionReceipt extends Model
{
    protected $fillable = [
        'user_id', 'task_assignment_id', 'task_submission_id',
        'idempotency_key', 'receipt_id', 'payload_sha256',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TaskSubmission::class, 'task_submission_id');
    }
}

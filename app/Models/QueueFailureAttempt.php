<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class QueueFailureAttempt extends Model
{
    protected $table = 'queue_failure_attempts';

    protected $fillable = [
        'job_uuid',
        'connection',
        'queue',
        'job_class',
        'exception_class',
        'exception_message',
        'exception_code',
        'exception_file',
        'exception_line',
        'exception_trace',
        'exception_chain',
        'job_payload',
        'attempt',
        'event_type',
        'occurred_at',
    ];

    protected $casts = [
        'exception_chain' => 'array',
        'job_payload' => 'array',
        'exception_line' => 'integer',
        'attempt' => 'integer',
        'occurred_at' => 'datetime',
    ];
}

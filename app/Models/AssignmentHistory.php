<?php

namespace App\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AssignmentHistory extends Model
{
    use HasUuid7;

    protected $table = 'assignment_history';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'from_user_id',
        'to_user_id',
        'actor_id',
        'action',
        'channel',
        'reason_code',
        'reason_note',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

final class ChannelOperationAttempt extends Model
{
    use HasUuid7;

    public const STATUS_SENDING = 'sending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_UNCERTAIN = 'uncertain';

    public const STATUS_RETRYABLE = 'retryable';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'channel_operation_attempts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'uncertain_at' => 'datetime',
            'last_response' => 'array',
        ];
    }
}

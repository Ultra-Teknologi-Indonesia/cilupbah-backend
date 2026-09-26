<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ShippingLabelCacheArtifact extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'attempts' => 'integer', 'next_attempt_at' => 'datetime',
            'archived_at' => 'datetime', 'local_deleted_at' => 'datetime'];
    }
}

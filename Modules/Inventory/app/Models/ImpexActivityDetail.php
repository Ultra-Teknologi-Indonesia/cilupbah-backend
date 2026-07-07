<?php

namespace Modules\Inventory\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImpexActivityDetail extends Model
{
    use HasUuid7;

    public $timestamps = false;

    protected $fillable = [
        'impex_activity_id',
        'reference_id',
        'description',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ImpexActivity::class, 'impex_activity_id');
    }
}

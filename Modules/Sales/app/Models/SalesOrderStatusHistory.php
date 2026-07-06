<?php

namespace Modules\Sales\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderStatusHistory extends Model
{
    use HasUuid7;

    protected $table = 'sales_order_status_histories';

    public $timestamps = false;

    protected $fillable = [
        'salesorder_id',
        'action_id',
        'action',
        'actor_email',
        'actor_id',
        'actor_name',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'salesorder_id');
    }
}

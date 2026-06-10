<?php

namespace Modules\Outbound\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasUuid7;

class ShipmentOrder extends Model
{
    use HasUuid7;

    protected $fillable = [
        'shipment_id',
        'order_id',
        'packlist_id',
        'tracking_number',
        'qty_given',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrder::class, 'order_id');
    }

    public function packlist(): BelongsTo
    {
        return $this->belongsTo(Packlist::class);
    }
}

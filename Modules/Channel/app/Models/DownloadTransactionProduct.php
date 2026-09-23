<?php

namespace Modules\Channel\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Models\Product;

class DownloadTransactionProduct extends Model
{
    use HasUuid7;

    protected $fillable = [
        'download_transaction_id',
        'product_id',
        'external_product_id',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(DownloadTransaction::class, 'download_transaction_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

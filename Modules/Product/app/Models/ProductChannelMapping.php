<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Channel\Models\ChannelShop;

class ProductChannelMapping extends Model
{
    use HasUuid7;

    public const STATUS_PENDING = 'pending';        
    public const STATUS_SYNCING = 'syncing';        
    public const STATUS_IN_REVIEW = 'in_review';    
    public const STATUS_SYNCED = 'synced';          
    public const STATUS_REJECTED = 'rejected';      
    public const STATUS_FAILED = 'failed';          
    public const STATUS_DEACTIVATED = 'deactivated';

    protected $fillable = [
        'product_id',
        'channel_shop_id',
        'external_product_id',
        'channel_attributes',
        'channel_url',
        'sync_status',
        'error_message',
        'last_synced_at',
        'reviewed_at',
    ];

    protected $casts = [
        'channel_attributes' => 'array',
        'last_synced_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function channelShop(): BelongsTo
    {
        return $this->belongsTo(ChannelShop::class);
    }

    public function variantMappings(): HasMany
    {
        return $this->hasMany(ProductVariantChannelMapping::class);
    }

    public function scopeSynced($query)
    {
        return $query->where('sync_status', 'synced');
    }

    public function scopeFailed($query)
    {
        return $query->where('sync_status', 'failed');
    }

    public function scopePending($query)
    {
        return $query->where('sync_status', 'pending');
    }

    public function scopeInReview($query)
    {
        return $query->where('sync_status', self::STATUS_IN_REVIEW);
    }

    public function scopeRejected($query)
    {
        return $query->where('sync_status', self::STATUS_REJECTED);
    }

    public function markAsSyncing(): void
    {
        $this->update([
            'sync_status' => 'syncing',
            'error_message' => null,
        ]);
    }

    public function markAsSynced(?string $externalProductId = null): void
    {
        $data = [
            'sync_status' => 'synced',
            'error_message' => null,
            'last_synced_at' => now(),
        ];

        if ($externalProductId !== null) {
            $data['external_product_id'] = $externalProductId;
        }

        $this->update($data);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'sync_status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    public function markInReview(?string $externalProductId = null): void
    {
        $data = [
            'sync_status' => self::STATUS_IN_REVIEW,
            'error_message' => null,
            'last_synced_at' => now(),
        ];

        if ($externalProductId !== null) {
            $data['external_product_id'] = $externalProductId;
        }

        $this->update($data);
    }

    public function markApproved(?string $externalProductId = null): void
    {
        $data = [
            'sync_status' => self::STATUS_SYNCED,
            'error_message' => null,
            'reviewed_at' => now(),
        ];

        if ($externalProductId !== null) {
            $data['external_product_id'] = $externalProductId;
        }

        $this->update($data);
    }

    public function markRejected(string $reason): void
    {
        $this->update([
            'sync_status' => self::STATUS_REJECTED,
            'error_message' => $reason,
            'reviewed_at' => now(),
        ]);
    }
}

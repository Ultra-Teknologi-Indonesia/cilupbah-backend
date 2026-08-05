<?php

namespace Modules\Channel\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class DownloadTransaction extends Model
{
    use HasUuid7;

    public const STATE_QUEUED = 'queued';
    public const STATE_DOWNLOADING = 'downloading';
    public const STATE_DONE = 'done';
    public const STATE_FAILED = 'failed';

    protected $fillable = [
        'trx_no',
        'channel_shop_id',
        'executed_by',
        'state',
        'all_product',
        'total_downloaded',
        'total_failed',
        'progress_percent',
        'error_message',
    ];

    protected $casts = [
        'all_product' => 'integer',
        'total_downloaded' => 'integer',
        'total_failed' => 'integer',
        'progress_percent' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (DownloadTransaction $transaction) {
            if (empty($transaction->trx_no)) {
                $next = DB::selectOne("SELECT nextval('download_transactions_trx_seq') AS n")->n;
                $transaction->trx_no = 'DWNLD-' . str_pad((string) $next, 7, '0', STR_PAD_LEFT);
            }
        });
    }

    public function channelShop(): BelongsTo
    {
        return $this->belongsTo(ChannelShop::class);
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'executed_by');
    }

    public function markDownloading(int $allProduct = 0): void
    {
        $this->update([
            'state' => self::STATE_DOWNLOADING,
            'all_product' => $allProduct,
        ]);
    }

    public function markDone(int $totalDownloaded, int $totalFailed = 0): void
    {
        $this->update([
            'state' => self::STATE_DONE,
            'total_downloaded' => $totalDownloaded,
            'total_failed' => $totalFailed,
            'all_product' => max($this->all_product, $totalDownloaded + $totalFailed),
            'progress_percent' => 100,
        ]);
    }

    public function updateProgress(int $downloaded, int $total, int $failed = 0): void
    {
        $percent = $total > 0 ? (int) round(($downloaded / $total) * 100) : 0;

        $this->update([
            'total_downloaded' => $downloaded,
            'total_failed' => $failed,
            'all_product' => $total,
            'progress_percent' => min($percent, 99),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'state' => self::STATE_FAILED,
            'error_message' => $message,
        ]);
    }
}

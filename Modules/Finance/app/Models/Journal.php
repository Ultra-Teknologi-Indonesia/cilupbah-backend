<?php

namespace Modules\Finance\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journal extends Model
{
    use HasUuid7;

    public const TYPE_MANUAL = 'Manual Jurnal'; 

    protected $fillable = [
        'journal_no',
        'transaction_date',
        'journal_type',
        'source_doc_type',
        'source_doc_id',
        'source_doc_no',
        'notes',
        'total_debit',
        'total_credit',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
        'total_debit' => 'decimal:4',
        'total_credit' => 'decimal:4',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(JournalDetail::class);
    }

    public function isManual(): bool
    {
        return $this->journal_type === self::TYPE_MANUAL;
    }
}

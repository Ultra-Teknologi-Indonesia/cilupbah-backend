<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingItem extends Model
{
    protected $fillable = [
        'domain', 'method', 'endpoint', 'function_id', 'cilupbah_impl',
        'status', 'baseline_status', 'pic', 'notes', 'priority', 'source', 'updated_by',
    ];

    public const STATUSES = ['done', 'in_progress', 'todo', 'blocked'];
    public const PICS = ['Darriel', 'Rasyid'];

    /** Normalisasi nilai status dari dokumen (partial => in_progress). */
    public static function normalizeStatus(?string $status): string
    {
        $status = $status === 'partial' ? 'in_progress' : (string) $status;

        return in_array($status, self::STATUSES, true) ? $status : 'todo';
    }
}

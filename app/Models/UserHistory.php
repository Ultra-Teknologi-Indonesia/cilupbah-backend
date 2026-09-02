<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid7;

class UserHistory extends Model
{
    use HasFactory, HasUuid7;

    protected $fillable = [
        'actor_id',
        'actor_id_snapshot',
        'actor_user_name',
        'actor_user_email',
        'target_user_id',
        'target_user_id_snapshot',
        'target_user_name',
        'target_user_email',
        'action',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}

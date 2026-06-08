<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class ProductMergeHidden extends Model
{
    use HasUuid7;

    protected $table = 'product_merge_hidden';

    protected $fillable = [
        'master_name',
    ];
}

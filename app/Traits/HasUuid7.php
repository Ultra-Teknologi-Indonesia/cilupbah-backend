<?php

namespace App\Traits;

use Ramsey\Uuid\Uuid;

trait HasUuid7
{
    /**
     * Boot the trait.
     */
    protected static function bootHasUuid7()
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = Uuid::uuid7()->getHex()->toString();
            }
        });
    }

    /**
     * Get the auto-incrementing key type.
     */
    public function getIncrementing()
    {
        return false;
    }

    /**
     * Get the data type for the primary key.
     */
    public function getKeyType()
    {
        return 'string';
    }
}

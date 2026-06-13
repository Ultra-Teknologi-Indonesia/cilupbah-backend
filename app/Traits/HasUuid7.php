<?php

namespace App\Traits;

use Ramsey\Uuid\Uuid;

trait HasUuid7
{

    protected static function bootHasUuid7()
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = Uuid::uuid7()->toString();
            }
        });
    }

    public function getIncrementing()
    {
        return false;
    }

    public function getKeyType()
    {
        return 'string';
    }
}

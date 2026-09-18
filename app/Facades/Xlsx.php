<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\XlsxRenderer;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class Xlsx extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return XlsxRenderer::class;
    }
}

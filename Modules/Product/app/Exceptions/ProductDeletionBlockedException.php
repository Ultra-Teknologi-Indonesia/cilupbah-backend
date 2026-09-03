<?php

declare(strict_types=1);

namespace Modules\Product\Exceptions;

use DomainException;

final class ProductDeletionBlockedException extends DomainException
{
    public function __construct(private readonly array $blockers)
    {
        parent::__construct('Satu atau beberapa produk belum memenuhi syarat untuk dihapus.');
    }

    public function blockers(): array
    {
        return $this->blockers;
    }
}

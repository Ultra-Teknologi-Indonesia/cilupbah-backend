<?php

namespace Modules\Product\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * B2 guard — a bundle component must not be a variant of another bundle
 * (bundle-in-bundle). App-level equivalent of a 23504 reference violation.
 */
class BundleCompositionException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(422, $message);
    }
}

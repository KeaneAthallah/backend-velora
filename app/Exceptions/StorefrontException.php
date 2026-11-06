<?php

namespace App\Exceptions;

use RuntimeException;

class StorefrontException extends RuntimeException
{
    public function __construct(string $message, public string $errorCode = 'STOREFRONT_ERROR', public array $errors = [])
    {
        parent::__construct($message);
    }
}

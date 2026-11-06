<?php

namespace App\Exceptions;

use RuntimeException;

class DeliveryException extends RuntimeException
{
    public function __construct(string $message, public string $errorCode = 'DELIVERY_ERROR', public array $errors = [])
    {
        parent::__construct($message);
    }
}

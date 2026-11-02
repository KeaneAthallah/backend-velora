<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A subscription problem that should reach the client as a structured
 * envelope rather than a generic 500. The renderer in bootstrap/app.php
 * turns it into `{success:false, code, errors, meta}`.
 */
class SubscriptionException extends RuntimeException
{
    public function __construct(
        string $message,
        public string $errorCode = 'SUBSCRIPTION_ERROR',
        public int $status = 402,
        public array $details = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => $this->details,
            'code' => $this->errorCode,
            'request_id' => (string) Str::uuid(),
        ], $this->status);
    }
}

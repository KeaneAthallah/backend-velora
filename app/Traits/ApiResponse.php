<?php

namespace App\Traits;

trait ApiResponse
{
    protected function success($data = null, string $message = 'Operation completed successfully', int $status = 200, array $meta = [])
    {
        $payload = ['success' => true, 'message' => $message, 'data' => $data];
        if (! empty($meta)) {
            $payload['meta'] = $meta;
        }
        return response()->json($payload, $status);
    }

    protected function error(string $message, int $status = 400, array $errors = [], string $code = 'ERROR', ?string $requestId = null)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'code' => $code,
            'request_id' => $requestId ?? (string) \Illuminate\Support\Str::uuid(),
        ], $status);
    }
}

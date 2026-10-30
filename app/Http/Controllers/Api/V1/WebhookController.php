<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function payment(Request $request, $provider)
    {
        // Verify signature placeholder; real implementation depends on provider
        $data = $request->all();
        $externalId = $data['external_id'] ?? null;
        if ($externalId) {
            $payment = Payment::where('external_id', $externalId)->first();
            if ($payment) {
                // dedup: already processed
                return $this->success(['status' => 'already_processed']);
            }
        }

        return $this->success(['status' => 'received']);
    }
}

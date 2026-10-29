<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentService
{
    /**
     * Record one or more payments against a completed sale. Split payments are
     * supported: the batch is accepted when it covers the remaining total, and
     * any overpayment is returned as change from the cash tendered.
     *
     * The sale row is updated in the same transaction so paid_amount and
     * change_amount always reflect the payment ledger.
     *
     * @param  array<int, array<string, mixed>>  $payments
     * @return array{payments: Collection<int, Payment>, paid: string, change: string}
     */
    public function recordPayments(Sale $sale, array $payments, ?string $idempotencyKey = null): array
    {
        // Lock the sale for the whole billing decision so two cashiers (or a
        // retried request) cannot both see the same remaining balance and
        // overpay. The idempotency lookup also runs under this lock, so a
        // duplicate submission returns the rows the first one created.
        return DB::transaction(function () use ($sale, $payments, $idempotencyKey) {
            $original = $sale;
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if (! in_array($sale->status, ['completed', 'partially_refunded'], true)) {
                throw new RuntimeException('Only completed sales can take payments');
            }
            if ($payments === []) {
                throw new RuntimeException('Add at least one payment');
            }

            PaymentMethod::ensureDefaults($sale->business_id);
            $methods = PaymentMethod::where('business_id', $sale->business_id)->get()->keyBy('code');

            if ($idempotencyKey) {
                $existing = Payment::where('sale_id', $sale->id)
                    ->where('idempotency_key', 'like', $idempotencyKey.':%')
                    ->orderBy('id')
                    ->get();
                if ($existing->isNotEmpty()) {
                    return [
                        'payments' => $existing,
                        'paid' => (string) $sale->paid_amount,
                        'change' => (string) $sale->change_amount,
                    ];
                }
            }

            $prepared = [];
            $tendered = '0.0000';

            foreach (array_values($payments) as $index => $entry) {
                $code = strtolower(trim((string) ($entry['method'] ?? '')));
                $method = $methods->get($code);
                if (! $method || ! $method->is_active) {
                    throw new RuntimeException(
                        'Unknown or inactive payment method: '.($code !== '' ? $code : 'empty')
                    );
                }

                $amount = $this->money($entry['amount'] ?? 0);
                if (bccomp($amount, '0', 4) <= 0) {
                    throw new RuntimeException('Payment amounts must be greater than zero');
                }

                $prepared[] = [
                    'business_id' => $sale->business_id,
                    'sale_id' => $sale->id,
                    'payment_method_id' => $method->id,
                    'method' => $code,
                    'amount' => $amount,
                    'status' => 'completed',
                    'reference' => $entry['reference'] ?? null,
                    'provider' => $entry['provider'] ?? null,
                    'external_id' => $entry['external_id'] ?? null,
                    'idempotency_key' => $idempotencyKey ? $idempotencyKey.':'.$index : null,
                    'paid_at' => now(),
                ];

                $tendered = bcadd($tendered, $amount, 4);
            }

            $existingPaid = $this->paidTotal($sale);
            $total = $this->money($sale->total);
            if (bccomp($existingPaid, $total, 4) >= 0) {
                throw new RuntimeException('This sale is already fully paid');
            }

            $paid = bcadd($existingPaid, $tendered, 4);

            if (bccomp($paid, $total, 4) < 0) {
                throw new RuntimeException('Payment does not cover the total');
            }

            $change = bccomp($paid, $total, 4) > 0 ? bcsub($paid, $total, 4) : '0.0000';

            if (bccomp($change, '0', 4) > 0) {
                $cashCodes = $methods->where('type', 'cash')->pluck('code')->all();
                if ($cashCodes === []) {
                    throw new RuntimeException('Overpayment needs a cash payment method');
                }

                $cash = '0.0000';
                $existing = Payment::where('sale_id', $sale->id)->where('status', 'completed')->get(['method', 'amount']);
                foreach ($existing as $row) {
                    if (in_array($row->method, $cashCodes, true)) {
                        $cash = bcadd($cash, $this->money($row->amount), 4);
                    }
                }
                foreach ($prepared as $row) {
                    if (in_array($row['method'], $cashCodes, true)) {
                        $cash = bcadd($cash, $row['amount'], 4);
                    }
                }

                if (bccomp($change, $cash, 4) > 0) {
                    throw new RuntimeException('Change cannot exceed the cash tendered');
                }
            }

            $created = collect();
            foreach ($prepared as $row) {
                $created->push(Payment::create($row));
            }

            $sale->paid_amount = $paid;
            $sale->change_amount = $change;
            $sale->save();

            // Keep the caller's instance in sync with the committed row.
            $original->setRawAttributes($sale->getAttributes(), true);

            return [
                'payments' => $created,
                'paid' => $paid,
                'change' => $change,
            ];
        });
    }

    /** Completed payments already recorded against the sale. */
    public function paidTotal(Sale $sale): string
    {
        $paid = '0.0000';
        foreach ($sale->payments()->where('status', 'completed')->pluck('amount') as $amount) {
            $paid = bcadd($paid, $this->money($amount), 4);
        }

        return $paid;
    }

    private function money(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 4);
    }
}

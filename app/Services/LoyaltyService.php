<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\Sale;
use RuntimeException;

/**
 * Loyalty points for a single programme per business.
 *
 * Points are earned when a sale is completed and redeemed when the cashier
 * types the points to spend. The ledger on `loyalty_transactions` mirrors
 * `customers.loyalty_points`, so the balance can always be reconstructed.
 */
class LoyaltyService
{
    /**
     * The programme is provisioned with defaults the first time it is needed,
     * the same way payment methods are, so loyalty works out of the box.
     */
    public function program(int $businessId): LoyaltyProgram
    {
        return LoyaltyProgram::firstOrCreate(
            ['business_id' => $businessId],
            [
                'is_active' => true,
                'points_per_amount' => '0.0001',
                'redeem_value' => '100',
                'min_redeem_points' => '10',
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProgram(int $businessId, array $data): LoyaltyProgram
    {
        $program = LoyaltyProgram::firstOrNew(['business_id' => $businessId]);
        $program->fill($data);
        $program->business_id = $businessId;
        $program->save();

        return $program;
    }

    public function pointsFor(LoyaltyProgram $program, string $amount): string
    {
        if (bccomp($amount, '0', 4) !== 1) {
            return '0';
        }

        $points = bcmul($amount, (string) $program->points_per_amount, 4);

        return bccomp($points, '0', 4) === 1 ? $points : '0';
    }

    /**
     * Validate a redemption the cashier typed and convert it to money.
     *
     * Nothing is written here: the balance is only touched when the sale is
     * completed, so abandoned carts and open tabs never hold points.
     *
     * @return array{discount: string, points: string}
     */
    public function reserveRedemption(int $businessId, ?int $customerId, string $points, string $available): array
    {
        $points = bcadd($points, '0', 4);

        if (bccomp($points, '0', 4) !== 1) {
            return ['discount' => '0', 'points' => '0'];
        }

        if (! $customerId) {
            throw new RuntimeException('Select a customer before redeeming points');
        }

        $program = $this->program($businessId);
        if (! $program->is_active) {
            throw new RuntimeException('This business does not run a loyalty programme');
        }

        if (bccomp($points, (string) $program->min_redeem_points, 4) === -1) {
            throw new RuntimeException('Redeem at least '.$program->min_redeem_points.' points');
        }

        $customer = Customer::where('business_id', $businessId)->find($customerId);
        if (! $customer) {
            throw new RuntimeException('Customer not found');
        }

        if (bccomp($points, (string) $customer->loyalty_points, 4) === 1) {
            throw new RuntimeException('The customer only has '.$customer->loyalty_points.' points');
        }

        $discount = bcmul($points, (string) $program->redeem_value, 4);

        if (bccomp($discount, $available, 4) === 1) {
            throw new RuntimeException('Those points are worth more than the order total');
        }

        return ['discount' => $discount, 'points' => $points];
    }

    /**
     * Spend the points a sale was priced with. Called while completing a sale.
     */
    public function fulfilRedemption(Sale $sale): void
    {
        $points = (string) $sale->loyalty_points_redeemed;

        if (bccomp($points, '0', 4) !== 1) {
            return;
        }

        if (! $sale->customer_id) {
            throw new RuntimeException('This sale redeems points without a customer');
        }

        $customer = $this->lockCustomer($sale->business_id, $sale->customer_id);
        if (! $customer) {
            throw new RuntimeException('Customer not found');
        }

        if (bccomp((string) $customer->loyalty_points, $points, 4) === -1) {
            throw new RuntimeException('The customer does not have enough points for this redemption');
        }

        $customer->loyalty_points = bcsub((string) $customer->loyalty_points, $points, 4);
        $this->record($customer, $sale, 'redeem', bcmul($points, '-1', 4), 'Redeemed on '.$sale->receipt_number);
        $customer->save();
    }

    /**
     * Credit points, spend counters and lifetime value for a completed sale.
     */
    public function earn(Sale $sale): void
    {
        if (! $sale->customer_id) {
            return;
        }

        $customer = $this->lockCustomer($sale->business_id, $sale->customer_id);
        if (! $customer) {
            return;
        }

        // Spend counters move on every visit, even with earning switched off.
        $program = $this->program($sale->business_id);
        $points = $program->is_active ? $this->pointsFor($program, (string) $sale->total) : '0';

        if (bccomp($points, '0', 4) === 1) {
            $customer->loyalty_points = bcadd((string) $customer->loyalty_points, $points, 4);
            $customer->loyalty_joined_at = $customer->loyalty_joined_at ?? now();
            $this->record($customer, $sale, 'earn', $points, 'Earned on '.$sale->receipt_number);
            $sale->loyalty_points_earned = $points;
            $sale->save();
        }

        $customer->total_spent = bcadd((string) $customer->total_spent, (string) $sale->total, 4);
        $customer->visits_count = (int) $customer->visits_count + 1;
        $customer->last_visit_at = now();
        $customer->save();
    }

    /**
     * Put points and spend counters back when a completed sale is voided.
     */
    public function reverseForSale(Sale $sale, string $reason): void
    {
        if (! $sale->customer_id) {
            return;
        }

        $customer = $this->lockCustomer($sale->business_id, $sale->customer_id);
        if (! $customer) {
            return;
        }

        $earned = (string) $sale->loyalty_points_earned;
        if (bccomp($earned, '0', 4) === 1) {
            $deduct = bccomp((string) $customer->loyalty_points, $earned, 4) === -1
                ? (string) $customer->loyalty_points
                : $earned;

            if (bccomp($deduct, '0', 4) === 1) {
                $customer->loyalty_points = bcsub((string) $customer->loyalty_points, $deduct, 4);
                $this->record($customer, $sale, 'reverse', bcmul($deduct, '-1', 4), 'Void: '.$reason);
            }
        }

        $redeemed = (string) $sale->loyalty_points_redeemed;
        if (bccomp($redeemed, '0', 4) === 1) {
            $customer->loyalty_points = bcadd((string) $customer->loyalty_points, $redeemed, 4);
            $this->record($customer, $sale, 'reverse', $redeemed, 'Redeemed points returned on void');
        }

        $customer->total_spent = bcsub((string) $customer->total_spent, (string) $sale->total, 4);
        if (bccomp((string) $customer->total_spent, '0', 4) === -1) {
            $customer->total_spent = '0';
        }
        $customer->visits_count = max(0, (int) $customer->visits_count - 1);
        $customer->save();
    }

    /**
     * Manual correction by a manager. Negative points take points away.
     */
    public function adjust(Customer $customer, string $points, string $reason, ?int $actorId = null): Customer
    {
        $delta = bcadd($points, '0', 4);

        if (bccomp($delta, '0', 4) === 0) {
            throw new RuntimeException('Enter a non-zero points adjustment');
        }

        $balance = bcadd((string) $customer->loyalty_points, $delta, 4);
        if (bccomp($balance, '0', 4) === -1) {
            throw new RuntimeException('The customer only has '.$customer->loyalty_points.' points');
        }

        $customer->loyalty_points = $balance;
        if ($customer->loyalty_joined_at === null && bccomp($balance, '0', 4) === 1) {
            $customer->loyalty_joined_at = now();
        }
        $customer->save();

        LoyaltyTransaction::create([
            'business_id' => $customer->business_id,
            'customer_id' => $customer->id,
            'sale_id' => null,
            'type' => 'adjust',
            'points' => $delta,
            'balance_after' => $customer->loyalty_points,
            'reason' => $reason,
            'actor_id' => $actorId,
        ]);

        return $customer;
    }

    private function record(Customer $customer, Sale $sale, string $type, string $points, string $reason): void
    {
        LoyaltyTransaction::create([
            'business_id' => $customer->business_id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'type' => $type,
            'points' => $points,
            'balance_after' => $customer->loyalty_points,
            'reason' => $reason,
        ]);
    }

    private function lockCustomer(int $businessId, int $customerId): ?Customer
    {
        return Customer::where('business_id', $businessId)->lockForUpdate()->find($customerId);
    }
}

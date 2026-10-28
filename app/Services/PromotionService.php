<?php

namespace App\Services;

use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\Sale;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Promotions are priced on the server only. A promotion can carry a code the
 * cashier types at the register, or no code at all, in which case the best
 * qualifying promotion is applied automatically.
 */
class PromotionService
{
    /**
     * Resolve the promotion for a cart and return its discount.
     *
     * @return array{promotion: Promotion|null, discount: string}
     */
    public function resolve(
        int $businessId,
        ?string $code,
        ?int $customerId,
        string $subtotal,
        string $lineDiscountTotal = '0',
        bool $allowAutomatic = true,
    ): array {
        $code = $code !== null ? trim($code) : null;

        if ($code !== null && $code !== '') {
            $promotion = Promotion::where('business_id', $businessId)
                ->live()
                ->where('code', strtoupper($code))
                ->first();

            if (! $promotion) {
                throw new RuntimeException("Promotion code \"{$code}\" is not valid for this business");
            }

            return [
                'promotion' => $promotion,
                'discount' => $this->evaluate($promotion, $subtotal, $lineDiscountTotal, $customerId),
            ];
        }

        if (! $allowAutomatic) {
            return ['promotion' => null, 'discount' => '0'];
        }

        /** @var Collection<int, Promotion> $candidates */
        $candidates = Promotion::where('business_id', $businessId)
            ->live()
            ->automatic()
            ->get();

        $best = null;
        $bestDiscount = '0';

        foreach ($candidates as $promotion) {
            try {
                $discount = $this->evaluate($promotion, $subtotal, $lineDiscountTotal, $customerId);
            } catch (RuntimeException) {
                continue;
            }

            if (bccomp($discount, $bestDiscount, 4) === 1) {
                $best = $promotion;
                $bestDiscount = $discount;
            }
        }

        return ['promotion' => $best, 'discount' => $bestDiscount];
    }

    /**
     * Discount a promotion yields for a cart, or the reason it does not apply.
     *
     * Automatically selected promotions swallow the exception; a code the
     * cashier typed surfaces it to the register.
     */
    public function evaluate(
        Promotion $promotion,
        string $subtotal,
        string $lineDiscountTotal,
        ?int $customerId,
    ): string {
        $net = bcsub($subtotal, $lineDiscountTotal, 4);

        if ($promotion->min_spend !== null && bccomp($subtotal, (string) $promotion->min_spend, 4) === -1) {
            throw new RuntimeException('This promotion needs a minimum spend of '.$promotion->min_spend);
        }

        if ($promotion->usage_limit !== null && $promotion->usage_count >= $promotion->usage_limit) {
            throw new RuntimeException('This promotion has reached its usage limit');
        }

        if ($promotion->per_customer_limit !== null) {
            if (! $customerId) {
                throw new RuntimeException('Select a customer to use this promotion');
            }

            $used = PromotionRedemption::where('promotion_id', $promotion->id)
                ->where('customer_id', $customerId)
                ->count();

            if ($used >= $promotion->per_customer_limit) {
                throw new RuntimeException('This customer has already used this promotion');
            }
        }

        if (bccomp($net, '0', 4) !== 1) {
            return '0';
        }

        $discount = $promotion->type === 'percentage'
            ? bcdiv(bcmul($net, (string) $promotion->value, 8), '100', 4)
            : (string) $promotion->value;

        if ($promotion->max_discount !== null && bccomp($discount, (string) $promotion->max_discount, 4) === 1) {
            $discount = (string) $promotion->max_discount;
        }

        if (bccomp($discount, $net, 4) === 1) {
            $discount = $net;
        }

        if (bccomp($discount, '0', 4) !== 1) {
            return '0';
        }

        return $discount;
    }

    /**
     * Persist the redemption once the sale is finalised and count the usage.
     *
     * Re-checks the limits because an open order can sit at the table for a
     * while before it is paid for.
     */
    public function recordRedemption(Sale $sale): void
    {
        if (! $sale->promotion_id || bccomp((string) $sale->promotion_discount, '0', 4) !== 1) {
            return;
        }

        $promotion = Promotion::where('business_id', $sale->business_id)->find($sale->promotion_id);
        if (! $promotion) {
            return;
        }

        if ($promotion->usage_limit !== null && $promotion->usage_count >= $promotion->usage_limit) {
            throw new RuntimeException('This promotion has reached its usage limit');
        }

        if ($promotion->per_customer_limit !== null && $sale->customer_id) {
            $used = PromotionRedemption::where('promotion_id', $promotion->id)
                ->where('customer_id', $sale->customer_id)
                ->count();

            if ($used >= $promotion->per_customer_limit) {
                throw new RuntimeException('This customer has already used this promotion');
            }
        }

        PromotionRedemption::create([
            'business_id' => $sale->business_id,
            'promotion_id' => $promotion->id,
            'sale_id' => $sale->id,
            'customer_id' => $sale->customer_id,
            'code' => $promotion->code,
            'amount' => $sale->promotion_discount,
        ]);

        $promotion->increment('usage_count');
    }

    /**
     * Voiding a sale gives the promotion usage back.
     */
    public function releaseRedemption(Sale $sale): void
    {
        $redemption = PromotionRedemption::where('sale_id', $sale->id)->first();
        if (! $redemption) {
            return;
        }

        $promotion = Promotion::where('business_id', $sale->business_id)->find($redemption->promotion_id);
        if ($promotion && $promotion->usage_count > 0) {
            $promotion->decrement('usage_count');
        }

        $redemption->delete();
    }
}

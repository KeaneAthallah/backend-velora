<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    public const TYPES = ['cash', 'qris', 'debit_card', 'credit_card', 'ewallet', 'transfer', 'other'];

    /**
     * Default rails provisioned for every business.
     *
     * @var array<int, array<string, mixed>>
     */
    public const DEFAULTS = [
        ['code' => 'cash', 'name' => 'Cash', 'type' => 'cash', 'sort_order' => 1],
        ['code' => 'qris', 'name' => 'QRIS', 'type' => 'qris', 'sort_order' => 2],
        ['code' => 'debit_card', 'name' => 'Debit Card', 'type' => 'debit_card', 'requires_reference' => true, 'sort_order' => 3],
        ['code' => 'credit_card', 'name' => 'Credit Card', 'type' => 'credit_card', 'requires_reference' => true, 'sort_order' => 4],
        ['code' => 'ewallet', 'name' => 'E-Wallet', 'type' => 'ewallet', 'requires_reference' => true, 'sort_order' => 5],
        ['code' => 'transfer', 'name' => 'Bank Transfer', 'type' => 'transfer', 'requires_reference' => true, 'sort_order' => 6],
    ];

    protected $fillable = ['business_id', 'code', 'name', 'type', 'requires_reference', 'is_active', 'sort_order', 'settings'];

    protected function casts(): array
    {
        return [
            'requires_reference' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'settings' => 'array',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Ensure the business has its default payment rails. Safe to call often:
     * only missing codes are created and edited defaults are left untouched.
     */
    public static function ensureDefaults(int $businessId): void
    {
        $existing = static::where('business_id', $businessId)->pluck('code')->all();

        foreach (self::DEFAULTS as $default) {
            if (in_array($default['code'], $existing, true)) {
                continue;
            }
            static::create(array_merge($default, [
                'business_id' => $businessId,
                'is_active' => true,
            ]));
        }
    }
}

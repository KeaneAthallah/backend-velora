<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Converts ingredient quantities between units of the same dimension.
 *
 * Money and quantities in this app are decimal strings; every calculation is
 * done with bc math so a recipe never drifts by float rounding. Unknown units
 * are treated as their own dimension, which means a custom unit (e.g. "sachet")
 * converts to itself but never silently to grams or millilitres.
 */
class UnitConverter
{
    /** unit => [dimension, factor to the dimension's base unit] */
    private const UNITS = [
        'mg' => ['mass', '0.001'],
        'g' => ['mass', '1'],
        'gr' => ['mass', '1'],
        'gram' => ['mass', '1'],
        'grams' => ['mass', '1'],
        'kg' => ['mass', '1000'],
        'kilogram' => ['mass', '1000'],
        'kilograms' => ['mass', '1000'],
        'ml' => ['volume', '1'],
        'milliliter' => ['volume', '1'],
        'milliliters' => ['volume', '1'],
        'millilitre' => ['volume', '1'],
        'millilitres' => ['volume', '1'],
        'cl' => ['volume', '10'],
        'dl' => ['volume', '100'],
        'l' => ['volume', '1000'],
        'lt' => ['volume', '1000'],
        'liter' => ['volume', '1000'],
        'liters' => ['volume', '1000'],
        'litre' => ['volume', '1000'],
        'litres' => ['volume', '1000'],
        'pcs' => ['count', '1'],
        'pc' => ['count', '1'],
        'piece' => ['count', '1'],
        'pieces' => ['count', '1'],
        'ea' => ['count', '1'],
        'each' => ['count', '1'],
        'unit' => ['count', '1'],
        'units' => ['count', '1'],
    ];

    public function normalise(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }

        $normalised = strtolower(trim($unit));

        return $normalised === '' ? null : $normalised;
    }

    public function dimensionOf(?string $unit): ?string
    {
        $normalised = $this->normalise($unit);
        if ($normalised === null) {
            return null;
        }

        return self::UNITS[$normalised][0] ?? 'custom:'.$normalised;
    }

    public function compatible(?string $from, ?string $to): bool
    {
        if ($from === null || $to === null) {
            return true;
        }

        return $this->dimensionOf($from) === $this->dimensionOf($to);
    }

    /**
     * Convert a quantity, keeping the app's 4 dp decimal-string contract.
     * A null unit means "already in the target unit".
     *
     * @throws InvalidArgumentException when the units are not convertible
     */
    public function convert(string|int|float $quantity, ?string $from, ?string $to): string
    {
        $quantity = (string) $quantity;
        $from = $this->normalise($from);
        $to = $this->normalise($to);

        if ($from === null || $to === null || $from === $to) {
            return bcadd($quantity, '0', 4);
        }

        if (! $this->compatible($from, $to)) {
            throw new InvalidArgumentException("Cannot convert {$from} to {$to}: incompatible units");
        }

        $base = bcmul($quantity, $this->factorOf($from), 8);

        return bcdiv($base, $this->factorOf($to), 4);
    }

    private function factorOf(string $unit): string
    {
        return self::UNITS[$unit][1] ?? '1';
    }
}

<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Allocate one landed-cost component across receipt lines using frozen
 * purchase subtotals as weights. Inputs and output are in one currency.
 * The final line receives the rounding remainder so totals reconcile.
 *
 * This calculator does not post stock values or accounting entries.
 */
class LandedCostAllocator
{
    /**
     * @param array<int|string, string> $subtotals Non-negative receipt line values.
     * @return array<int|string, string> Allocations at the requested decimal scale.
     */
    public function allocate(string $amount, array $subtotals, int $scale = 2): array
    {
        if ($scale < 0 || $scale > 10 || $subtotals === []) {
            throw new InvalidArgumentException('A non-empty set of lines and a scale between 0 and 10 are required.');
        }

        $cost = BigDecimal::of($amount);
        if ($cost->isLessThan(0)) {
            throw new InvalidArgumentException('Landed cost cannot be negative.');
        }
        $cost = $cost->toScale($scale, RoundingMode::HALF_UP);

        $weights = [];
        $total = BigDecimal::zero();
        foreach ($subtotals as $id => $value) {
            $weight = BigDecimal::of($value);
            if ($weight->isLessThan(0)) {
                throw new InvalidArgumentException('Receipt line subtotals cannot be negative.');
            }
            $weights[$id] = $weight;
            $total = $total->plus($weight);
        }
        if ($total->isZero()) {
            throw new InvalidArgumentException('At least one receipt line must have positive value.');
        }

        $remaining = $cost;
        $result = [];
        $positiveIds = array_keys(array_filter($weights, fn ($weight) => $weight->isGreaterThan(0)));
        $lastPositiveId = end($positiveIds);
        foreach ($weights as $id => $weight) {
            if ($weight->isZero()) {
                $part = BigDecimal::zero()->toScale($scale);
            } elseif ($id === $lastPositiveId) {
                $part = $remaining;
            } else {
                $part = $cost->multipliedBy($weight)
                    ->dividedBy($total, $scale, RoundingMode::DOWN);
                $remaining = $remaining->minus($part);
            }
            $result[$id] = (string) $part->toScale($scale);
        }

        return $result;
    }
}

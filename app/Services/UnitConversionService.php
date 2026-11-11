<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\UnitConversion;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Explicit unit conversions between recipe, purchase and stock units.
 *
 * Rules:
 *  - a conversion exists only if an administrator defined that exact direction;
 *  - an item-specific conversion wins over a general one;
 *  - nothing is chained or inverted automatically;
 *  - a converted quantity must be exact at the target precision. Quantities are never
 *    silently rounded, because rounded stock quantities would no longer reconcile.
 */
class UnitConversionService
{
    public const QUANTITY_SCALE = 6;

    public static function normalize(string $unit): string
    {
        return mb_strtolower(trim($unit));
    }

    public function sameUnit(string $a, string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    /** Active factor for 1 $from = factor $to, or null when none is defined. */
    public function factor(string $from, string $to, ?int $materialId = null): ?BigDecimal
    {
        if ($this->sameUnit($from, $to)) {
            return BigDecimal::one();
        }
        $rows = UnitConversion::query()->where('is_active', true)
            ->where(function ($query) use ($materialId) {
                $query->whereNull('inventory_material_id');
                if ($materialId) {
                    $query->orWhere('inventory_material_id', $materialId);
                }
            })->get()
            ->filter(fn (UnitConversion $row) => $this->sameUnit($row->from_unit, $from) && $this->sameUnit($row->to_unit, $to));
        $row = $rows->firstWhere('inventory_material_id', $materialId ?: -1) ?? $rows->firstWhere('inventory_material_id', null);

        return $row ? BigDecimal::of((string) $row->factor) : null;
    }

    /**
     * Converts a quantity exactly. Throws a validation error when no conversion is defined
     * or when the result cannot be held exactly at the stock quantity precision.
     */
    public function convert(string $quantity, string $from, string $to, ?int $materialId = null, string $errorKey = 'unit'): string
    {
        $factor = $this->factor($from, $to, $materialId);
        if (! $factor) {
            throw ValidationException::withMessages([$errorKey => "No active unit conversion is defined from {$from} to {$to}. Add it under Inventory → Unit conversions."]);
        }
        try {
            return (string) BigDecimal::of($quantity)->multipliedBy($factor)->toScale(self::QUANTITY_SCALE, RoundingMode::Unnecessary);
        } catch (MathException) {
            throw ValidationException::withMessages([$errorKey => "Converting {$quantity} {$from} to {$to} needs more than ".self::QUANTITY_SCALE.' decimal places. Adjust the quantity or use a finer stock unit; quantities are never rounded automatically.']);
        }
    }

    public function define(array $data, int $actorId): UnitConversion
    {
        return DB::transaction(function () use ($data, $actorId) {
            $from = trim($data['from_unit']);
            $to = trim($data['to_unit']);
            $materialId = ! empty($data['inventory_material_id']) ? (int) $data['inventory_material_id'] : null;
            if ($this->sameUnit($from, $to)) {
                throw ValidationException::withMessages(['to_unit' => 'Choose two different units.']);
            }
            $factor = BigDecimal::of((string) $data['factor']);
            if (! $factor->isPositive()) {
                throw ValidationException::withMessages(['factor' => 'The conversion factor must be greater than zero.']);
            }
            $duplicate = UnitConversion::query()->where('is_active', true)->lockForUpdate()->get()
                ->first(fn (UnitConversion $row) => $this->sameUnit($row->from_unit, $from) && $this->sameUnit($row->to_unit, $to)
                    && (int) ($row->inventory_material_id ?? 0) === (int) ($materialId ?? 0));
            if ($duplicate) {
                throw ValidationException::withMessages(['from_unit' => 'An active conversion already exists for this direction and item. Deactivate it before defining a new factor.']);
            }
            $conversion = UnitConversion::create([
                'from_unit' => $from, 'to_unit' => $to, 'factor' => (string) $factor->toScale(10, RoundingMode::Unnecessary),
                'inventory_material_id' => $materialId, 'is_active' => true,
                'notes' => isset($data['notes']) ? trim((string) $data['notes']) ?: null : null, 'created_by' => $actorId,
            ]);
            ActivityLog::record('inventory.unit_conversion.defined', 'unit_conversion', $conversion->id,
                "Defined 1 {$from} = {$conversion->factor} {$to}".($materialId ? ' for one stock item' : ' for all items').'.',
                ['from' => $from, 'to' => $to, 'factor' => (string) $conversion->factor, 'inventory_material_id' => $materialId]);

            return $conversion;
        });
    }

    /** Deactivation never changes documents that already stored their converted quantities. */
    public function deactivate(UnitConversion $conversion, int $actorId): void
    {
        DB::transaction(function () use ($conversion, $actorId) {
            $locked = UnitConversion::whereKey($conversion->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                throw ValidationException::withMessages(['conversion' => 'This conversion is already inactive.']);
            }
            $locked->update(['is_active' => false, 'deactivated_by' => $actorId, 'deactivated_at' => now()]);
            ActivityLog::record('inventory.unit_conversion.deactivated', 'unit_conversion', $locked->id,
                "Deactivated conversion {$locked->from_unit} to {$locked->to_unit}; existing documents keep their stored quantities.");
        });
    }
}

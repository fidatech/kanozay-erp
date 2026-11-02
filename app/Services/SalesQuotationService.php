<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\DocumentSequence;
use App\Models\SalesQuotation;
use App\Models\TradeParty;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesQuotationService
{
    public function create(array $data, int $actorId): SalesQuotation
    {
        return DB::transaction(function () use ($data, $actorId) {
            $existing = SalesQuotation::where('submission_token', $data['submission_token'])->first();
            if ($existing) return $existing->load(['customer', 'currency', 'lines']);

            $customer = TradeParty::query()->lockForUpdate()->findOrFail($data['customer_id']);
            $currency = Currency::whereKey($data['currency_id'])->where('is_active', true)->firstOrFail();
            if (! $customer->is_active || $customer->type !== 'customer' || ! $customer->currencies()->whereKey($currency->id)->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'Choose an active customer enabled for the selected currency.']);
            }

            $total = BigDecimal::zero();
            $lines = [];
            foreach ($data['lines'] as $line) {
                $lineTotal = BigDecimal::of((string) $line['unit_price'])->multipliedBy((string) $line['quantity'])->toScale(4, RoundingMode::HalfUp);
                $total = $total->plus($lineTotal);
                $lines[] = [$line, $lineTotal];
            }
            if (! $total->isGreaterThan(0) || $total->isGreaterThan(BigDecimal::of('99999999999999999999.9999'))) {
                throw ValidationException::withMessages(['lines' => 'Quotation total must be positive and within the supported range.']);
            }

            DocumentSequence::firstOrCreate(['document_type' => 'sales_quotation'], ['prefix' => 'QUO-', 'next_number' => 1, 'padding' => 6, 'is_active' => true]);
            $quotation = SalesQuotation::create([
                'number' => DocumentSequence::issue('sales_quotation'), 'submission_token' => $data['submission_token'],
                'customer_id' => $customer->id, 'currency_id' => $currency->id, 'currency_code' => $currency->code,
                'quotation_date' => $data['quotation_date'], 'valid_until' => $data['valid_until'], 'status' => 'issued',
                'subtotal' => (string) $total->toScale(4, RoundingMode::HalfUp), 'total' => (string) $total->toScale(4, RoundingMode::HalfUp),
                'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $actorId,
            ]);
            foreach ($lines as [$line, $lineTotal]) {
                $quotation->lines()->create([
                    'description' => $line['description'], 'quantity' => (int) $line['quantity'], 'unit' => $line['unit'],
                    'unit_price' => (string) BigDecimal::of((string) $line['unit_price'])->toScale(4, RoundingMode::HalfUp),
                    'line_total' => (string) $lineTotal,
                ]);
            }
            return $quotation->load(['customer', 'currency', 'lines']);
        });
    }
}

<?php

namespace App\Services;

use App\Models\DocumentSequence;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesOrderService
{
    public function acceptQuotation(int $quotationId, int $actorId, ?string $token = null): SalesOrder
    {
        return DB::transaction(function () use ($quotationId, $actorId, $token) {
            $quotation = SalesQuotation::with('lines')->lockForUpdate()->findOrFail($quotationId);
            $existing = SalesOrder::where('sales_quotation_id', $quotation->id)->first();
            if ($existing) return $existing->load(['customer', 'currency', 'quotation', 'lines']);
            if ($quotation->status !== 'issued') throw ValidationException::withMessages(['quotation' => 'Only an issued quotation can be accepted into an order.']);
            if ($quotation->valid_until->isBefore(now()->startOfDay())) throw ValidationException::withMessages(['quotation' => 'This quotation has expired. Create a revised quotation before accepting it.']);

            DocumentSequence::firstOrCreate(['document_type' => 'sales_order'], ['prefix' => 'SO-', 'next_number' => 1, 'padding' => 6, 'is_active' => true]);
            $order = SalesOrder::create([
                'number' => DocumentSequence::issue('sales_order'), 'submission_token' => $token ?: (string) Str::uuid(),
                'sales_quotation_id' => $quotation->id, 'customer_id' => $quotation->customer_id,
                'currency_id' => $quotation->currency_id, 'currency_code' => $quotation->currency_code,
                'order_date' => now()->toDateString(), 'status' => 'confirmed', 'total' => $quotation->total,
                'created_by' => $actorId,
            ]);
            foreach ($quotation->lines as $line) {
                $order->lines()->create([
                    'sales_quotation_line_id' => $line->id, 'description' => $line->description,
                    'quantity' => $line->quantity, 'unit' => $line->unit,
                    'unit_price' => $line->unit_price, 'line_total' => $line->line_total,
                ]);
            }
            $quotation->status = 'accepted';
            $quotation->save();
            return $order->load(['customer', 'currency', 'quotation', 'lines']);
        });
    }
}

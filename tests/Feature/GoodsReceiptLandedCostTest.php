<?php

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLandedCost;
use App\Services\GoodsReceiptLandedCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GoodsReceiptLandedCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_unsupported_component_before_accessing_receipt(): void
    {
        $this->expectException(ValidationException::class);
        app(GoodsReceiptLandedCostService::class)->record(1, 'unknown', '10.00', 1);
    }

    public function test_rejects_fractional_cent_amount_before_accessing_receipt(): void
    {
        $this->expectException(ValidationException::class);
        app(GoodsReceiptLandedCostService::class)->record(1, 'freight', '10.001', 1);
    }

    public function test_rejects_missing_receipt(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(GoodsReceiptLandedCostService::class)->record(999999, 'freight', '10.00', 1);
    }

    public function test_snapshot_models_reject_modification(): void
    {
        $cost = new GoodsReceiptLandedCost(['component' => 'freight']);
        $cost->forceFill(['id' => 1]);
        $cost->exists = true;
        $this->expectException(\LogicException::class);
        $cost->delete();
    }
}

<?php

namespace Tests\Unit;

use App\Services\HistoricalBatchMixedQcAllocationService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Prepared regression cases for the shared original-invoice QC budget.
 * These are NOT counted as passing until the deferred test suite runs.
 */
class HistoricalBatchMixedQcAllocationTest extends TestCase
{
    private function physical(int $soldPackages): \Illuminate\Support\Collection
    {
        return collect([(object)[
            'invoice_line_id'=>101,
            'quantity_packages'=>$soldPackages,
        ]]);
    }

    private function released(int $qc,int $credit,int $qty,string $date): array
    {
        return [
            'qc_id'=>$qc,'credit_line_id'=>$credit,
            'sales_invoice_line_id'=>101,'date'=>$date,'quantity'=>$qty,
            'original_sale_cost'=>'1.0000','expected_cost'=>'unreviewed',
            'fingerprint'=>'original-release-'.$qc,
        ];
    }

    private function scrap(int $qc,int $credit,int $qty,string $date): array
    {
        return [
            'qc_id'=>$qc,'credit_line_id'=>$credit,
            'sales_invoice_line_id'=>101,'date'=>$date,'quantity'=>$qty,
            'original_sale_cost'=>'1.0000','amount'=>'unreviewed',
            'fingerprint'=>'original-scrap-'.$qc,
        ];
    }

    public function test_full_original_sale_cost_is_allocated_once_across_both_qc_outcomes(): void
    {
        $result=app(HistoricalBatchMixedQcAllocationService::class)->allocate(
            $this->physical(3),[101=>'1.0000'],
            [$this->released(10,20,1,'2026-10-01')],
            [$this->scrap(11,21,2,'2026-10-02')]
        );
        $this->assertSame('0.3333',$result['returns'][0]['expected_cost']);
        $this->assertSame('0.6667',$result['scraps'][0]['amount']);
        $this->assertSame(64,strlen($result['returns'][0]['fingerprint']));
        $this->assertSame(64,strlen($result['scraps'][0]['fingerprint']));
    }

    public function test_scrap_before_saleable_release_reserves_its_cost_first(): void
    {
        $result=app(HistoricalBatchMixedQcAllocationService::class)->allocate(
            $this->physical(3),[101=>'1.0000'],
            [$this->released(12,23,2,'2026-10-04')],
            [$this->scrap(11,22,1,'2026-10-02')]
        );
        $this->assertSame('0.3333',$result['scraps'][0]['amount']);
        $this->assertSame('0.6667',$result['returns'][0]['expected_cost']);
    }

    public function test_same_day_release_uses_rounding_before_scrap(): void
    {
        $result=app(HistoricalBatchMixedQcAllocationService::class)->allocate(
            $this->physical(3),[101=>'1.0000'],
            [$this->released(99,22,1,'2026-10-02')],
            [$this->scrap(1,23,2,'2026-10-02')]
        );
        $this->assertSame('0.3333',$result['returns'][0]['expected_cost']);
        $this->assertSame('0.6667',$result['scraps'][0]['amount']);
    }

    public function test_unreturned_source_stock_keeps_unallocated_original_cost(): void
    {
        $result=app(HistoricalBatchMixedQcAllocationService::class)->allocate(
            $this->physical(3),[101=>'1.0000'],
            [$this->released(10,20,1,'2026-10-01')],
            [$this->scrap(11,21,1,'2026-10-02')]
        );
        $this->assertSame('0.3333',$result['returns'][0]['expected_cost']);
        $this->assertSame('0.3333',$result['scraps'][0]['amount']);
    }

    public function test_combined_return_and_scrap_cannot_exceed_original_sold_packages(): void
    {
        $this->expectException(ValidationException::class);
        app(HistoricalBatchMixedQcAllocationService::class)->allocate(
            $this->physical(3),[101=>'1.0000'],
            [$this->released(10,20,2,'2026-10-01')],
            [$this->scrap(11,21,2,'2026-10-02')]
        );
    }

    public function test_one_customer_credit_cannot_be_recovered_and_scrapped(): void
    {
        $this->expectException(ValidationException::class);
        app(HistoricalBatchMixedQcAllocationService::class)->allocate(
            $this->physical(3),[101=>'1.0000'],
            [$this->released(10,20,1,'2026-10-01')],
            [$this->scrap(11,20,1,'2026-10-02')]
        );
    }

    public function test_wrong_original_cogs_currency_cost_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $scrap=$this->scrap(11,21,1,'2026-10-02');
        $scrap['original_sale_cost']='2.0000';
        app(HistoricalBatchMixedQcAllocationService::class)->allocate(
            $this->physical(3),[101=>'1.0000'],
            [$this->released(10,20,1,'2026-10-01')],[$scrap]
        );
    }

    public function test_unmixed_original_approval_fingerprints_and_values_are_preserved(): void
    {
        $original=$this->released(10,20,1,'2026-10-01');
        $original['expected_cost']='0.3333';
        $result=app(HistoricalBatchMixedQcAllocationService::class)->allocate(
            $this->physical(3),[101=>'1.0000'],[$original],[]
        );
        $this->assertSame([$original],$result['returns']);
        $this->assertSame([],$result['scraps']);
    }
}

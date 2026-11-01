<?php

namespace App\Http\Controllers;

use App\Models\FinishedGood;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use App\Services\ProductionBatchService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductionBatchController extends Controller
{
    public function store(Request $request, ProductionOrder $order, ProductionBatchService $service)
    {
        abort_unless($request->user()->can('manufacturing.update'), 403);
        $data = $request->validate([
            'finished_good_id' => ['required', Rule::exists('finished_goods', 'id')->where('is_active', true)],
            'reported_packages' => ['required', 'integer', 'min:1', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $service->submit(
            $order, FinishedGood::findOrFail($data['finished_good_id']),
            (int) $data['reported_packages'], $data['notes'] ?? null, $request->user()->id
        );
        return back()->with('success', 'Production batch submitted for laboratory checks. No finished goods were added to stock.');
    }

    public function check(Request $request, ProductionOrder $order, ProductionBatch $batch, ProductionBatchService $service)
    {
        abort_unless($request->user()->can('laboratory.create'), 403);
        abort_unless($batch->production_order_id === $order->id, 404);
        $data = $request->validate([
            'test_name' => ['required', 'string', 'max:120'],
            'specification' => ['nullable', 'string', 'max:2000'],
            'observation' => ['nullable', 'string', 'max:2000'],
            'result' => ['required', Rule::in(['pass', 'fail'])],
        ]);
        $service->recordCheck($batch, $data, $request->user()->id);
        return back()->with('success', 'Laboratory result recorded.');
    }

    public function decide(Request $request, ProductionOrder $order, ProductionBatch $batch, ProductionBatchService $service)
    {
        abort_unless($request->user()->can('laboratory.update'), 403);
        abort_unless($batch->production_order_id === $order->id, 404);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'rejected_packages' => ['required', 'integer', 'min:0', 'max:100000'],
            'qc_notes' => ['required', 'string', 'max:2000'],
        ]);
        $service->decide($batch, $data['decision'], (int) $data['rejected_packages'], $data['qc_notes'], $request->user()->id);
        return back()->with('success', 'Laboratory decision saved. Stock remains unchanged until warehouse release.');
    }

    public function release(Request $request, ProductionOrder $order, ProductionBatch $batch, ProductionBatchService $service)
    {
        abort_unless($request->user()->can('inventory.update'), 403);
        abort_unless($batch->production_order_id === $order->id, 404);
        $data = $request->validate([
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);
        if ($batch->decided_at && $data['occurred_on'] < $batch->decided_at->toDateString()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'occurred_on' => 'Warehouse release date cannot precede laboratory approval.',
            ]);
        }
        $service->release($batch, $data['occurred_on'], $request->user()->id);
        return back()->with('success', 'Approved packages released to finished goods inventory.');
    }
}

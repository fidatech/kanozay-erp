<?php

namespace App\Http\Controllers;

use App\Models\FinishedGood;
use App\Models\ProductionBatch;
use App\Services\FinishedGoodDispatchService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinishedGoodController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can('inventory.view'), 403);
        return view('production.finished-goods', [
            'goods' => FinishedGood::orderBy('sku')->paginate(20),
        ]);
    }

    public function show(Request $request, FinishedGood $good, FinishedGoodDispatchService $dispatches)
    {
        abort_unless($request->user()->can('inventory.view'), 403);
        $batches = ProductionBatch::with('order')->where('finished_good_id', $good->id)
            ->where('status', 'released')->orderByDesc('released_at')->get();
        $batches->each(fn ($batch) => $batch->setAttribute('available_packages', $dispatches->availablePackages($batch)));
        return view('production.finished-good-show', [
            'good' => $good,
            'movements' => $good->movements()->with('batch.order')->latest('id')->paginate(25),
            'dispatchLines' => $good->dispatchLines()->with(['dispatch', 'batch.order'])->latest('id')->paginate(25, ['*'], 'dispatch_page'),
            'availableBatches' => $batches->filter(fn ($batch) => $batch->available_packages > 0),
        ]);
    }

    public function dispatch(Request $request, FinishedGood $good, FinishedGoodDispatchService $dispatches)
    {
        abort_unless($request->user()->can('inventory.update'), 403);
        $data = $request->validate([
            'submission_token' => ['required', 'uuid'],
            'production_batch_id' => ['required', 'integer', 'exists:production_batches,id'],
            'quantity_packages' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'dispatch_date' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $dispatches->post($good->id, (int) $data['production_batch_id'], (int) $data['quantity_packages'], $data, (int) $request->user()->id);
        return redirect()->route('production.finished-goods.show', $good)->with('success', 'Finished goods dispatch posted. Warehouse stock was reduced from the selected released batch.');
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('inventory.create'), 403);
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('finished_goods', 'sku')],
            'name' => ['required', 'string', 'max:180'],
            'package_unit' => ['required', 'string', 'max:40'],
            'package_weight_kg' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
        ]);
        FinishedGood::create($data);
        return redirect()->route('production.finished-goods.index')->with('success', 'Finished good added. Opening stock is zero.');
    }
}

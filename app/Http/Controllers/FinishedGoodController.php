<?php

namespace App\Http\Controllers;

use App\Models\FinishedGood;
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

    public function show(Request $request, FinishedGood $good)
    {
        abort_unless($request->user()->can('inventory.view'), 403);
        return view('production.finished-good-show', [
            'good' => $good,
            'movements' => $good->movements()->with('batch.order')->latest('id')->paginate(25),
        ]);
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

<?php

namespace App\Http\Controllers;

use App\Models\FinishedGood;
use App\Services\ApprovedCatalogService;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use App\Models\ProductionBatch;
use App\Models\SalesCreditNoteLine;
use App\Services\FinishedGoodDispatchService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinishedGoodController extends Controller
{
    public function index(Request $request,ApprovedCatalogService $catalog)
    {
        abort_unless($request->user()->can('inventory.view'), 403);
        return view('production.finished-goods', [
            'goods' => FinishedGood::with(['catalogUnit','catalogCategory'])->orderBy('sku')->paginate(20),
            'catalogUnits'=>$catalog->entries('units'),
            'catalogCategories'=>$catalog->entries('categories'),
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
            'returnLines' => SalesCreditNoteLine::with('creditNote')->where('finished_good_id', $good->id)->latest('id')->paginate(25, ['*'], 'return_page'),
            'availableBatches' => $batches->filter(fn ($batch) => $batch->available_packages > 0),
        ]);
    }

    public function dispatch(Request $request, FinishedGood $good, FinishedGoodDispatchService $dispatches)
    {
        abort_unless($request->user()->can('inventory.update'),403);
        // A standalone warehouse dispatch cannot recognize matching COGS
        // or a customer receivable. The corresponding stock movement must
        // be created within SalesInvoiceService's atomic journal workflow.
        throw \Illuminate\Validation\ValidationException::withMessages([
            'dispatch'=>'Direct finished-goods dispatch is disabled because it bypasses customer invoicing and original-currency COGS posting. Post a sales invoice to dispatch saleable products; use warehouse transfer for internal moves.',
        ]);
    }

    public function assignCatalog(Request $request,FinishedGood $good,ApprovedCatalogService $catalog)
    {
        abort_unless($request->user()->can('inventory.update'),403);
        $data=$request->validate([
            'catalog_unit_id'=>['nullable','integer','exists:operational_registers,id'],
            'catalog_category_id'=>['nullable','integer','exists:operational_registers,id'],
        ]);
        $catalog->validateUnit($data['catalog_unit_id']??null,$good->package_unit);
        $catalog->validateCategory($data['catalog_category_id']??null,'finished');
        DB::transaction(function()use($good,$data){
            $good->update([
                'catalog_unit_id'=>$data['catalog_unit_id']??null,
                'catalog_category_id'=>$data['catalog_category_id']??null,
            ]);
            ActivityLog::record('inventory.finished_good.catalog.assigned','finished_good',$good->id,
                'Approved catalog references assigned without changing package unit or weight.');
        });
        return back()->with('success','Approved product unit and category assigned.');
    }

    public function store(Request $request,ApprovedCatalogService $catalog)
    {
        abort_unless($request->user()->can('inventory.create'), 403);
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('finished_goods', 'sku')],
            'name' => ['required', 'string', 'max:180'],
            'package_unit' => ['required', 'string', 'max:40'],
            'package_weight_kg' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
            'catalog_unit_id'=>['nullable','integer','exists:operational_registers,id'],
            'catalog_category_id'=>['nullable','integer','exists:operational_registers,id'],
        ]);
        $catalog->validateUnit($data['catalog_unit_id']??null,$data['package_unit']);
        $catalog->validateCategory($data['catalog_category_id']??null,'finished');
        FinishedGood::create($data);
        return redirect()->route('production.finished-goods.index')->with('success', 'Finished good added. Opening stock is zero.');
    }
}

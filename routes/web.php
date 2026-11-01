<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::post('/language', function (\Illuminate\Http\Request $request) {
    $data = $request->validate(['locale' => ['required', \Illuminate\Validation\Rule::in(['en', 'fa', 'ps'])]]);
    session(['locale' => $data['locale']]);
    return back();
})->middleware('auth')->name('language.update');

Route::middleware('auth')->post('/production/recipes/kanozay-confirm', [\App\Http\Controllers\KanozayWorkbookPreviewController::class, 'confirm'])->name('production.recipes.kanozay-confirm');
Route::middleware('auth')->post('/production/recipes/kanozay-preview', [\App\Http\Controllers\KanozayWorkbookPreviewController::class, '__invoke'])->name('production.recipes.kanozay-preview');
Route::middleware('auth')->post('/production/recipes/import-preview', [\App\Http\Controllers\ProductionRecipeImportController::class, 'preview'])->name('production.recipes.import-preview');
Route::middleware('auth')->get('/inventory/materials', [\App\Http\Controllers\InventoryMaterialController::class, 'index'])->name('inventory.materials.index');
Route::middleware('auth')->get('/inventory/materials/{material}', [\App\Http\Controllers\InventoryMaterialController::class, 'show'])->name('inventory.materials.show');
Route::middleware('auth')->post('/inventory/materials', [\App\Http\Controllers\InventoryMaterialController::class, 'store'])->name('inventory.materials.store');
Route::middleware('auth')->post('/inventory/materials/{material}/opening', [\App\Http\Controllers\InventoryMaterialController::class, 'opening'])->name('inventory.materials.opening');
Route::middleware('auth')->get('/production/finished-goods', [\App\Http\Controllers\FinishedGoodController::class, 'index'])->name('production.finished-goods.index');
Route::middleware('auth')->post('/production/finished-goods', [\App\Http\Controllers\FinishedGoodController::class, 'store'])->name('production.finished-goods.store');
Route::middleware('auth')->post('/production/orders/{order}/batches', [\App\Http\Controllers\ProductionBatchController::class, 'store'])->name('production.orders.batches.store');
Route::middleware('auth')->post('/production/orders/{order}/batches/{batch}/checks', [\App\Http\Controllers\ProductionBatchController::class, 'check'])->name('production.orders.batches.checks.store');
Route::middleware('auth')->post('/production/orders/{order}/batches/{batch}/decide', [\App\Http\Controllers\ProductionBatchController::class, 'decide'])->name('production.orders.batches.decide');
Route::middleware('auth')->post('/production/orders/{order}/batches/{batch}/release', [\App\Http\Controllers\ProductionBatchController::class, 'release'])->name('production.orders.batches.release');
Route::middleware('auth')->get('/production/orders', [\App\Http\Controllers\ProductionOrderController::class, 'index'])->name('production.orders.index');
Route::middleware('auth')->post('/production/orders', [\App\Http\Controllers\ProductionOrderController::class, 'store'])->name('production.orders.store');
Route::middleware('auth')->patch('/production/orders/{order}/materials/{materialLine}/inventory', [\App\Http\Controllers\ProductionOrderController::class, 'linkMaterial'])->name('production.orders.materials.link');
Route::middleware('auth')->post('/production/orders/{order}/reserve', [\App\Http\Controllers\ProductionOrderController::class, 'reserve'])->name('production.orders.reserve');
Route::middleware('auth')->post('/production/orders/{order}/issue', [\App\Http\Controllers\ProductionOrderController::class, 'issue'])->name('production.orders.issue');
Route::middleware('auth')->post('/production/orders/{order}/cancel', [\App\Http\Controllers\ProductionOrderController::class, 'cancel'])->name('production.orders.cancel');
Route::middleware('auth')->get('/production/orders/{order}', [\App\Http\Controllers\ProductionOrderController::class, 'show'])->name('production.orders.show');
Route::middleware('auth')->get('/production/recipes', [\App\Http\Controllers\ProductionRecipeController::class, 'index'])->name('production.recipes.index');
Route::middleware('auth')->get('/production/recipes/{recipe}', [\App\Http\Controllers\ProductionRecipeController::class, 'show'])->name('production.recipes.show');
Route::middleware('auth')->post('/production/recipes/{recipe}/approve', [\App\Http\Controllers\ProductionRecipeController::class, 'approve'])->name('production.recipes.approve');
Route::middleware('auth')->post('/production/recipes', [\App\Http\Controllers\ProductionRecipeController::class, 'store'])->name('production.recipes.store');
Route::middleware('auth')->post('/purchases/quick-supplier', [\App\Http\Controllers\PurchaseOrderController::class,'quickSupplier'])->name('purchases.quick-supplier');
Route::middleware('auth')->post('/purchases/quick-customer', [\App\Http\Controllers\PurchaseOrderController::class,'quickCustomer'])->name('purchases.quick-customer');
Route::middleware('auth')->post('/purchases/quick-currency', [\App\Http\Controllers\PurchaseOrderController::class,'quickCurrency'])->name('purchases.quick-currency');
Route::middleware('auth')->post('/purchases/orders', [\App\Http\Controllers\PurchaseOrderController::class,'store'])->name('purchases.orders.store');
Route::middleware('auth')->patch('/purchases/orders/{order}/items/{item}/inventory', [\App\Http\Controllers\PurchaseOrderController::class,'linkMaterial'])->name('purchases.orders.items.link');
Route::middleware('auth')->post('/purchases/orders/{order}/goods-receipts', [\App\Http\Controllers\GoodsReceiptController::class,'store'])->name('purchases.orders.receipts.store');
Route::middleware('auth')->post('/purchases/orders/{order}/confirm', [\App\Http\Controllers\PurchaseOrderController::class,'confirm'])->name('purchases.orders.confirm');
Route::middleware('auth')->post('/purchases/orders/{order}/expenses', [\App\Http\Controllers\PurchaseOrderController::class,'addExpense'])->name('purchases.orders.expenses.store');
Route::middleware('auth')->post('/purchases/orders/{order}/advances', [\App\Http\Controllers\PurchaseOrderController::class,'addAdvance'])->name('purchases.orders.advances.store');
Route::middleware('auth')->get('/purchases/orders/{order}', [\App\Http\Controllers\PurchaseOrderController::class,'show'])->name('purchases.orders.show');
Route::middleware('auth')->get('/purchases/orders', [\App\Http\Controllers\PurchaseOrderController::class,'index'])->name('purchases.orders.index');

Route::middleware('auth')->get('/workspace/{module}/{page}', [\App\Http\Controllers\ModulePageController::class, 'show'])->name('workspace.page');

Route::middleware('auth')->group(function () {
    Route::get('/finance/documents', [\App\Http\Controllers\TradeDocumentController::class,'index'])->name('finance.documents.index');
    Route::get('/finance/aging', [\App\Http\Controllers\TradeAgingController::class,'index'])->name('finance.aging.index');
    Route::get('/finance/reconciliation', [\App\Http\Controllers\TradeReconciliationController::class,'index'])->name('finance.reconciliation.index');
    Route::post('/finance/reconciliation/cases', [\App\Http\Controllers\TradeReconciliationController::class,'saveCase'])->name('finance.reconciliation.cases.save');
    Route::post('/finance/reconciliation/cases/{case}/approve', [\App\Http\Controllers\TradeReconciliationController::class,'approveCase'])->name('finance.reconciliation.cases.approve');
    Route::post('/finance/reconciliation/cases/{case}/reopen', [\App\Http\Controllers\TradeReconciliationController::class, 'reopenCase'])->name('finance.reconciliation.cases.reopen');
    Route::post('/finance/documents', [\App\Http\Controllers\TradeDocumentController::class,'store'])->name('finance.documents.store');
    Route::post('/finance/settlements', [\App\Http\Controllers\TradeSettlementController::class,'store'])->name('finance.settlements.store');
    Route::get('/finance/customers', [\App\Http\Controllers\TradePartyController::class,'customers'])->name('finance.customers.index');
    Route::get('/finance/suppliers', [\App\Http\Controllers\TradePartyController::class,'suppliers'])->name('finance.suppliers.index');
    Route::get('/finance/customers/{party}', [\App\Http\Controllers\TradePartyController::class,'showCustomer'])->name('finance.customers.show');
    Route::get('/finance/suppliers/{party}', [\App\Http\Controllers\TradePartyController::class,'showSupplier'])->name('finance.suppliers.show');
    Route::patch('/finance/customers/{party}', [\App\Http\Controllers\TradePartyController::class,'updateCustomer'])->name('finance.customers.update');
    Route::patch('/finance/suppliers/{party}', [\App\Http\Controllers\TradePartyController::class,'updateSupplier'])->name('finance.suppliers.update');
    Route::get('/finance/parties', [\App\Http\Controllers\TradePartyController::class,'index'])->name('finance.parties.index');
    Route::post('/finance/parties', [\App\Http\Controllers\TradePartyController::class,'store'])->name('finance.parties.store');
    Route::post('/finance/parties/{party}/opening-balance', [\App\Http\Controllers\TradePartyController::class,'openingBalance'])->name('finance.parties.opening.store');
    Route::post('/finance/parties/{party}/currencies', [\App\Http\Controllers\TradePartyController::class,'addCurrency'])->name('finance.parties.currencies.store');
    Route::get('/finance/exchanges', [\App\Http\Controllers\CurrencyExchangeController::class,'index'])->name('finance.exchanges.index');
    Route::post('/finance/exchanges', [\App\Http\Controllers\CurrencyExchangeController::class,'store'])->name('finance.exchanges.store');
    Route::get('/finance/money', [\App\Http\Controllers\MoneyTransactionController::class,'index'])->name('finance.money.index');
    Route::post('/finance/money', [\App\Http\Controllers\MoneyTransactionController::class,'store'])->name('finance.money.store');
    Route::get('/finance/accounts', [\App\Http\Controllers\FinancialAccountController::class,'index'])->name('finance.accounts.index');
    Route::post('/finance/accounts', [\App\Http\Controllers\FinancialAccountController::class,'store'])->name('finance.accounts.store');
    Route::put('/finance/accounts/{account}', [\App\Http\Controllers\FinancialAccountController::class,'update'])->name('finance.accounts.update');
    Route::get('/finance/overview', function () { abort_unless(auth()->user()->can('administration.view'), 403); return view('finance.overview', ['customers' => \App\Models\TradeParty::where('type','customer')->where('is_active',true)->count(), 'suppliers' => \App\Models\TradeParty::where('type','supplier')->where('is_active',true)->count(), 'receivables' => \App\Models\TradeDocument::where('type','receivable')->count(), 'payables' => \App\Models\TradeDocument::where('type','payable')->count()]); })->name('finance.overview');
    Route::get('/finance/general-ledger', [\App\Http\Controllers\GeneralLedgerController::class,'index'])->name('finance.ledger.index');
    Route::get('/finance/journals', [\App\Http\Controllers\JournalEntryController::class,'index'])->name('finance.journals.index');
    Route::post('/finance/journals', [\App\Http\Controllers\JournalEntryController::class,'store'])->name('finance.journals.store');
    Route::patch('/finance/journals/{journal}/post', [\App\Http\Controllers\JournalEntryController::class,'post'])->name('finance.journals.post');
    Route::post('/finance/journals/{journal}/reverse', [\App\Http\Controllers\JournalEntryController::class,'reverse'])->name('finance.journals.reverse');
    Route::get('/approvals', [\App\Http\Controllers\ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('/approvals', [\App\Http\Controllers\ApprovalController::class, 'store'])->name('approvals.store');
    Route::patch('/approvals/{approval}/decide', [\App\Http\Controllers\ApprovalController::class, 'decide'])->name('approvals.decide');
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::patch('/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'read'])->name('notifications.read');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('chart-of-accounts', [\App\Http\Controllers\Admin\LedgerAccountController::class,'index'])->name('accounts.index');
    Route::post('chart-of-accounts', [\App\Http\Controllers\Admin\LedgerAccountController::class,'store'])->name('accounts.store');
    Route::put('chart-of-accounts/{account}', [\App\Http\Controllers\Admin\LedgerAccountController::class,'update'])->name('accounts.update');
    Route::get('financial-settings', [\App\Http\Controllers\Admin\FinancialSettingController::class,'edit'])->name('financial-settings.edit');
    Route::put('financial-settings', [\App\Http\Controllers\Admin\FinancialSettingController::class,'update'])->name('financial-settings.update');
    Route::get('document-numbering', [\App\Http\Controllers\Admin\DocumentSequenceController::class,'index'])->name('sequences.index');
    Route::post('document-numbering', [\App\Http\Controllers\Admin\DocumentSequenceController::class,'store'])->name('sequences.store');
    Route::put('document-numbering/{sequence}', [\App\Http\Controllers\Admin\DocumentSequenceController::class,'update'])->name('sequences.update');
    Route::get('currencies', [\App\Http\Controllers\Admin\CurrencyController::class,'index'])->name('currencies.index');
    Route::post('currencies', [\App\Http\Controllers\Admin\CurrencyController::class,'store'])->name('currencies.store');
    Route::put('currencies/{currency}', [\App\Http\Controllers\Admin\CurrencyController::class,'update'])->name('currencies.update');
    Route::patch('currencies/{currency}/base', [\App\Http\Controllers\Admin\CurrencyController::class,'base'])->name('currencies.base');
    Route::get('exchange-rates', [\App\Http\Controllers\Admin\ExchangeRateController::class,'index'])->name('exchange-rates.index');
    Route::post('exchange-rates', [\App\Http\Controllers\Admin\ExchangeRateController::class,'store'])->name('exchange-rates.store');
    Route::get('company', [\App\Http\Controllers\Admin\CompanySettingController::class, 'edit'])->name('company.edit');
    Route::put('company', [\App\Http\Controllers\Admin\CompanySettingController::class, 'update'])->name('company.update');
    Route::get('/', function () { abort_unless(auth()->user()->can('administration.view') || auth()->user()->can('users.view') || auth()->user()->can('roles.view'), 403); return view('admin.index'); })->name('index');
    Route::get('activity', function () { abort_unless(auth()->user()->can('administration.view'), 403); return view('admin.activity.index', ['activities' => \App\Models\ActivityLog::with('actor')->latest()->paginate(30)]); })->name('activity.index');
    Route::resource('users', \App\Http\Controllers\Admin\UserController::class)->only(['index','store','update','destroy']);
    Route::resource('roles', \App\Http\Controllers\Admin\RoleController::class)->only(['index','store','update','destroy']);
});

require __DIR__.'/auth.php';

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

Route::middleware('auth')->group(function () {
    Route::get('/finance/documents', [\App\Http\Controllers\TradeDocumentController::class,'index'])->name('finance.documents.index');
    Route::get('/finance/aging', [\App\Http\Controllers\TradeAgingController::class,'index'])->name('finance.aging.index');
    Route::get('/finance/reconciliation', [\App\Http\Controllers\TradeReconciliationController::class,'index'])->name('finance.reconciliation.index');
    Route::post('/finance/documents', [\App\Http\Controllers\TradeDocumentController::class,'store'])->name('finance.documents.store');
    Route::post('/finance/settlements', [\App\Http\Controllers\TradeSettlementController::class,'store'])->name('finance.settlements.store');
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

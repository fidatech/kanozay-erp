<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\Shareholder;
use App\Services\Accounting\ShareholderTransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use App\Support\LocalizedDecimal;
use App\Services\ShareholderStatementService;

class ShareholderController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['shareholders.view', 'administration.view']), 403);

        return view('shareholders.index', [
            'shareholders' => Shareholder::with('currencies')->withCount('transactions')->orderBy('name')->paginate(20),
            'currencies' => Currency::where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canAny(['shareholders.create', 'administration.update']), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'code' => ['nullable', 'string', 'max:40', 'unique:shareholders,code'],
            'phone' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:180'],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'currency_ids' => ['required', 'array', 'min:1'],
            'currency_ids.*' => ['required', 'integer', 'distinct', Rule::exists('currencies', 'id')->where('is_active', true)],
        ]);

        $shareholder = DB::transaction(function () use ($data, $request) {
            $currencyIds = array_map('intval', $data['currency_ids']);
            unset($data['currency_ids']);
            $shareholder = Shareholder::create($data + ['is_active' => true, 'created_by' => $request->user()->id]);
            $shareholder->currencies()->sync($currencyIds);
            foreach ($currencyIds as $currencyId) {
                $shareholder->provisionLedgerAccountsForCurrency($currencyId);
            }
            \App\Models\ActivityLog::record('shareholder.created', 'shareholder', $shareholder->id, 'Shareholder profile created: '.$shareholder->name);
            return $shareholder;
        });

        return redirect()->route('shareholders.show', $shareholder)->with('status', 'Shareholder profile and currency-specific ledger accounts created.');
    }

    public function show(Request $request, Shareholder $shareholder)
    {
        abort_unless($request->user()->canAny(['shareholders.view', 'administration.view']), 403);
        $filters = $request->validate([
            'currency_id' => ['nullable', 'integer', Rule::exists('shareholder_currencies', 'currency_id')->where('shareholder_id', $shareholder->id)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'export' => ['nullable', Rule::in(['csv'])],
        ]);
        $query = $shareholder->transactions()->with(['currency', 'financialAccount', 'journal'])
            ->when($filters['currency_id'] ?? null, fn ($q, $id) => $q->where('currency_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('transaction_date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('transaction_date', '<=', $date))
            ->orderBy('transaction_date')->orderBy('id');

        $shareholder->load('currencies');
        $statementService=app(ShareholderStatementService::class);
        $allActivity=$statementService->rows($shareholder,$filters);
        $totals=$statementService->summary($shareholder,$allActivity);
        if (($filters['export'] ?? null) === 'csv') {
            return response()->streamDownload(function()use($allActivity){
                $out=fopen('php://output','w');
                fputcsv($out,['Date','Document','Category','Currency','Amount','Financial account','Approval/reference','Posted GL journal']);
                foreach($allActivity as $row){
                    $cells=[
                        $row['occurred_on'],$row['number'],$row['type'],
                        $row['currency_code'],$row['amount'],$row['financial_account'],
                        $row['reference'],$row['journal_number'],
                    ];
                    // Prevent formula execution if the CSV is opened in Excel.
                    fputcsv($out,array_map(fn($cell)=>
                        preg_match('/^\\s*[=+@\\-]/u',(string)$cell)?"'".$cell:(string)$cell,$cells));
                }
                fclose($out);
            },'shareholder-'.$shareholder->id.'-statement.csv',
              ['Content-Type'=>'text/csv; charset=UTF-8']);
        }
        $recentActivity=array_reverse(array_slice($allActivity,-75));

        $transactions = $query->paginate(30)->withQueryString();
        $accounts = FinancialAccount::where('is_active', true)
            ->whereIn('currency_id', $shareholder->currencies()->select('currencies.id'))
            ->with('currency')->orderBy('name')->get();

        return view('shareholders.show', [
            'shareholder' => $shareholder,
            'statementTotals'=>$totals,
            'recentActivity'=>$recentActivity,
            'activityCount'=>count($allActivity),
            'transactions' => $transactions,
            'accounts' => $accounts,
            'idempotencyKey' => (string) Str::uuid(),
            'filters' => $filters,
        ]);
    }

    public function postTransaction(Request $request, Shareholder $shareholder, ShareholderTransactionService $service)
    {
        abort_unless($request->user()->can('administration.update') ||
            ($request->user()->canAny(['shareholders.create','shareholders.update']) &&
             $request->user()->can('finance.update')),403);
        $request->merge(['amount'=>LocalizedDecimal::normalize($request->input('amount'))]);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'type' => ['required', Rule::in(['contribution', 'withdrawal'])],
            'transaction_date' => ['required', 'date','before_or_equal:today'],
            'currency_id' => ['required', 'integer', Rule::exists('shareholder_currencies', 'currency_id')->where('shareholder_id', $shareholder->id)],
            'financial_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:9999999999999999.9999'],
            'reference' => [Rule::requiredIf(fn()=>($request->input('type')==='withdrawal')),
                'nullable','string','min:4','max:120'],
            'description' => ['required', 'string', 'max:2000'],
        ]);
        $service->post($shareholder, $data, $request->user()->id);

        return redirect()->route('shareholders.show', $shareholder)->with('status', 'Shareholder transaction posted to the General Ledger.');
    }
}

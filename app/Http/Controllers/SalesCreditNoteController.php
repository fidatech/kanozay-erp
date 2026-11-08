<?php

namespace App\Http\Controllers;

use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Services\SalesCreditNoteService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SalesCreditNoteController extends Controller
{
    public function create(Request $request, SalesInvoice $invoice)
    {
        abort_unless($request->user()->can('sales.create'), 403);
        abort_unless($invoice->status === 'posted', 404);
        $invoice->load(['customer', 'lines.finishedGood', 'lines.batch']);
        foreach ($invoice->lines as $line) {
            $line->setAttribute('returned_packages', (int) $line->creditNotes()->sum('quantity_packages'));
            $line->setAttribute('returnable_packages', max(0, $line->quantity_packages - $line->returned_packages));
        }
        $warehouses=\App\Models\CompanyWarehouse::where('is_active',true)->orderBy('name')->get();
        $invoice->load(['tradeDocument.allocations','tradeDocument.credits']);
        $refundAccounts=\App\Models\FinancialAccount::with('currency')
            ->where('currency_id',$invoice->currency_id)->where('is_active',true)->orderBy('name')->get();
        return view('sales.credit-notes.create', compact('invoice','warehouses','refundAccounts'));
    }

    public function store(Request $request, SalesInvoice $invoice, SalesCreditNoteService $service)
    {
        abort_unless($request->user()->can('sales.create'), 403);
        $data = $request->validate([
            'submission_token' => ['required', 'uuid'], 'credit_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:255'],
            'refund_source_account_id'=>['nullable','integer','exists:financial_accounts,id'],
            'refund_reference'=>['nullable','string','min:3','max:120'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.sales_invoice_line_id' => ['required', 'integer', 'distinct', 'exists:sales_invoice_lines,id'],
            'lines.*.quantity_packages' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'lines.*.restock' => ['nullable', 'boolean'],
            'lines.*.company_warehouse_id'=>['nullable',\Illuminate\Validation\Rule::exists('company_warehouses','id')->where('is_active',true)],
        ]);
        $data['lines'] = array_values(array_filter($data['lines'], fn ($line) => (int) $line['quantity_packages'] > 0));
        if (! $data['lines']) throw ValidationException::withMessages(['lines' => 'Enter at least one returned package quantity.']);
        foreach ($data['lines'] as $line) {
            if (filter_var($line['restock']??false,FILTER_VALIDATE_BOOLEAN)) {
                throw ValidationException::withMessages([
                    'lines'=>'Returned products must pass independent QC quarantine review before restocking.',
                ]);
            }
        }
        if(!empty($data['refund_source_account_id']) &&
            !$request->user()->canAny(['finance.update','administration.update']))abort(403);
        $note = $service->post($invoice->id, $data, (int) $request->user()->id);
        return redirect()->route('sales.credit-notes.show', $note)->with('success', 'Credit note posted. Returned packages are quarantined until an independent QC disposition; saleable inventory was not increased.');
    }

    public function show(Request $request, SalesCreditNote $creditNote)
    {
        abort_unless($request->user()->canAny(['sales.view', 'administration.view', 'finance.view', 'reports.view']), 403);
        $creditNote->load(['invoice.customer', 'lines.invoiceLine', 'lines.quarantine', 'journal',
            'refund.source','refund.transaction.journal','tradeDocumentCredit']);
        return view('sales.credit-notes.show', compact('creditNote'));
    }
}

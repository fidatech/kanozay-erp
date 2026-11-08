<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\TradeDocumentCredit;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesCreditNoteService
{
    public function __construct(private readonly JournalPostingService $journals) {}

    public function post(int $invoiceId, array $data, int $actorId): SalesCreditNote
    {
        return DB::transaction(function () use ($invoiceId, $data, $actorId) {
            $existing = SalesCreditNote::where('submission_token', $data['submission_token'])->first();
            if ($existing) return $existing->load(['invoice', 'lines', 'journal']);

            $invoice = SalesInvoice::query()->with(['tradeDocument.allocations', 'journal.lines'])->lockForUpdate()->findOrFail($invoiceId);
            if ($invoice->status !== 'posted' || $invoice->invoice_date->gt($data['credit_date'])) $this->reject('Select a posted invoice and a credit date on or after its date.');
            $remainingBalance = BigDecimal::of((string) $invoice->total);
            foreach ($invoice->tradeDocument?->allocations ?? [] as $allocation) $remainingBalance = $remainingBalance->minus((string) $allocation->amount);
            $previousCredits = BigDecimal::zero();
            foreach ($invoice->tradeDocument?->credits ?? [] as $credit) $previousCredits = $previousCredits->plus((string) $credit->amount);
            $remainingBalance = $remainingBalance->minus($previousCredits);

            $total = BigDecimal::zero();
            $prepared = [];
            foreach ($data['lines'] as $input) {
                $line = \App\Models\SalesInvoiceLine::query()->lockForUpdate()->where('sales_invoice_id', $invoice->id)->findOrFail($input['sales_invoice_line_id']);
                $alreadyReturned = DB::table('sales_credit_note_lines')->where('sales_invoice_line_id', $line->id)->sum('quantity_packages');
                $quantity = (int) $input['quantity_packages'];
                if ($quantity < 1 || $alreadyReturned + $quantity > $line->quantity_packages) $this->reject('Return quantity exceeds the uninvolved quantity on an invoice line.');
                $amount = BigDecimal::of((string) $line->unit_price)->multipliedBy((string) $quantity)->toScale(4, RoundingMode::HalfUp);
                $total = $total->plus($amount);
                // Customer returns must enter QC quarantine on receipt. An
                // invoice-credit form cannot certify physical saleability.
                if(filter_var($input['restock'] ?? false,FILTER_VALIDATE_BOOLEAN)){
                    $this->reject('Customer returns require quarantine and independent QC release. Direct restock is not permitted.');
                }
                $prepared[] = [$line, $quantity, $amount, $input['company_warehouse_id']??null];
            }
            if(!$total->isGreaterThan(0))$this->reject('Return value must be positive.');
            if($remainingBalance->isLessThan(0))$this->reject('Invoice settlement is inconsistent; reconcile payments and previous credits first.');
            $refundAmount=$total->isGreaterThan($remainingBalance)
                ?$total->minus($remainingBalance)->toScale(4):BigDecimal::zero();
            if($refundAmount->isPositive()){
                if(empty($data['refund_source_account_id'])||empty($data['refund_reference']))
                    $this->reject('Paid return exceeds unpaid balance. Provide an authorized refund account and approval reference.');
                if($refundAmount->isGreaterThan('999999999999.9999'))
                    $this->reject('Refund exceeds supported cash payment limit.');
            }elseif(!empty($data['refund_source_account_id'])||!empty($data['refund_reference'])){
                $this->reject('Refund account or reference provided when only an outstanding receivable credit is needed.');
            }

            $partyLedger = DB::table('entity_ledger_accounts')->where([
                'entity_type' => 'customer', 'entity_id' => $invoice->customer_id,
                'currency_id' => $invoice->currency_id, 'purpose' => 'receivable',
            ])->value('ledger_account_id');
            $incomeLedger = $invoice->journal?->lines->first(function ($line) {
                $account = LedgerAccount::find($line->ledger_account_id);
                return $account && $account->type === 'income' && BigDecimal::of((string) $line->credit)->isGreaterThan(0);
            })?->ledger_account_id;
            if (! $partyLedger || ! $incomeLedger) $this->reject('The invoice receivable and revenue ledgers are not available for credit posting.');

            DocumentSequence::firstOrCreate(['document_type' => 'sales_credit_note'], ['prefix' => 'CN-', 'next_number' => 1, 'padding' => 6, 'is_active' => true]);
            $number = DocumentSequence::issue('sales_credit_note');
            $journal = JournalEntry::create([
                'number' => $number, 'entry_date' => $data['credit_date'], 'reference' => $invoice->number,
                'description' => 'Credit note for '.$invoice->number.' — '.$data['reason'],
                'currency_id' => $invoice->currency_id, 'created_by' => $actorId, 'status' => 'draft',
            ]);
            $journal->lines()->create(['ledger_account_id' => $incomeLedger, 'debit' => (string) $total, 'credit' => '0']);
            $journal->lines()->create(['ledger_account_id' => $partyLedger, 'debit' => '0', 'credit' => (string) $total]);
            $this->journals->post($journal, $actorId);

            $note = SalesCreditNote::create([
                'number' => $number, 'submission_token' => $data['submission_token'],
                'sales_invoice_id' => $invoice->id, 'trade_document_id' => $invoice->trade_document_id,
                'journal_entry_id' => $journal->id, 'credit_date' => $data['credit_date'], 'reason' => $data['reason'],
                'currency_code' => $invoice->currency_code, 'total' => (string) $total->toScale(4, RoundingMode::HalfUp), 'created_by' => $actorId,
            ]);
            foreach ($prepared as [$line, $quantity, $amount, $returnWarehouse]) {
                $returnLine=$note->lines()->create([
                    'sales_invoice_line_id' => $line->id, 'finished_good_id' => $line->finished_good_id,
                    'production_batch_id' => $line->production_batch_id, 'quantity_packages' => $quantity,
                    'unit_price' => $line->unit_price, 'line_total' => (string) $amount,
                    'restocked' => false,
                    'company_warehouse_id'=>$returnWarehouse,
                ]);
                \App\Models\SalesReturnQuarantine::create([
                    'sales_credit_note_line_id'=>$returnLine->id,
                    'production_batch_id'=>$line->production_batch_id,
                    'received_warehouse_id'=>$returnWarehouse,
                    'quantity_packages'=>$quantity,'status'=>'quarantined',
                    'registered_by'=>$actorId,
                ]);
            }
            // The original invoice receivable is reduced only by its unpaid
            // remainder; reimbursement of amounts already paid is recorded
            // in a separate, linked and balanced cash payment journal.
            $creditAgainstOutstanding=$total->minus($refundAmount);
            if($creditAgainstOutstanding->isPositive())TradeDocumentCredit::create([
                'trade_document_id'=>$invoice->trade_document_id,
                'sales_credit_note_id'=>$note->id,'credit_date'=>$data['credit_date'],
                'amount'=>(string)$creditAgainstOutstanding->toScale(4,RoundingMode::HalfUp),
            ]);
            if($refundAmount->isPositive())app(SalesRefundService::class)->post(
                $note,(string)$refundAmount,(int)$data['refund_source_account_id'],
                (string)$data['refund_reference'],(int)$partyLedger,$actorId
            );
            ActivityLog::record('sales_credit_note.posted', 'sales_credit_note', $note->id, $number.' posted for '.$invoice->number);
            return $note->load(['invoice', 'lines', 'journal']);
        });
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['credit_note' => $message]);
    }
}

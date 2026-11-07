@canany(['finance.update','administration.update'])
<section class="al-panel"><h2>Reconcile and post pending asset disposal journals</h2>
<p class="al-warning">The acquisition journal must already be posted against the precise asset reference, value and currency. Finance must select the originating PPE ledger and the appropriate disposal accounts. The system verifies the acquisition, depreciation, book value, and one-time posting. Proceeds are treated as receivable, not as cash received.</p>
@foreach($disposals as $d)
@if($d->financial_status==='pending_reconciliation')
<details style="padding:13px;border:1px solid #dce6f0;border-radius:8px;margin:10px 0">
<summary><strong>{{ $d->asset_tag }}</strong> · Net book value {{ $d->currency_code }} {{ $d->book_value }} · Proceeds {{ $d->proceeds }}</summary>
<form method="POST" action="{{ route('assets.lifecycle.disposal-accounting',$d->id) }}" class="al-form" style="margin-top:12px">@csrf
<label>Original posted acquisition journal<select name="acquisition_journal_id" required><option value="">Choose journal with asset source reference</option>
@foreach($acquisitionJournals as $j)<option value="{{ $j->id }}">{{ $j->number }} · {{ $j->reference }} · {{ $j->entry_date?->format('Y-m-d') }}</option>@endforeach</select></label>
<label>Original PPE asset ledger<select name="asset_ledger_id" required><option value="">Select asset account</option>
@foreach($assetLedgers as $a)<option value="{{ $a->id }}">{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></label>
<label>Proceeds receivable ledger (if sale)<select name="receivable_ledger_id"><option value="">No proceeds</option>@foreach($assetLedgers as $a)<option value="{{ $a->id }}">{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></label>
<label>Loss expense ledger (if loss)<select name="loss_ledger_id"><option value="">Not applicable</option>@foreach($expenseLedgers as $a)<option value="{{ $a->id }}">{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></label>
<label>Gain income ledger (if gain)<select name="gain_ledger_id"><option value="">Not applicable</option>@foreach($incomeLedgers as $a)<option value="{{ $a->id }}">{{ $a->code }} · {{ $a->name }}</option>@endforeach</select></label>
<label>Reconciliation approval/reference<input name="reconciliation_reference" required minlength="4" maxlength="180"></label>
<div><button class="btn" onclick="return confirm('Post final asset disposal accounting journal?')" type="submit">Post final disposal journal</button></div>
</form></details>
@endif
@endforeach
</section>
@endcanany
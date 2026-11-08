@extends('layouts.kanozay')
@section('content')
<style>
.dn-sheet{max-width:880px;background:#fff;border:1px solid #e4e9f0;margin:0 auto;padding:35px 40px;border-radius:7px;color:#21384f}
.dn-sheet .dn-head{display:flex;justify-content:space-between;gap:30px;align-items:center;border-bottom:3px solid #16814e;padding-bottom:16px}
.dn-sheet .dn-head img{width:155px;max-height:85px;object-fit:contain}
.dn-sheet .dn-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;padding:22px 0}
.dn-sheet .dn-line-table table{font-size:12px}.dn-sheet .dn-line-table th{background:#eaf5ef;padding:12px}.dn-sheet .dn-line-table td{padding:12px}
.dn-sheet .dn-signatures{display:grid;grid-template-columns:1fr 1fr;gap:50px;margin-top:65px}
.dn-sheet .dn-signatures>div{border-top:1px solid #8398ac;padding-top:10px}
.dn-controls{max-width:880px;margin:0 auto 16px;display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.dn-controls form{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.dn-controls input{min-height:40px}
@media(max-width:600px){.dn-sheet{padding:14px}.dn-sheet .dn-grid{grid-template-columns:1fr}}
@media print{@page{size:A4;margin:12mm}.top,.rail,.sidebar,.dn-controls,.no-print{display:none!important}.content{padding:0!important;margin:0!important}.dn-sheet{margin:0;box-shadow:none;border:0;max-width:none;padding:0}}
</style>
<div class="dn-controls">
<a class="btn btn-light" href="{{ route('workspace.page',['sales','delivery-notes']) }}">Back to delivery notes</a>
<a class="btn btn-light" href="{{ route('workspace.page',['module'=>'sales','page'=>'delivery-tracking','note_id'=>$note->id]) }}">Tracking history</a>
<button class="btn" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save PDF</button>
@if($note->status==='issued')
@canany(['sales.update','administration.update'])
<form method="POST" action="{{ route('sales.delivery-notes.delivered',$note) }}">@csrf
<input type="hidden" name="submission_token" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<input type="text" name="received_by" placeholder="Received by" required maxlength="150">
<input type="text" name="delivery_location" placeholder="Delivery checkpoint" maxlength="180" required>
<input type="text" name="proof_reference" placeholder="Proof of delivery reference" maxlength="180" required>
<input type="datetime-local" name="delivered_at" value="{{ now()->format('Y-m-d\TH:i') }}" required>
<button class="btn">Confirm delivery</button></form>
<form method="POST" action="{{ route('sales.delivery-notes.cancel',$note) }}" onsubmit="return confirm('Cancel this logistics note? Invoiced inventory dispatch will remain posted.')">@csrf
<input name="reason" required maxlength="1000" minlength="5" placeholder="Cancellation reason"><button class="btn btn-light">Cancel note</button>
</form>
@endcanany
@endif
@if($note->status==='returned')
@canany(['sales.update','administration.update'])
<form method="POST" action="{{ route('sales.delivery-notes.cancel',$note) }}" onsubmit="return confirm('Close this returned logistics note and free its delivery-document allocation? The original invoice dispatch and stock will not be reversed.')">
@csrf
<input name="reason" required maxlength="1000" minlength="5" placeholder="Return evidence and cancellation reason">
<button class="btn btn-light">Close returned note</button>
</form>
@endcanany
@endif
</div>
@if(session('success'))<div class="panel no-print" style="color:#206c49">{{ session('success') }}</div>@endif
@if($errors->any())<div class="panel no-print" style="color:#ae312d">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<div class="dn-sheet" dir="{{ session('locale','en')==='en'?'ltr':'rtl' }}">
<div class="dn-head">
<div style="display:flex;gap:18px;align-items:center"><img src="{{ asset('images/kanozay-logo.jpg') }}" alt="Kanozay Foods Manufacturing">
<div><h2 style="margin:0">Kanozay Foods Manufacturing Company</h2><p style="margin:5px 0;color:#75879a">Delivery &amp; Dispatch Documentation</p></div></div>
<div style="text-align:right"><h1 style="font-size:23px;margin:0">DELIVERY NOTE</h1><strong>{{ $note->number }}</strong><div>{{ $note->delivery_date?->format('Y-m-d') }}</div></div>
</div>
<div class="dn-grid">
<div><strong>Delivered to</strong><p>{{ $note->invoice?->customer?->name }}</p><p>{{ $note->delivery_address ?: $note->invoice?->customer?->address }}</p><p>{{ $note->invoice?->customer?->phone }}</p></div>
<div><div><strong>Sales Invoice:</strong> {{ $note->invoice?->number }}</div><div><strong>Vehicle:</strong> {{ $note->vehicle_number?:'—' }}</div><div><strong>Driver:</strong> {{ $note->driver_name?:'—' }}</div><div><strong>Customer reference:</strong> {{ $note->reference?:'—' }}</div><div><strong>Status:</strong> {{ strtoupper($note->status) }}</div></div>
</div>
<div class="dn-line-table"><table style="width:100%"><thead><tr><th>#</th><th>Product</th><th>SKU</th><th>Production batch</th><th>Packages</th><th>Unit</th></tr></thead><tbody>
@foreach($note->lines as $line)
<tr><td>{{ $loop->iteration }}</td><td>{{ $line->invoiceLine?->finishedGood?->name }}</td><td>{{ $line->invoiceLine?->finishedGood?->sku }}</td><td>{{ $line->invoiceLine?->batch?->batch_number }}</td><td>{{ $line->quantity_packages }}</td><td>{{ $line->invoiceLine?->finishedGood?->package_unit }}</td></tr>
@endforeach
</tbody><tfoot><tr><th colspan="4">Total packages</th><th colspan="2">{{ $note->lines->sum('quantity_packages') }}</th></tr></tfoot></table></div>
<p style="margin-top:25px"><strong>Notes:</strong> {{ $note->notes?:'—' }}</p>
@if($note->received_by)<p><strong>Received by:</strong> {{ $note->received_by }} · {{ $note->delivered_at?->format('Y-m-d H:i') }}</p>@endif
<div class="dn-signatures"><div>Warehouse / Dispatch officer<br><small>Name and signature</small></div><div>Customer / Receiver<br><small>Name, stamp and signature</small></div></div>
<p style="font-size:10px;color:#8093a8;margin-top:25px">This delivery note records logistics against a posted sales invoice. It does not create an additional accounting entry or stock movement.</p>
</div>
@endsection

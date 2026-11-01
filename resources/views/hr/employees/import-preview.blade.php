@extends('layouts.kanozay')
@section('content')
<div class="heading">
 <div><h1>Payroll workbook import preview</h1><p>Review totals and row-level validation before applying any employee records.</p></div>
 <div class="actions"><a class="btn btn-secondary" href="{{ route('workspace.page',['hr','employees']) }}">Cancel</a></div>
</div>
<div class="card" style="padding:20px;margin-bottom:16px">
 <h2>Preview summary</h2>
 <p>Rows found: <strong>{{ number_format($preview['total']) }}</strong></p>
 <p>Valid rows: <strong>{{ number_format($preview['valid']) }}</strong></p>
 <p>Salary currency: <strong>{{ $salaryCurrency->code }} — {{ $salaryCurrency->name }}</strong> (selected for this import because the workbook does not identify a currency)</p>
 <p>Rows with errors: <strong>{{ number_format(count(array_unique(array_column($preview['errors'],'row')))) }}</strong></p>
 <p role="note">The tax/transport column is excluded from this import, whether it contains values or formulas. No tax rate or deduction has been calculated.</p>
 @if($preview['formula_count'] > 0)<p role="status" class="notice notice-warning">{{ number_format($preview['formula_count']) }} source formula cells were found in the excluded tax/transport column.</p>@endif
 <p class="muted">Names and personal identifiers are hidden in this preview. The original workbook is not saved by the preview step.</p>
</div>
@if($preview['errors'])
<div class="card" style="padding:20px;margin-bottom:16px">
 <h2>Rows that need correction</h2>
 <div class="table-wrap"><table><thead><tr><th>Workbook row</th><th>Error</th></tr></thead><tbody>
 @foreach($preview['errors'] as $error)<tr><td>{{ $error['row'] }}</td><td>{{ $error['message'] }}</td></tr>@endforeach
 </tbody></table></div>
</div>
@endif
@if($preview['total'] > 0 && $preview['errors'] === [])
<div class="card" style="padding:20px">
 <h2>Confirm import</h2>
 <p>To apply this preview, select the same workbook again. The system checks that its contents have not changed.</p>
 <form method="POST" action="{{ route('hr.employees.import-confirm') }}" enctype="multipart/form-data" class="form-grid">
  @csrf
  <input type="hidden" name="preview_token" value="{{ $previewToken }}">
  <input type="hidden" name="salary_currency_id" value="{{ $salaryCurrencyId }}">
  <label>Re-select the same workbook<input type="file" name="file" accept=".xlsx" required></label>
  <label class="inline-check"><input type="checkbox" name="confirm_import" value="1" required> I reviewed the row counts and confirm importing these employee records.</label>
  <div><button class="btn" type="submit">Import {{ number_format($preview['valid']) }} employees</button></div>
 </form>
</div>
@endif
@endsection

@props(['amount', 'direction' => 'neutral', 'decimals' => 4])
@php
    $value = (float) $amount;
    $precision = max(0, min(12, (int) $decimals));
    $formatted = number_format(abs($value), $precision, '.', ',');
    if (str_contains($formatted, '.')) $formatted = rtrim(rtrim($formatted, '0'), '.');
    if ($precision === 0) $formatted = number_format(abs($value), 0, '.', ',');
    if ($formatted === '') $formatted = '0';
    $kind = in_array($direction, ['in', 'out'], true) ? $direction : 'neutral';
@endphp
<span class="k-finance-amount k-finance-amount--{{ $kind }}" title="{{ $kind === 'in' ? 'Debit / Incoming' : ($kind === 'out' ? 'Credit / Outgoing' : 'Amount') }}">
    @if($kind !== 'neutral' && $value != 0)<i class="fa-solid {{ $kind === 'in' ? 'fa-arrow-down' : 'fa-arrow-up' }}" aria-hidden="true"></i>@endif
    <span>{{ $value < 0 ? '−' : '' }}{{ $formatted }}</span>
</span>

@props(['amount', 'direction' => 'neutral', 'decimals' => 4])
@php
    // Keep significant decimal digits, without adding .0000 to whole amounts.
    $precision = max(0, min(12, (int) $decimals));
    $raw = trim((string) ($amount ?? '0'));
    $negative = str_starts_with($raw, '-');
    $numeric = ltrim($raw, '+-');
    $parts = explode('.', $numeric, 2);
    $whole = preg_replace('/\D/', '', $parts[0]) ?: '0';
    $fraction = isset($parts[1]) ? rtrim(substr(preg_replace('/\D/', '', $parts[1]), 0, $precision), '0') : '';
    $formatted = number_format((float) $whole, 0, '.', ',') . ($fraction !== '' ? '.' . $fraction : '');
    $isZero = !preg_match('/[1-9]/', $whole . $fraction);
    $kind = in_array($direction, ['in', 'out'], true) ? $direction : 'neutral';
@endphp
<span class="k-finance-amount k-finance-amount--{{ $kind }}" title="{{ $kind === 'in' ? 'Debit / Incoming' : ($kind === 'out' ? 'Credit / Outgoing' : 'Amount') }}">
    @if($kind !== 'neutral' && !$isZero)<i class="fa-solid {{ $kind === 'in' ? 'fa-arrow-down' : 'fa-arrow-up' }}" aria-hidden="true"></i>@endif
    <span>{{ $negative && !$isZero ? '−' : '' }}{{ $formatted }}</span>
</span>

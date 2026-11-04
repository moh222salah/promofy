<!doctype html>
<html lang="en" dir="ltr">
<head><meta charset="utf-8"><title>Quotation {{ $q->number }}</title>
<style>body{font-family:Arial,sans-serif;max-width:820px;margin:32px auto;color:#102B5C}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #ddd;padding:8px;text-align:start}.tot td{font-weight:bold}.muted{color:#667085}@media print{body{margin:0}}</style></head>
<body>
<h1>PROMOFY</h1>
<p>Quotation / عرض سعر <strong>{{ $q->number }}</strong> · {{ strtoupper($q->status) }}<br>
{{ $q->created_at->format('Y-m-d') }}@if($q->valid_until) · Valid until / صالح حتى {{ $q->valid_until->format('Y-m-d') }}@endif</p>
<p><strong>{{ $q->customer_name }}</strong>@if($q->customer_company)<br>{{ $q->customer_company }}@endif @if($q->customer_email)<br>{{ $q->customer_email }}@endif</p>
<h2>{{ $q->title }}</h2>
<table><thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Disc %</th><th>Total</th></tr></thead><tbody>
@foreach ($q->items as $i)<tr><td>{{ $i->name_en }}<br><small>{{ $i->name_ar }}</small>@if($i->description)<br><small class="muted">{{ $i->description }}</small>@endif</td><td>{{ rtrim(rtrim($i->quantity, '0'), '.') }}</td><td>{{ $i->unit_price }}</td><td>{{ $i->discount_percent }}</td><td>{{ $i->line_total }}</td></tr>@endforeach
<tr><td colspan="4">Subtotal</td><td>{{ $q->subtotal }}</td></tr>
<tr><td colspan="4">Discount</td><td>-{{ $q->discount_total }}</td></tr>
<tr><td colspan="4">Tax ({{ $q->tax_rate }}%)</td><td>{{ $q->tax_total }}</td></tr>
<tr class="tot"><td colspan="4">Total ({{ $q->currency_code }})</td><td>{{ $q->total }}</td></tr>
</tbody></table>
@if($q->scope)<h3>Scope of work</h3><div>{!! \App\Support\Html\HtmlSanitizer::clean($q->scope) !!}</div>@endif
@if($q->payment_terms)<h3>Payment terms</h3><p>{{ $q->payment_terms }}</p>@endif
@if($q->notes)<p class="muted">{{ $q->notes }}</p>@endif
</body></html>

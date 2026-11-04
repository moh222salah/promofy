<!doctype html>
<html lang="en" dir="ltr">
<head><meta charset="utf-8"><title>Invoice {{ $order->number }}</title>
<style>body{font-family:Arial,sans-serif;max-width:760px;margin:32px auto;color:#102B5C}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #ddd;padding:8px;text-align:start}h1{margin:0}.tot td{font-weight:bold}</style></head>
<body>
<h1>PROMOFY</h1>
<p>Invoice / فاتورة <strong>{{ $order->number }}</strong><br>{{ $order->created_at->format('Y-m-d') }} · {{ strtoupper($order->status) }}</p>
<p>{{ $order->customer_name }}<br>{{ $order->customer_email }}<br>{{ $order->customer_phone }}</p>
<table><thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Total</th></tr></thead><tbody>
@foreach ($order->items as $i)<tr><td>{{ $i->name_en }}<br><small>{{ $i->name_ar }}</small></td><td>{{ $i->quantity }}</td><td>{{ $i->unit_price }}</td><td>{{ $i->line_total }}</td></tr>@endforeach
<tr><td colspan="3">Subtotal</td><td>{{ $order->subtotal }}</td></tr>
<tr><td colspan="3">Discount</td><td>-{{ $order->discount_total }}</td></tr>
<tr><td colspan="3">Tax ({{ $order->tax_rate }}%)</td><td>{{ $order->tax_total }}</td></tr>
<tr class="tot"><td colspan="3">Total ({{ $order->currency_code }})</td><td>{{ $order->total }}</td></tr>
</tbody></table>
</body></html>

@extends('pdf.base')
@section('title', pdf_ar(__('wallet.receipt.title', [], 'ar')).' / Payment Receipt')
@section('content')
<table style="width:100%"><tr>
    <td class="ar" style="width:50%">
        <div><span class="muted">{{ pdf_ar('رقم الإيصال') }}:</span> <b>{{ $payment->receipt_no }}</b></div>
        <div><span class="muted">{{ pdf_ar('التاريخ') }}:</span> {{ display_tz($payment->paid_at)->format('Y-m-d H:i') }} {{ pdf_ar(hijri_date($payment->paid_at)) }}</div>
        <div><span class="muted">{{ pdf_ar('الطالب') }}:</span> {{ pdf_ar($student->full_name) }} ({{ $student->student_no }})</div>
        <div><span class="muted">{{ pdf_ar('ولي الأمر') }}:</span> {{ pdf_ar($student->guardian_name) }} — {{ $student->guardian_phone }}</div>
        <div><span class="muted">{{ pdf_ar('طريقة الدفع') }}:</span> {{ pdf_ar($payment->method->label('ar')) }} @if($payment->reference) — {{ $payment->reference }} @endif</div>
    </td>
    <td class="en" style="width:50%; vertical-align:top">
        <div><span class="muted">Receipt no:</span> <b>{{ $payment->receipt_no }}</b></div>
        <div><span class="muted">Date:</span> {{ display_tz($payment->paid_at)->format('Y-m-d H:i') }}</div>
        <div><span class="muted">Student:</span> {{ $student->student_no }}</div>
        <div><span class="muted">Method:</span> {{ $payment->method->label('en') }}</div>
        <div><span class="muted">Received by:</span> {{ $payment->receiver?->name }}</div>
    </td>
</tr></table>

<div class="box center" style="margin-top:14pt">
    <div class="muted">{{ pdf_ar('المبلغ المستلم') }} / Amount received</div>
    <div style="font-size:24pt; font-weight:bold" class="emerald">{{ number_format($payment->amount_fils / 1000, 3) }} BHD</div>
    <div class="muted">{{ pdf_ar(\App\Support\Money::format($payment->amount_fils, 'ar')) }}</div>
</div>

@if($allocations->isNotEmpty())
<table class="grid">
    <thead><tr>
        <th class="ar">{{ pdf_ar('الفاتورة') }} / Invoice</th>
        <th class="ar">{{ pdf_ar('الوصف') }} / Description</th>
        <th>{{ pdf_ar('المسدد') }} / Allocated (BHD)</th>
        <th>{{ pdf_ar('المتبقي') }} / Remaining (BHD)</th>
    </tr></thead>
    <tbody>
    @foreach($allocations as $a)
        <tr>
            <td class="center">{{ $a->invoice->invoice_no }}</td>
            <td class="ar">{{ pdf_ar($a->invoice->description) }}</td>
            <td class="center">{{ number_format($a->amount_fils / 1000, 3) }}</td>
            <td class="center">{{ number_format($a->invoice->outstandingFils() / 1000, 3) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

<div class="box">
    <table style="width:100%"><tr>
        <td class="ar">{{ pdf_ar('الرصيد بعد الدفع') }}: <b>{{ pdf_ar(\App\Support\Money::format($balanceAfter, 'ar')) }}</b></td>
        <td class="en" style="text-align:right">Balance after payment: <b>{{ number_format($balanceAfter / 1000, 3) }} BHD</b></td>
    </tr></table>
    @if($payment->note)<div class="ar muted" style="margin-top:6pt">{{ pdf_ar($payment->note) }}</div>@endif
</div>
@endsection

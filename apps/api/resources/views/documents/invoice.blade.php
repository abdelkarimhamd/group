{{-- A simplified tax invoice or credit note, Arabic and English side by side. Rendered by App\Modules\Orders\Documents\InvoicePdf with mPDF. --}}
<html>
<head>
<style>
    body { font-family: dejavusans; font-size: 9pt; color: #1f2328; }
    .ar { direction: rtl; text-align: right; }
    .en { text-align: left; }
    .muted { color: #59636e; }
    h1 { font-size: 15pt; margin: 0 0 2mm 0; }
    table { border-collapse: collapse; width: 100%; }
    .head td { vertical-align: top; width: 50%; padding: 0; }
    .meta td { padding: 1.4mm 2mm; border-bottom: 0.2mm solid #d1d9e0; }
    .meta .label { width: 32%; }
    .meta .value { width: 36%; text-align: center; }
    .lines { margin-top: 6mm; }
    .lines th { background: #f6f8fa; border-bottom: 0.3mm solid #8c959f; padding: 1.6mm 1.4mm; font-weight: bold; font-size: 8pt; }
    .lines td { border-bottom: 0.2mm solid #d1d9e0; padding: 1.6mm 1.4mm; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .totals { margin-top: 4mm; width: 62%; }
    .totals td { padding: 1.4mm 2mm; border-bottom: 0.2mm solid #d1d9e0; }
    .totals .grand td { font-weight: bold; font-size: 10.5pt; border-bottom: 0.4mm solid #1f2328; }
    .note { margin-top: 6mm; font-size: 8pt; }
</style>
</head>
<body>

<table class="head">
    <tr>
        <td class="en">
            <h1>{{ $title['en'] }}</h1>
            <div><b>{{ $seller['name_en'] }}</b></div>
            <div class="muted">{{ $seller['address_en'] }}</div>
        </td>
        <td class="ar" lang="ar">
            <h1>{{ $title['ar'] }}</h1>
            <div><b>{{ $seller['name_ar'] }}</b></div>
            <div class="muted">{{ $seller['address_ar'] }}</div>
        </td>
    </tr>
</table>

<table class="meta" style="margin-top: 5mm;">
    <tr>
        <td class="label en muted">{{ $creditNote ? 'Credit note number' : 'Invoice number' }}</td>
        <td class="value">{{ $number }}</td>
        <td class="label ar muted" lang="ar">{{ $creditNote ? 'رقم الإشعار' : 'رقم الفاتورة' }}</td>
    </tr>
    <tr>
        <td class="label en muted">Issue date (Riyadh time)</td>
        <td class="value">{{ $issuedAt }}</td>
        <td class="label ar muted" lang="ar">تاريخ الإصدار (بتوقيت الرياض)</td>
    </tr>
    @if ($originalNumber)
    <tr>
        <td class="label en muted">Against invoice</td>
        <td class="value">{{ $originalNumber }}</td>
        <td class="label ar muted" lang="ar">عن الفاتورة</td>
    </tr>
    @endif
    <tr>
        <td class="label en muted">Order</td>
        <td class="value">{{ $orderNumber }}</td>
        <td class="label ar muted" lang="ar">الطلب</td>
    </tr>
    <tr>
        <td class="label en muted">Seller VAT number</td>
        <td class="value">{{ $seller['vat_number'] }}</td>
        <td class="label ar muted" lang="ar">الرقم الضريبي للبائع</td>
    </tr>
    @if ($seller['cr_number'])
    <tr>
        <td class="label en muted">Commercial registration</td>
        <td class="value">{{ $seller['cr_number'] }}</td>
        <td class="label ar muted" lang="ar">السجل التجاري</td>
    </tr>
    @endif
</table>

<table class="lines">
    <thead>
        <tr>
            <th class="en">Description<br><span lang="ar">الوصف</span></th>
            <th class="num">Qty<br><span lang="ar">الكمية</span></th>
            <th class="num">Unit price incl. VAT<br><span lang="ar">سعر الوحدة شامل الضريبة</span></th>
            <th class="num">Taxable amount<br><span lang="ar">المبلغ الخاضع للضريبة</span></th>
            <th class="num">VAT rate<br><span lang="ar">نسبة الضريبة</span></th>
            <th class="num">VAT<br><span lang="ar">الضريبة</span></th>
            <th class="num">Total<br><span lang="ar">الإجمالي</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
        <tr>
            <td class="en">{{ $line['description_en'] }}<br><span lang="ar">{{ $line['description_ar'] }}</span></td>
            <td class="num">{{ $line['qty'] }}</td>
            <td class="num">{{ $line['unit_price'] }}</td>
            <td class="num">{{ $line['net'] }}</td>
            <td class="num">{{ $line['vat_rate'] }}</td>
            <td class="num">{{ $line['vat'] }}</td>
            <td class="num">{{ $line['total'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<table style="margin-top: 4mm;">
    <tr>
        <td style="width: 38%; vertical-align: top;">
            <barcode code="{{ $qr }}" type="QR" size="1.1" error="M" disableborder="1" />
        </td>
        <td style="vertical-align: top;">
            <table class="totals" style="width: 100%;">
                <tr>
                    <td class="en muted">Total excl. VAT</td>
                    <td class="num">{{ $net }} SAR</td>
                    <td class="ar muted" lang="ar">الإجمالي غير شامل الضريبة</td>
                </tr>
                <tr>
                    <td class="en muted">VAT {{ $vatRate }}</td>
                    <td class="num">{{ $vat }} SAR</td>
                    <td class="ar muted" lang="ar">ضريبة القيمة المضافة {{ $vatRate }}</td>
                </tr>
                <tr class="grand">
                    <td class="en">{{ $creditNote ? 'Total credited incl. VAT' : 'Total incl. VAT' }}</td>
                    <td class="num">{{ $total }} SAR</td>
                    <td class="ar" lang="ar">{{ $creditNote ? 'إجمالي المبلغ المرد شامل الضريبة' : 'الإجمالي شامل الضريبة' }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table class="note">
    <tr>
        <td class="en muted" style="width: 50%;">Prices include {{ $vatRate }} VAT. Amounts are in Saudi riyals.</td>
        <td class="ar muted" lang="ar" style="width: 50%;">الأسعار شاملة ضريبة القيمة المضافة {{ $vatRate }}. المبالغ بالريال السعودي.</td>
    </tr>
</table>

</body>
</html>

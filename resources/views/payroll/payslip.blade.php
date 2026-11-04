<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $slip['reference'] }} — {{ $slip['employee_name'] }}</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eceef1; color: #111; font: 13px Arial, Helvetica, sans-serif; }
        .toolbar { max-width: 1080px; margin: 16px auto; padding: 0 12px; color: #454b56; font: 13px system-ui, sans-serif; }
        .paper { width: min(1080px, calc(100% - 32px)); margin: 20px auto; padding: 26px; background: white; }
        .slip { border: 1.4px solid #111; }
        .company { display: flex; justify-content: space-between; align-items: baseline; gap: 20px; padding: 7px 9px; border-bottom: 1.4px solid #111; }
        .company h1 { font-size: 20px; font-weight: 400; margin: 0; overflow-wrap: anywhere; }
        .period { font-size: 14px; font-weight: 700; text-transform: uppercase; white-space: nowrap; }
        .employee { display: grid; grid-template-columns: 1.45fr 1fr; gap: 26px; padding: 8px 9px 10px; }
        dl { margin: 0; display: grid; grid-template-columns: 165px minmax(0, 1fr); gap: 5px 10px; }
        dt { text-transform: uppercase; }
        dd { margin: 0; overflow-wrap: anywhere; }
        .facts { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 0 9px 10px; }
        .rate { display: grid; grid-template-columns: 165px 100px; gap: 5px 10px; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; text-align: center; align-items: start; }
        .stats span { display: block; margin-top: 5px; }
        .columns { display: grid; grid-template-columns: 53.5% 46.5%; border-top: 1.4px solid #111; }
        .column + .column { border-left: 1.4px solid #111; }
        h2 { margin: 0; font: 400 13px Arial, sans-serif; padding: 7px 9px; border-bottom: 1.4px solid #111; text-transform: uppercase; }
        .lines { padding: 7px 9px; min-height: 240px; }
        .line { display: grid; grid-template-columns: minmax(0, 1fr) 110px; gap: 12px; padding: 3px 0; break-inside: avoid; }
        .line .name { text-transform: uppercase; overflow-wrap: anywhere; }
        .amount { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .totals { display: grid; grid-template-columns: 53.5% 46.5%; border-top: 1.4px solid #111; }
        .totals > div { padding: 5px 9px; }
        .totals > div + div { border-left: 1.4px solid #111; }
        .net { margin-left: 53.5%; border: 1.4px solid #111; border-top: 0; padding: 5px 9px; }
        .net .line { font-weight: 700; }
        .lower { display: grid; grid-template-columns: 37% 1fr; gap: 17%; margin-top: 0; }
        .employer { border: 1.4px solid #111; padding: 6px 9px; margin-top: 0; }
        .employer .line { grid-template-columns: 1fr 100px; }
        .leave { align-self: center; display: grid; gap: 8px; }
        .leave .line { grid-template-columns: 125px 1fr; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 14%; padding: 27px 10px 0; }
        .signature { min-height: 45px; }
        .signature span { display: block; margin-top: 8px; }
        .footer { font-size: 10px; color: #555; padding: 9px 10px 0; line-height: 1.5; }
        @media print {
            body { background: white; font-size: 11px; }
            .toolbar { display: none; }
            .paper { width: 100%; margin: 0; padding: 0; }
            .company h1 { font-size: 18px; }
            .lines { min-height: 210px; }
            .company, .employee, .facts, .totals, .net, .lower, .signatures { break-inside: avoid; }
        }
        @media screen and (max-width: 700px) {
            .paper { min-width: 680px; margin: 12px; padding: 16px; }
            .toolbar { margin-left: 12px; }
        }
    </style>
</head>
<body>
    <div class="toolbar">{{ $slip['sample'] ? 'Template preview — reference figures are sample data, not current statutory rates.' : 'Private payslip. Use your browser’s Print / Save as PDF; select A4 landscape.' }}</div>
    <main class="paper" aria-label="Payslip">
        <section class="slip">
            <header class="company"><h1>{{ $slip['company_name'] }}</h1><div class="period">{{ $slip['sample'] ? 'Sample · ' : 'End payment · ' }}{{ $slip['period_title'] }}</div></header>
            <div class="employee">
                <dl>
                    <dt>Employee no.</dt><dd>{{ $slip['employee_number'] }}</dd>
                    <dt>Name</dt><dd>{{ $slip['employee_name'] }}</dd>
                    <dt>IC / Passport no.</dt><dd>{{ $slip['identity'] }}</dd>
                    <dt>Department</dt><dd>{{ $slip['department'] }}</dd>
                </dl>
                <dl>
                    <dt>Position</dt><dd>{{ $slip['position'] }}</dd>
                    <dt>EPF no.</dt><dd>{{ $slip['epf_number'] }}</dd>
                    <dt>SOCSO no.</dt><dd>{{ $slip['socso_number'] }}</dd>
                    <dt>Tax no.</dt><dd>{{ $slip['tax_number'] }}</dd>
                </dl>
            </div>
            <div class="facts">
                <div class="rate"><span>Monthly base rate</span><span class="amount">{{ $slip['monthly_base'] }}</span><span>Eligible days</span><span class="amount">{{ $slip['eligible_days'] }}</span></div>
                <div class="stats"><div>CALENDAR DAYS<span>{{ $slip['calendar_days'] }}</span></div><div>EARLY OUT<span>—</span></div><div>LATENESS<span>—</span></div><div>UNPAID DAYS<span>{{ $slip['unpaid_days'] }}</span></div></div>
            </div>
            <div class="columns">
                <section class="column"><h2>Earnings / income</h2><div class="lines">@foreach ($slip['earnings'] as $line)<div class="line"><span class="name">{{ $line['label'] }}</span><span class="amount">{{ $line['amount'] }}</span></div>@endforeach</div></section>
                <section class="column"><h2>Deduction</h2><div class="lines">@foreach ($slip['deductions'] as $line)<div class="line"><span class="name">{{ $line['label'] }}</span><span class="amount">{{ $line['amount'] }}</span></div>@endforeach</div></section>
            </div>
            <div class="totals"><div class="line"><span>GROSS PAY</span><span class="amount">{{ $slip['gross'] }}</span></div><div class="line"><span>TOTAL DEDUCTION</span><span class="amount">{{ $slip['total_deductions'] }}</span></div></div>
        </section>
        <div class="net"><div class="line"><span>NET PAY</span><span class="amount">{{ $slip['net'] }}</span></div></div>
        <div class="lower"><div class="employer">@forelse ($slip['employer'] as $line)<div class="line"><span class="name">{{ $line['label'] }}</span><span class="amount">{{ $line['amount'] }}</span></div>@empty<div>EMPLOYER CONTRIBUTIONS —</div>@endforelse</div><div class="leave"><div class="line"><span>YTD ANNUAL LEAVE</span><span>{{ $slip['annual_leave'] }}</span></div><div class="line"><span>YTD MEDICAL LEAVE</span><span>{{ $slip['medical_leave'] }}</span></div></div></div>
        <div class="signatures"><div class="signature">APPROVED BY:<span>{{ $slip['approved_by'] }}</span></div><div class="signature">RECEIVED BY:<span>____________________________</span></div></div>
        <footer class="footer">{{ $slip['reference'] }} · Period {{ $slip['period_from'] }} – {{ $slip['period_to'] }} · Pay date {{ $slip['pay_date'] }} · Currency {{ $slip['currency'] }}<br>{{ $slip['sample'] ? 'LAYOUT SAMPLE ONLY — not an issued payslip.' : 'Issued from approved payroll. This statement does not confirm bank payment or statutory remittance. “—” means not recorded.' }}</footer>
    </main>
</body>
</html>

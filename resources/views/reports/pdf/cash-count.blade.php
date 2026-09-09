@extends('reports.pdf.layout')

@php ($denominations = \App\Support\Reports\CashCountReport::DENOMINATIONS)

@section('body')
    <div class="report-sub">Operational days {{ $report['range']['from'] }} → {{ $report['range']['to'] }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>Op. Date</th>
                <th>Bus</th>
                <th>Shift</th>
                @foreach ($denominations as $d)
                    <th class="num">{{ $d }}</th>
                @endforeach
                <th class="num">Counted (₱)</th>
                <th class="num">Remitted (₱)</th>
                <th class="num">Variance (₱)</th>
                <th class="num">Net Cash (₱)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td>{{ $row['op_date'] }}</td>
                    <td>{{ $row['bus_number'] }}</td>
                    <td>{{ $row['shift'] }}{{ $row['adjusted'] ? ' *' : '' }}</td>
                    @foreach ($denominations as $d)
                        <td class="num">{{ $row['denominations'][$d] ?? 0 }}</td>
                    @endforeach
                    <td class="num">{{ number_format($row['counted_total'], 2) }}</td>
                    <td class="num">{{ number_format($row['remitted_total'], 2) }}</td>
                    <td class="num">{{ number_format($row['variance'], 2) }}</td>
                    <td class="num">{{ number_format($row['net_cash'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ 7 + count($denominations) }}" style="text-align:center;">No cash counts in this range.</td></tr>
            @endforelse
            <tr class="totals">
                <td colspan="3">TOTAL</td>
                @foreach ($denominations as $d)
                    <td class="num">{{ $report['denom_totals'][$d] ?? 0 }}</td>
                @endforeach
                <td class="num">{{ number_format($report['grand_total'], 2) }}</td>
                <td class="num">{{ number_format($report['remitted_total'], 2) }}</td>
                <td class="num"></td>
                <td class="num">{{ number_format($report['net_cash_total'], 2) }}</td>
            </tr>
        </tbody>
    </table>
    <p style="font-size:8px;color:#777;margin-top:6px;">* denominations manually adjusted by a manager.</p>
@endsection

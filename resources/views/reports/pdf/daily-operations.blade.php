@extends('reports.pdf.layout')

@section('body')
    <div class="report-sub">Operational days {{ $report['range']['from'] }} → {{ $report['range']['to'] }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>Bus</th>
                <th class="num">Trips</th>
                <th class="num">Pax</th>
                <th class="num">Terminal (₱)</th>
                <th class="num">Pickup (₱)</th>
                <th class="num">Gross (₱)</th>
                <th class="num">Dispatch (₱)</th>
                <th class="num">Op. Exp. (₱)</th>
                <th class="num">Remaining (₱)</th>
                <th class="num">Cash Counted (₱)</th>
                <th class="num">AM Net (₱)</th>
                <th class="num">PM Net (₱)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td>{{ $row['bus_number'] }}</td>
                    <td class="num">{{ $row['trips_count'] }}</td>
                    <td class="num">{{ $row['passenger_total'] }}</td>
                    <td class="num">{{ number_format($row['terminal_income'], 2) }}</td>
                    <td class="num">{{ number_format($row['pickup_income'], 2) }}</td>
                    <td class="num">{{ number_format($row['gross_income'], 2) }}</td>
                    <td class="num">{{ number_format($row['dispatch_total'], 2) }}</td>
                    <td class="num">{{ number_format($row['operational_expenses'], 2) }}</td>
                    <td class="num">{{ number_format($row['remaining_income'], 2) }}</td>
                    <td class="num">{{ number_format($row['cash_counted'], 2) }}</td>
                    <td class="num">{{ number_format($row['morning_net'], 2) }}</td>
                    <td class="num">{{ number_format($row['evening_net'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="12" style="text-align:center;">No buses.</td></tr>
            @endforelse
            @php ($s = $report['summary'])
            <tr class="totals">
                <td>TOTAL</td>
                <td class="num">{{ $s['trips_count'] }}</td>
                <td class="num">{{ $s['total_passenger'] }}</td>
                <td class="num">{{ number_format($s['terminal'], 2) }}</td>
                <td class="num">{{ number_format($s['pickup'], 2) }}</td>
                <td class="num">{{ number_format($s['gross_income'], 2) }}</td>
                <td class="num">{{ number_format($s['dispatch'], 2) }}</td>
                <td class="num">{{ number_format($s['operational_expenses'], 2) }}</td>
                <td class="num">{{ number_format($s['remaining_income'], 2) }}</td>
                <td class="num">{{ number_format($s['cash_counted'], 2) }}</td>
                <td class="num">{{ number_format($s['morning_net'], 2) }}</td>
                <td class="num">{{ number_format($s['evening_net'], 2) }}</td>
            </tr>
        </tbody>
    </table>
@endsection

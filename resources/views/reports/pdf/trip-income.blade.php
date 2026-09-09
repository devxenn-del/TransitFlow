@extends('reports.pdf.layout')

@section('body')
    <div class="report-sub">
        Bus {{ $report['bus_number'] }} ({{ $report['plate_number'] }}) — {{ $report['date'] }}
    </div>
    <table class="data">
        <thead>
            <tr>
                <th>Reference</th>
                <th>Origin</th>
                <th>Destination</th>
                <th class="num">Terminal</th>
                <th class="num">Pickup</th>
                <th class="num">Pax</th>
                <th class="num">Fares (₱)</th>
                <th class="num">Dispatch (₱)</th>
                <th class="num">Net (₱)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td>{{ $row['reference'] }}</td>
                    <td>{{ $row['origin'] }}</td>
                    <td>{{ $row['destination'] }}</td>
                    <td class="num">{{ $row['terminal_count'] }}</td>
                    <td class="num">{{ $row['pickup_count'] }}</td>
                    <td class="num">{{ $row['passenger_count'] }}</td>
                    <td class="num">{{ number_format($row['fare_total'], 2) }}</td>
                    <td class="num">{{ number_format($row['dispatch_total'], 2) }}</td>
                    <td class="num">{{ number_format($row['net_total'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;">No trips on this date.</td></tr>
            @endforelse
            <tr class="totals">
                <td colspan="5">TOTAL</td>
                <td class="num">{{ $report['total_passengers'] }}</td>
                <td class="num">{{ number_format($report['total_received'], 2) }}</td>
                <td class="num">{{ number_format($report['total_dispatch'], 2) }}</td>
                <td class="num">{{ number_format($report['total_income'], 2) }}</td>
            </tr>
        </tbody>
    </table>
@endsection

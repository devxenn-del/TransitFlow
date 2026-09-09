@extends('reports.pdf.layout')

@section('body')
    <div class="report-sub">{{ ucfirst($report['period']) }} — {{ $report['range_label'] }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>Bus</th>
                <th>Plate</th>
                <th class="num">Trips</th>
                <th class="num">Tickets</th>
                <th class="num">Income (₱)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td>{{ $row['bus_number'] }}</td>
                    <td>{{ $row['plate_number'] }}</td>
                    <td class="num">{{ $row['trip_count'] }}</td>
                    <td class="num">{{ $row['ticket_count'] }}</td>
                    <td class="num">{{ number_format($row['income'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;">No activity in this period.</td></tr>
            @endforelse
            <tr class="totals">
                <td colspan="2">TOTAL</td>
                <td class="num">{{ $report['total_trips'] }}</td>
                <td class="num">{{ $report['total_tickets'] }}</td>
                <td class="num">{{ number_format($report['total_income'], 2) }}</td>
            </tr>
        </tbody>
    </table>
@endsection

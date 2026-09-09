@extends('reports.pdf.layout')

@section('body')
    @php ($range = $report['range'])
    <div class="report-sub">
        {{ $range['from'] ?? 'earliest' }} → {{ $range['to'] ?? 'latest' }}
    </div>

    <div class="section-head">Fuel Purchases</div>
    <table class="data">
        <thead>
            <tr>
                <th>Fueled At</th>
                <th>Bus</th>
                <th>Type</th>
                <th class="num">Litres</th>
                <th class="num">₱/L</th>
                <th class="num">Amount (₱)</th>
                <th>Station</th>
                <th>Recorded By</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['fuel']['rows'] as $row)
                <tr>
                    <td>{{ $row['fueled_at'] }}</td>
                    <td>{{ $row['bus_number'] }}</td>
                    <td>{{ $row['fuel_type'] }}</td>
                    <td class="num">{{ number_format($row['liters'], 2) }}</td>
                    <td class="num">{{ number_format($row['price_per_liter'], 2) }}</td>
                    <td class="num">{{ number_format($row['amount_paid'], 2) }}</td>
                    <td>{{ $row['station'] }}</td>
                    <td>{{ $row['recorded_by_name'] }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;">No fuel purchases.</td></tr>
            @endforelse
            @php ($ft = $report['fuel']['totals'])
            <tr class="totals">
                <td colspan="3">TOTAL ({{ $ft['fills'] }} fills)</td>
                <td class="num">{{ number_format($ft['total_liters'], 2) }}</td>
                <td class="num">{{ $ft['avg_price'] !== null ? number_format($ft['avg_price'], 2) : '—' }}</td>
                <td class="num">{{ number_format($ft['total_cost'], 2) }}</td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>

    <div class="section-head">EV Charging Sessions</div>
    <table class="data">
        <thead>
            <tr>
                <th>Started</th>
                <th>Ended</th>
                <th>Bus</th>
                <th>Status</th>
                <th class="num">Battery %</th>
                <th class="num">Gained %</th>
                <th class="num">Minutes</th>
                <th>Recorded By</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['charging']['rows'] as $row)
                <tr>
                    <td>{{ $row['started_at'] }}</td>
                    <td>{{ $row['ended_at'] }}</td>
                    <td>{{ $row['bus_number'] }}</td>
                    <td>{{ $row['status'] }}</td>
                    <td class="num">{{ $row['battery_start_pct'] }} → {{ $row['battery_end_pct'] ?? '—' }}</td>
                    <td class="num">{{ $row['battery_gained_pct'] ?? '—' }}</td>
                    <td class="num">{{ $row['duration_minutes'] }}</td>
                    <td>{{ $row['recorded_by_name'] }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;">No charging sessions.</td></tr>
            @endforelse
            @php ($ct = $report['charging']['totals'])
            <tr class="totals">
                <td colspan="3">TOTAL</td>
                <td>{{ $ct['active'] }} active / {{ $ct['completed'] }} done</td>
                <td colspan="2"></td>
                <td class="num">{{ $ct['total_minutes'] }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
@endsection

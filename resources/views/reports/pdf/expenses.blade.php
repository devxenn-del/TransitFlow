@extends('reports.pdf.layout')

@section('body')
    <div class="report-sub">Operational days {{ $report['range']['from'] }} → {{ $report['range']['to'] }}</div>

    @forelse ($report['days'] as $day)
        <div class="section-head">{{ $day['op_date'] }} — ₱{{ number_format($day['total'], 2) }}</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Bus</th>
                    <th>Shift</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th class="num">Amount (₱)</th>
                    <th>Recorded By</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($day['items'] as $item)
                    <tr>
                        <td>{{ $item['bus_number'] }}</td>
                        <td>{{ $item['shift'] }}</td>
                        <td>{{ $item['category'] }}</td>
                        <td>{{ $item['description'] }}</td>
                        <td class="num">{{ number_format($item['amount'], 2) }}</td>
                        <td>{{ $item['recorded_by_name'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p style="text-align:center;">No expenses in this range.</p>
    @endforelse

    <div class="section-head" style="text-align:right;">GRAND TOTAL — ₱{{ number_format($report['grand_total'], 2) }}</div>
@endsection

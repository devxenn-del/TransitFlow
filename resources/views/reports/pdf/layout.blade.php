{{-- Shared letterhead + "Prepared By" wrapper for every report PDF (dompdf).
     Ported from BITS App\ReportLetterhead::styles() + App\PreparedBy::pdfCss()
     — docs/MIGRATION_MAP.md §J. --}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: {{ $pageMarginMm ?? 10 }}mm; }
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 10px; color: #16213e; margin: 0; }
    .letterhead { width: 100%; text-align: center; margin-bottom: 4px; }
    .letterhead-logo { display: block; margin: 0 auto 9px; max-width: 96px; max-height: 96px; }
    .letterhead-org-name { font-size: 20px; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; color: #16213e; line-height: 1.15; }
    .letterhead-route { font-size: 8.5px; font-weight: bold; color: #333; margin-top: 5px; line-height: 1.4; text-transform: uppercase; }
    .letterhead-line { font-size: 8px; color: #555; margin-top: 2px; }
    .letterhead-rule { border-top: 1.4px solid #16213e; margin: 8px 0 12px; }

    .report-title { font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: .5px; text-align: center; margin: 0 0 2px; }
    .report-sub { font-size: 9px; color: #555; text-align: center; margin: 0 0 12px; }

    table.data { width: 100%; border-collapse: collapse; }
    table.data th, table.data td { border: 0.6px solid #b9c0cc; padding: 4px 6px; }
    table.data th { background: #f5a623; color: #16213e; font-size: 8.5px; text-transform: uppercase; letter-spacing: .3px; }
    table.data td { font-size: 9px; }
    table.data td.num, table.data th.num { text-align: right; }
    table.data tr.totals td { font-weight: bold; background: #f4f6f9; }
    .section-head { font-size: 10px; font-weight: bold; text-transform: uppercase; margin: 14px 0 4px; }

    .prepared-by { margin-top: 34px; page-break-inside: avoid; text-align: right; }
    .prepared-by-title { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #16213e; margin-bottom: 34px; }
    .prepared-by-name { font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: .5px; color: #16213e; }
    .prepared-by-line { width: 280px; border-bottom: 1px solid #16213e; height: 1px; margin: 1px 0 6px auto; }
    .prepared-by-role { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: .5px; color: #333; }
    .prepared-by-when { font-size: 8.5px; color: #555; margin-top: 4px; }
    @yield('styles')
</style>
</head>
<body>
    @if ($letterhead)
        <div class="letterhead">
            @if ($letterhead['logo_data_uri'])
                <img src="{{ $letterhead['logo_data_uri'] }}" class="letterhead-logo">
            @endif
            <div class="letterhead-org-name">{{ $letterhead['org_name'] }}</div>
            @if ($letterhead['route_line'])
                <div class="letterhead-route">{{ $letterhead['route_line'] }}</div>
            @endif
            @foreach ($letterhead['meta_lines'] as $line)
                <div class="letterhead-line">{{ $line }}</div>
            @endforeach
        </div>
        <div class="letterhead-rule"></div>
    @endif

    <div class="report-title">{{ $title }}</div>
    <div class="report-sub">Generated {{ $preparedBy['when'] }}</div>

    @yield('body')

    @if ($preparedBy['name'])
        <div class="prepared-by">
            <div class="prepared-by-title">Prepared By</div>
            <div class="prepared-by-name">{{ strtoupper($preparedBy['name']) }}</div>
            <div class="prepared-by-line"></div>
            @if ($preparedBy['role'])
                <div class="prepared-by-role">{{ strtoupper($preparedBy['role']) }}</div>
            @endif
            <div class="prepared-by-when">{{ $preparedBy['when'] }}</div>
        </div>
    @endif
</body>
</html>

{{-- Shared by App\Mail\BillingStatementIssued ($paid = false: payment due) and App\Mail\BillingStatementPaid ($paid = true: payment received). --}}
@php
    $company = $statement->company;
    $php = fn ($amount) => 'PHP'.number_format((float) $amount, 2);
    $rows = [
        'Company Code' => $company->code,
        'Billing Period' => $statement->period_start->format('m/d/Y').'-'.$statement->period_end->format('m/d/Y'),
        'Billing Number' => $statement->billing_number,
        ...($paid
            ? [
                'Amount Billed' => $php($statement->total),
                'Amount Paid' => $php($statement->amount_received ?? $statement->total),
                'Payment made last' => ($statement->paid_at ?? now())->timezone(config('app.timezone'))->format('m/d/Y h:i A'),
                'Status' => 'PAID',
            ]
            : ['Amount to Pay' => $php($statement->total), 'Due Date' => $statement->due_on->format('m/d/Y'), 'Status' => 'UNPAID']),
    ];
    $emphasis = $paid ? 'Amount Paid' : 'Amount to Pay';
    // + short (added to the next bill) / − excess (deducted from it)
    $carryOver = $paid ? (float) $statement->carry_over_amount : 0.0;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f3f5;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#212529;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f3f5;padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">
                    <tr>
                        <td style="background:#10151d;color:#f5a623;padding:20px 28px;font-weight:700;font-size:18px;letter-spacing:.02em;">
                            {{ config('app.name') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 14px;font-size:16px;">Hello {{ $company->name }},</p>

                            <p style="margin:0 0 14px;line-height:1.55;">
                                @if ($paid)
                                    We've received your payment for Billing No. {{ $statement->billing_number }}. Thank you!
                                    @if ($carryOver > 0)
                                        <br><span style="color:#b45309;">Your payment was <strong>{{ $php($carryOver) }}</strong> short — this balance will be added to your next bill.</span>
                                    @elseif ($carryOver < 0)
                                        <br><span style="color:#198754;">You paid <strong>{{ $php(-$carryOver) }}</strong> in excess — this will be deducted from your next bill.</span>
                                    @endif
                                @else
                                    Your {{ config('app.name') }} billing statement is ready. Please settle the amount below on or before
                                    <strong>{{ $statement->due_on->format('m/d/Y') }}</strong>.
                                @endif
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%"
                                   style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;margin:8px 0 20px;">
                                @foreach ($rows as $label => $value)
                                    <tr>
                                        <td style="padding:{{ $loop->first ? '14px' : '6px' }} 16px {{ $loop->last ? '14px' : '6px' }};font-size:14px;color:#6c757d;width:42%;">{{ $label }}:</td>
                                        <td style="padding:{{ $loop->first ? '14px' : '6px' }} 16px {{ $loop->last ? '14px' : '6px' }};font-size:{{ $label === $emphasis ? '17px' : '14px' }};font-weight:{{ $label === $emphasis ? '700' : '600' }};{{ $label === 'Status' ? 'color:'.($paid ? '#198754' : '#b45309').';' : '' }}">{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </table>

                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="font-size:14px;margin:0 0 20px;">
                                <tr>
                                    <th align="left" style="padding:6px 0;border-bottom:1px solid #dee2e6;color:#6c757d;font-weight:600;">Fee</th>
                                    <th align="left" style="padding:6px 0;border-bottom:1px solid #dee2e6;color:#6c757d;font-weight:600;">Billing</th>
                                    <th align="right" style="padding:6px 0;border-bottom:1px solid #dee2e6;color:#6c757d;font-weight:600;">Amount</th>
                                </tr>
                                @foreach ($statement->items as $item)
                                    <tr>
                                        <td style="padding:6px 0;border-bottom:1px solid #f1f3f5;">{{ $item->fee_name }}</td>
                                        <td style="padding:6px 0;border-bottom:1px solid #f1f3f5;">{{ $item->billing_frequency?->label($item->billing_interval_months) ?? '—' }}</td>
                                        <td align="right" style="padding:6px 0;border-bottom:1px solid #f1f3f5;">{{ $item->isCarryOver() ? ((float) $item->amount < 0 ? '−'.$php(-$item->amount) : '+'.$php($item->amount)) : ((float) $item->amount === 0.0 ? 'Free' : $php($item->amount)) }}</td>
                                    </tr>
                                @endforeach
                                <tr>
                                    <td colspan="2" align="right" style="padding:8px 0;font-weight:700;">Total</td>
                                    <td align="right" style="padding:8px 0;font-weight:700;">{{ $php($statement->total) }}</td>
                                </tr>
                            </table>

                            <a href="{{ $billingUrl }}"
                               style="display:inline-block;background:#14213b;color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:600;">
                                View in {{ config('app.name') }}
                            </a>

                            <p style="margin:20px 0 0;font-size:13px;color:#6c757d;line-height:1.5;">
                                If the button doesn't work, open this link:<br>
                                <a href="{{ $billingUrl }}" style="color:#3b5ba9;">{{ $billingUrl }}</a>
                            </p>

                            <p style="margin:18px 0 0;font-size:13px;color:#adb5bd;">
                                This is an automated message — please don't reply.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

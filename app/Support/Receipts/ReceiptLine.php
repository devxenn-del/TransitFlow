<?php

namespace App\Support\Receipts;

/**
 * One line of a BITS-layout receipt — ported from BITS Conductor's
 * printing/ReceiptLine.java, so the passenger ticket, terminal receipt,
 * dispatch receipt and trip remittance report come out line-for-line the
 * way BITS prints them (see {@see ReceiptDocument::lines()}).
 *
 * Types, and how every renderer draws them (Android ESC/POS formatter,
 * web ReceiptView):
 *   title          centred, bold
 *   center         centred
 *   plain          left-aligned, wraps (e.g. "Coverage: X - Y")
 *   section        "------ TRIP INFO ------" — dashes flank the label
 *   row            label left, value right
 *   bold_row       same, bold
 *   highlight_row  the one figure a slip is about (TOTAL VALUE): bold,
 *                  double height, framed by "=" rules
 *   hr             "--------------------------------"
 *   footer         centred (the company's ticket footer)
 *
 * @phpstan-type Line array{type: string, text?: string, label?: string, value?: string}
 */
final class ReceiptLine
{
    /** @return Line */
    public static function title(string $text): array
    {
        return ['type' => 'title', 'text' => $text];
    }

    /** @return Line */
    public static function center(string $text): array
    {
        return ['type' => 'center', 'text' => $text];
    }

    /** @return Line */
    public static function plain(string $text): array
    {
        return ['type' => 'plain', 'text' => $text];
    }

    /** @return Line */
    public static function section(string $label): array
    {
        return ['type' => 'section', 'text' => $label];
    }

    /** @return Line */
    public static function row(string $label, string $value): array
    {
        return ['type' => 'row', 'label' => $label, 'value' => $value];
    }

    /** @return Line */
    public static function boldRow(string $label, string $value): array
    {
        return ['type' => 'bold_row', 'label' => $label, 'value' => $value];
    }

    /** @return Line */
    public static function highlightRow(string $label, string $value): array
    {
        return ['type' => 'highlight_row', 'label' => $label, 'value' => $value];
    }

    /** @return Line */
    public static function hr(): array
    {
        return ['type' => 'hr'];
    }

    /** @return Line */
    public static function footer(string $text): array
    {
        return ['type' => 'footer', 'text' => $text];
    }
}

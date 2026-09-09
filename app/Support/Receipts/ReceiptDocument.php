<?php

namespace App\Support\Receipts;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A single thermal receipt, expressed as a printer-agnostic DTO. The SPA
 * (and later the mobile app) renders this shape at `receipt_width_mm` — the
 * BITS `receipt/conductor/*.php` layouts become data here, not markup.
 *
 * Shape:
 *   kind        machine slug ("departure", "ticket", …)
 *   title       centred bold header line
 *   org         branding block ({@see ReceiptFactory::brand()})
 *   width_mm    paper width from company settings
 *   printed_at  ISO-8601 render timestamp
 *   reference   optional monospace reference line under the header
 *   sections    [{ heading, rows: [{ label, value, strong, total, divider }] }]
 *   note        centred footer line (ticket footer / thank-you)
 *
 * @phpstan-type ReceiptRow array{label: string, value: string, strong?: bool, total?: bool, divider?: bool}
 * @phpstan-type ReceiptSection array{heading: ?string, rows: list<ReceiptRow>}
 */
class ReceiptDocument implements Arrayable
{
    /** @var list<ReceiptSection> */
    private array $sections = [];

    private ?string $reference = null;

    private ?string $note = null;

    private ?string $subtitle = null;

    /**
     * @param  array<string, mixed>  $org
     */
    public function __construct(
        private readonly string $kind,
        private readonly string $title,
        private readonly array $org,
        private readonly float $widthMm,
    ) {}

    /**
     * @param  array<string, mixed>  $org
     */
    public static function make(string $kind, string $title, array $org, float $widthMm): self
    {
        return new self($kind, $title, $org, $widthMm);
    }

    public function reference(?string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    /** A second, smaller line under the title (e.g. "ARRIVAL SUCCESSFULLY CLOSED"). */
    public function subtitle(?string $subtitle): self
    {
        $this->subtitle = $subtitle;

        return $this;
    }

    public function note(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    /**
     * Add a titled block of label/value rows. `$rows` accepts:
     *   'Label' => 'value'                        plain row
     *   'Label' => ['value', strong: bool, total: bool]
     *   '---'   => true                           horizontal rule
     *
     * @param  array<string, mixed>  $rows
     */
    public function section(?string $heading, array $rows): self
    {
        $built = [];

        foreach ($rows as $label => $value) {
            if ($value === true && str_starts_with((string) $label, '---')) {
                $built[] = ['label' => '', 'value' => '', 'divider' => true];

                continue;
            }

            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $built[] = [
                    'label' => (string) $label,
                    'value' => (string) ($value[0] ?? ''),
                    'strong' => (bool) ($value['strong'] ?? false),
                    'total' => (bool) ($value['total'] ?? false),
                ];

                continue;
            }

            $built[] = ['label' => (string) $label, 'value' => (string) $value];
        }

        $this->sections[] = ['heading' => $heading, 'rows' => $built];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'org' => $this->org,
            'width_mm' => $this->widthMm,
            'reference' => $this->reference,
            'sections' => $this->sections,
            'note' => $this->note,
            'printed_at' => now()->toIso8601String(),
        ];
    }
}

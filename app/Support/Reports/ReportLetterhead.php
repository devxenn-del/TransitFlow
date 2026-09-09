<?php

namespace App\Support\Reports;

use App\Models\Company;
use App\Models\Franchise;

/**
 * Assembles the shared letterhead (official header) for exported PDF reports
 * from a company's `company_settings` row — the multi-tenant replacement for
 * BITS' single global Organization Details (BITS `App\ReportLetterhead`,
 * docs/MIGRATION_MAP.md §J).
 *
 * Falls back field by field: a company that has filled in nothing still gets
 * a usable (sparser) header off its own name.
 */
class ReportLetterhead
{
    /**
     * @return array{
     *     org_name: string,
     *     logo_data_uri: ?string,
     *     route_line: string,
     *     meta_lines: list<string>
     * }
     */
    public static function forCompany(Company $company, ?Franchise $franchise = null): array
    {
        $settings = $company->settings;

        $orgName = trim((string) ($settings?->receipt_org_name ?: '')) ?: $company->name;
        $email = trim((string) ($settings?->org_email ?? ''));
        $contact = trim((string) ($settings?->org_contact_number ?? ''));
        $registration = trim((string) ($settings?->registration_number ?? ''));
        $otc = trim((string) ($settings?->otc_accreditation_number ?? ''));

        $routeLine = '';
        $metaLines = [];

        if ($franchise !== null) {
            $routeLine = self::franchiseRouteLabel($franchise);

            if (trim((string) ($franchise->case_no ?? '')) !== '') {
                $metaLines[] = 'CASE NO: '.$franchise->case_no;
            }
            if ($email !== '') {
                $metaLines[] = $email;
            }
        } else {
            $regBits = [];
            if ($registration !== '') {
                $regBits[] = 'Reg. No. '.$registration;
            }
            if ($otc !== '') {
                $regBits[] = 'OTC Accreditation No. '.$otc;
            }
            if ($regBits !== []) {
                $metaLines[] = implode('  |  ', $regBits);
            }

            $contactBits = array_filter([$contact, $email]);
            if ($contactBits !== []) {
                $metaLines[] = implode('  |  ', $contactBits);
            }
        }

        return [
            'org_name' => $orgName,
            'logo_data_uri' => self::logoDataUri($settings?->logo_path),
            'route_line' => $routeLine,
            'meta_lines' => array_values($metaLines),
        ];
    }

    /**
     * The ROUTE line for a franchise: its `route_description` verbatim when
     * set, otherwise "ORIGIN - DESTINATION VICE VERSA" from its endpoints.
     */
    public static function franchiseRouteLabel(Franchise $franchise): string
    {
        $description = trim((string) ($franchise->route_description ?? ''));
        if ($description !== '') {
            return $description;
        }

        $origin = trim((string) ($franchise->route_origin ?? ''));
        $destination = trim((string) ($franchise->route_destination ?? ''));

        if ($origin !== '' && $destination !== '') {
            return $origin.' - '.$destination.' VICE VERSA';
        }

        return $origin !== '' ? $origin : $destination;
    }

    /**
     * Embeds a stored logo as a base64 data URI so dompdf never resolves a
     * filesystem/remote path. Returns null when there is no logo or it is an
     * unsupported (e.g. SVG) format.
     */
    private static function logoDataUri(?string $logoPath): ?string
    {
        if (! $logoPath) {
            return null;
        }

        $path = storage_path('app/public/'.ltrim($logoPath, '/'));
        if (! is_file($path)) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => null,
        };
        if ($mime === null) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}

<?php

namespace Database\Seeders;

use App\Models\LegalDocument;
use App\Models\User;
use App\Support\LegalDocuments;
use Illuminate\Database\Seeder;

/**
 * Seeds version 1.0 of the Privacy Policy and Terms of Use so the app has
 * something to show immediately after a fresh install. This is a DRAFT —
 * every bracketed placeholder ([COMPANY LEGAL NAME], [PRIVACY EMAIL], etc.)
 * must be filled in and the whole text reviewed by qualified legal counsel
 * (Philippine Data Privacy Act compliance in particular) before production
 * use. Once published, further edits are made through the Super Admin
 * "Legal Documents" page (App\Support\LegalDocuments::publish()), not by
 * re-running this seeder — it is idempotent (skips a type that already has
 * a published version) so re-running it after go-live is harmless.
 */
class LegalDocumentSeeder extends Seeder
{
    public function run(): void
    {
        // A system actor to attribute the seeded rows to — falls back to
        // whichever user exists first (e.g. the Platform seeder's Super
        // Admin) since a fresh install may not have one yet at this point.
        $actor = User::query()->first();

        if ($actor === null) {
            return;
        }

        if (LegalDocument::activeVersion(LegalDocument::TYPE_PRIVACY_POLICY) === null) {
            LegalDocuments::publish(
                LegalDocument::TYPE_PRIVACY_POLICY,
                'Privacy Policy',
                $this->privacyPolicyContent(),
                '2026-09-17',
                $actor,
            );
        }

        if (LegalDocument::activeVersion(LegalDocument::TYPE_TERMS_OF_USE) === null) {
            LegalDocuments::publish(
                LegalDocument::TYPE_TERMS_OF_USE,
                'Terms of Use',
                $this->termsOfUseContent(),
                '2026-09-17',
                $actor,
            );
        }
    }

    private function privacyPolicyContent(): string
    {
        return <<<'TEXT'
This document is a DRAFT prepared for internal review. It must be reviewed by qualified legal counsel — particularly for compliance with the Philippine Data Privacy Act of 2012 (RA 10173), its IRR, and NPC issuances — before production publication. It is not a substitute for legal advice.

## 1. Introduction

TransitFlow ("TransitFlow", "we", "us", or "our") is a transportation management and ticketing platform used by transportation companies and their authorized personnel, including drivers, conductors, dispatchers, and administrative staff. This Privacy Policy describes how TransitFlow handles information when you create or use an account, log into the TransitFlow mobile application or web portal, operate transportation services, issue tickets, record trips, use GPS/location features, use Bluetooth printers, use attendance/clock-in features, communicate with TransitFlow's servers and API, or use other TransitFlow functionality.

This Policy applies to the TransitFlow mobile application and, where noted, the TransitFlow web portal. If you do not agree with this Policy, please do not use TransitFlow.

## 2. Account Information

Depending on your role and your company's configuration, TransitFlow may process: name, email address, employee/driver/conductor code, account ID, role and assigned permissions, company/organization assignment, password credentials stored in securely hashed form, account status, and login information.

Passwords are never stored in plaintext. They are securely hashed and are never exposed through the application or API.

## 3. Driver and Conductor Information

TransitFlow may process operational information associated with drivers and conductors, including driver/conductor identity and driver code, assigned bus, assigned route, trip assignment, attendance / clock-in and clock-out information, trip activity, ticketing activity, and other operational activity.

This information is used for legitimate transportation operations, scheduling, attendance, accountability, reporting, and system security.

## 4. Location Information

TransitFlow may request location permission because GPS/location information can be required for live vehicle monitoring, trip tracking, vehicle location display, route monitoring, operational verification, and safety and operational purposes.

Location access may continue while the application is actively operating a trip or performing a supported background operational function, depending on your device's platform, settings, and the permissions you grant. Whether and how often location is collected depends on which features you use, the permissions you grant, your device settings, operating-system restrictions, and your company's configuration. TransitFlow does not collect location continuously outside of these operational contexts.

## 5. Bluetooth / Nearby Devices

TransitFlow may request Bluetooth or nearby-devices permissions to support compatible hardware, such as Bluetooth thermal/ticket printers and other authorized transportation hardware. Bluetooth access is used only to discover and connect to supported devices you choose to pair — it is not used to collect unrelated Bluetooth information from nearby devices.

## 6. Device Information

TransitFlow may process limited technical information required for application operation, security, troubleshooting, and compatibility, such as device model, operating system version, application version, device-generated identifiers where necessary for the app to function, network connectivity status, crash/error information, and application configuration. Only information actually required for these purposes is collected.

## 7. Ticketing and Transaction Information

TransitFlow may process operational ticket information such as ticket number, origin, destination, fare, passenger type, associated trip/bus/driver/conductor, date/time, ticket status, and payment-related operational information recorded for reconciliation. This is required for transportation ticketing, reporting, reconciliation, and operational management. TransitFlow does not collect or process payment-card information.

## 8. Attendance Information

Where attendance functionality is used, TransitFlow may process clock-in time, clock-out time, the assigned employee, assigned bus, attendance status, operational day, and related trip activity, used for operational attendance and accountability.

## 9. How We Use Information

TransitFlow may use information to authenticate users and secure accounts; provide transportation services; manage accounts, buses, drivers, and conductors; track active trips and provide live operational monitoring; issue and validate tickets and calculate fares; maintain attendance records; generate operational reports; process remittances and cash-count information where applicable; prevent unauthorized access and detect misuse; troubleshoot technical problems and maintain application security; improve reliability; and comply with applicable legal obligations.

TransitFlow does not use your information for advertising, and does not sell personal information to third parties.

## 10. Data Sharing

Transportation Company: information may be accessible to the transportation company or organization that operates your TransitFlow account, according to their role and permissions within the platform.

Service Providers: information may be processed by authorized service providers required to operate infrastructure, hosting, or security services on our behalf, under obligations to protect it.

Legal Requirements: information may be disclosed when required by applicable law, regulation, court order, or lawful government request.

Business Transfers: if TransitFlow or an operating company is involved in a merger, acquisition, or similar transaction, information may be transferred as part of that transaction, subject to applicable law.

TransitFlow does not sell personal information to third parties for advertising purposes.

## 11. Data Retention

Information is retained only for as long as reasonably necessary to provide the service, support transportation operations, accounting and reconciliation, security, legal obligations, and dispute resolution. Different categories of records may be retained for different periods. Where a transportation company configures its own retention practices, those company policies may also apply.

## 12. Data Security

We use reasonable technical and organizational safeguards to protect information, including authentication, access control, role-based permissions, secure password storage, encrypted network communication where supported, server-side authorization checks, and security logging/monitoring. However, no method of transmitting or storing information can be guaranteed to be completely secure.

## 13. Your Rights

Where the Philippine Data Privacy Act of 2012 (RA 10173) or other applicable law applies, you may have rights including the right to be informed; the right to access your information; the right to correct inaccurate information; the right to object to processing, where applicable; the right to request erasure or blocking, where applicable; the right to data portability, where applicable; and the right to file a complaint with the National Privacy Commission or other applicable authority. These rights apply to the extent provided by applicable law and are not expanded beyond what the law requires.

## 14. Account Deletion / Data Requests

To request account deletion, correction of information, access to your information, or to make any other privacy-related inquiry, contact us using the details in Section 17. We will respond in accordance with applicable law.

## 15. Children's Privacy

TransitFlow is intended for authorized transportation personnel and operational users. It is not intended for children to independently create or operate accounts, and we do not knowingly collect information from children.

## 16. Cookies and Similar Technologies

The TransitFlow mobile application does not use browser cookies. Web-based portions of TransitFlow (the company/admin web portal) may use cookies or similar technologies for authentication/session management, security, preferences, and performance monitoring.

## 17. Contact Information

Company: [COMPANY LEGAL NAME]
Email: [PRIVACY EMAIL]
Address: [COMPANY ADDRESS]
Data Protection Officer: [DPO NAME / CONTACT]

## 18. Changes to This Policy

TransitFlow may update this Privacy Policy from time to time. When material changes occur, we will update the policy version and effective date, and where required, request your renewed acknowledgment.
TEXT;
    }

    private function termsOfUseContent(): string
    {
        return <<<'TEXT'
This document is a DRAFT prepared for internal review and must be reviewed by qualified legal counsel before production publication, particularly Sections 10 (Limitation of Liability) and 11 (Governing Law).

## 1. Acceptance of Terms

By accessing or using TransitFlow, you agree to these Terms of Use. If you do not agree, do not use TransitFlow.

## 2. Eligibility and Authorized Use

TransitFlow is intended for authorized users only. You must have a valid account, use accurate credentials, follow your organization's policies, use the system only for authorized transportation operations, and keep your login credentials secure. You must not allow unauthorized persons to use your account.

## 3. Account Security

You are responsible for protecting your email, password, driver code, PIN, device access, and other authentication credentials. You must immediately report any suspected unauthorized access to your account.

## 4. Acceptable Use

You must not attempt unauthorized access to the system; circumvent permissions or access controls; manipulate tickets or modify fares without authorization; falsify trip information or attendance records; manipulate GPS/location information; access another user's account; interfere with system operation; reverse engineer the system where prohibited by law; upload malicious software; abuse APIs or circumvent rate limits; attempt to access another transportation company's data; or use the system for any unlawful purpose.

## 5. Company Data Separation

TransitFlow supports multiple transportation companies. You may only access information belonging to your authorized company/organization. The system enforces company-level data isolation on the backend; a user cannot access another company's buses, drivers, conductors, tickets, trips, reports, remittances, accounts, or operational data by manipulating client requests. Authorization is enforced server-side.

## 6. Transportation Operations

TransitFlow provides software tools that may assist with trip management, ticketing, fare calculation, vehicle monitoring, attendance, reporting, remittance, and operational management. TransitFlow does not replace the transportation company's responsibility for safe transportation operations, regulatory compliance, driver qualification, vehicle maintenance, or other legal obligations.

## 7. GPS and Location Disclaimer

GPS information can be affected by device hardware, signal availability, weather/environment, indoor operation, network connectivity, operating-system restrictions, and user permissions. TransitFlow does not guarantee that GPS information will always be perfectly accurate or continuously available.

## 8. Bluetooth Printers

Supported Bluetooth printers and external devices may depend on device compatibility, Bluetooth availability, operating-system permissions, printer condition, connectivity, and manufacturer behavior. TransitFlow does not guarantee compatibility with every Bluetooth device.

## 9. Ticketing Disclaimer

TransitFlow provides software for recording and processing ticketing information. The transportation company remains responsible for fare policies, passenger policies, regulatory compliance, ticket validity rules, revenue reconciliation, and transportation operations.

## 10. Availability and Updates

TransitFlow may occasionally be unavailable due to maintenance, network outages, server issues, third-party service interruptions, device problems, or emergency security measures. We do not guarantee uninterrupted service. TransitFlow may release updates that add features, improve security, fix bugs, or modify functionality; you may need to install updates to continue using certain functionality.

## 11. Intellectual Property

The TransitFlow software, design, branding, documentation, source code, graphics, and related materials are protected by applicable intellectual-property laws. You receive permission to use the application only as authorized; you do not receive ownership of the software.

## 12. Suspension or Termination

TransitFlow or your authorized transportation company may suspend or terminate your access for security violations, unauthorized access, abuse, fraudulent activity, violation of these Terms, or legal requirements, following your company's configured administrative policies.

## 13. Limitation of Liability

To the extent permitted by applicable law, TransitFlow is not responsible for losses resulting from device failure, network failure, incorrect GPS information, printer failure, unauthorized user actions, incorrect information entered by users, third-party service interruptions, or circumstances outside our reasonable control. This section must be reviewed by qualified legal counsel before production publication.

## 14. Governing Law

[GOVERNING LAW / JURISDICTION]. This section must be reviewed by Philippine legal counsel before specifying the final governing law and venue.

## 15. Changes to These Terms

TransitFlow may update these Terms of Use from time to time. We will update the terms version and effective date, and where a new version requires renewed consent, you will be asked to re-accept before continuing to use the application.
TEXT;
    }
}

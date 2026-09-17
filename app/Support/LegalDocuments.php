<?php

namespace App\Support;

use App\Models\LegalDocument;
use App\Models\LegalDocumentAcceptance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Publish/version the Privacy Policy and Terms of Use, and record a user's
 * acceptance of them. Platform-wide (no company scoping) — see
 * `legal_documents`/`legal_document_acceptances` migrations. Mirrors the
 * validate→apply→audit shape of App\Support\ServerConfig.
 */
class LegalDocuments
{
    /**
     * @return array{privacy_policy: ?array{version:int, title:string, effective_date:string}, terms_of_use: ?array{version:int, title:string, effective_date:string}}
     */
    public static function activeSummary(): array
    {
        return [
            LegalDocument::TYPE_PRIVACY_POLICY => self::summarize(LegalDocument::activeVersion(LegalDocument::TYPE_PRIVACY_POLICY)),
            LegalDocument::TYPE_TERMS_OF_USE => self::summarize(LegalDocument::activeVersion(LegalDocument::TYPE_TERMS_OF_USE)),
        ];
    }

    /**
     * Creates the next version for `$type`, deactivating whichever version
     * was previously active. The previous row is kept (never deleted) so
     * historical acceptances stay meaningful.
     */
    public static function publish(string $type, string $title, string $content, string $effectiveDate, User $actor): LegalDocument
    {
        return DB::transaction(function () use ($type, $title, $content, $effectiveDate, $actor) {
            $previous = LegalDocument::activeVersion($type);

            LegalDocument::query()->where('type', $type)->where('is_active', true)->update(['is_active' => false]);

            $document = LegalDocument::query()->create([
                'type' => $type,
                'version' => ($previous?->version ?? 0) + 1,
                'title' => $title,
                'content' => $content,
                'effective_date' => $effectiveDate,
                'is_active' => true,
                'published_by' => $actor->id,
            ]);

            Audit::record('legal_documents.published', $document, [
                'type' => $type,
                'previous_version' => $previous?->version,
                'new_version' => $document->version,
            ], actor: $actor);

            return $document;
        });
    }

    public static function recordAcceptance(
        User $user,
        ?int $privacyVersion,
        ?int $termsVersion,
        ?string $appVersion,
        string $platform,
    ): LegalDocumentAcceptance {
        $acceptance = LegalDocumentAcceptance::query()->create([
            'user_id' => $user->id,
            'privacy_policy_version' => $privacyVersion,
            'terms_version' => $termsVersion,
            'accepted_at' => now(),
            'application_version' => $appVersion,
            'platform' => $platform,
        ]);

        Audit::record('legal_documents.accepted', $acceptance, [
            'privacy_policy_version' => $privacyVersion,
            'terms_version' => $termsVersion,
            'platform' => $platform,
        ], actor: $user);

        return $acceptance;
    }

    /**
     * @return ?array{version:int, title:string, effective_date:string}
     */
    private static function summarize(?LegalDocument $document): ?array
    {
        if ($document === null) {
            return null;
        }

        return [
            'version' => $document->version,
            'title' => $document->title,
            'effective_date' => $document->effective_date->toDateString(),
        ];
    }
}

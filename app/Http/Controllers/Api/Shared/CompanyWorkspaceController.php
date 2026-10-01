<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\BusResource;
use App\Http\Resources\CompanyDocumentResource;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\TripMonitorResource;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Bus;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\Trip;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The company workspace (`/companies/{company}` in the SPA): the selected
 * company's header details, its overview counts and recent activity.
 *
 * Reachable by the Super Admin for any company, and by a company user for
 * their OWN company only — CompanyPolicy::view, so hand-editing the id in
 * the URL to another company is a 403 here. Every figure is computed
 * inside that company (CompanyContext::actAsCompany), and each "recent"
 * list is only included when the caller may see that module.
 */
class CompanyWorkspaceController extends Controller
{
    private const RECENT_LIMIT = 5;

    public function show(Request $request, Company $company, CompanyContext $context): JsonResponse
    {
        $this->authorize('view', $company);

        $user = $request->user();

        return $context->actAsCompany($company->id, fn () => response()->json([
            'data' => CompanyResource::make(
                $company->load('settings')->loadCount(['users', 'drivers', 'conductors', 'buses', 'documents'])
            ),
            'stats' => [
                'users' => $company->users_count,
                'drivers' => $company->drivers_count,
                'conductors' => $company->conductors_count,
                'buses' => $company->buses_count,
                'documents' => $company->documents_count,
                'active_trips' => Trip::query()->live()->count(),
                'tickets' => $company->tickets()->count(),
                'tickets_today' => $company->tickets()->whereDate('issued_at', today())->count(),
                'expiring_documents' => CompanyDocument::query()
                    ->whereNotNull('expires_at')
                    ->whereDate('expires_at', '<=', today()->addDays(CompanyDocument::EXPIRING_WITHIN_DAYS))
                    ->count(),
            ],
            'recent' => [
                'users' => $user->can('accounts.view')
                    ? UserResource::collection(User::query()->with('accessRole')->latest()->limit(self::RECENT_LIMIT)->get())
                    : null,
                'documents' => $user->can('documents.view')
                    ? CompanyDocumentResource::collection(CompanyDocument::query()->with('uploader')->latest()->limit(self::RECENT_LIMIT)->get())
                    : null,
                'buses' => $user->can('buses.view')
                    ? BusResource::collection(Bus::query()->latest()->limit(self::RECENT_LIMIT)->get())
                    : null,
                'trips' => $user->can('tripmonitoring.view')
                    ? TripMonitorResource::collection(Trip::query()->with(['conductor', 'driver'])->latest('started_at')->limit(self::RECENT_LIMIT)->get())
                    : null,
                'activity' => $user->can('audit.view')
                    ? AuditLogResource::collection(AuditLog::query()->where('company_id', $company->id)->visibleTo($user)->latest('created_at')->limit(8)->get())
                    : null,
            ],
        ]));
    }
}

<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Resources\MobileAppResource;
use App\Models\MobileAppSetting;

/**
 * Read-only view of the platform's one mobile-app distribution settings —
 * lets a Company Admin / Chairman see the currently published version,
 * force-update state, and download link their conductors are on. The app
 * itself is the same build for every company; only the Super Admin
 * publishes it — see `Api\SuperAdmin\MobileAppController`.
 *
 * Route-gated by `permission:mobileapp.view`.
 */
class MobileAppController extends Controller
{
    public function show(): MobileAppResource
    {
        return MobileAppResource::make(MobileAppSetting::current());
    }
}

<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\TestServerConfigRequest;
use App\Http\Requests\SuperAdmin\UpdateServerConfigRequest;
use App\Http\Resources\SystemSettingHistoryResource;
use App\Models\SystemSettingHistory;
use App\Support\ServerConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Super Admin "System Configuration" — the platform-wide default API base
 * URL the mobile app is told to use (docs/PARITY_CHECKLIST.md §K). See
 * App\Support\ServerConfig for the validate → test → apply rules and the
 * rollback mechanics.
 */
class SystemConfigurationController extends Controller
{
    public function show(): JsonResponse
    {
        $url = ServerConfig::current();
        $lastVerified = ServerConfig::lastVerifiedAt();

        return response()->json([
            'data' => [
                'api_base_url' => $url,
                'status' => $url === null ? 'unset' : 'connected',
                'last_verified_at' => $lastVerified,
                'configuration_version' => ServerConfig::version(),
            ],
        ]);
    }

    /** Pure connectivity check — never writes anything. */
    public function test(TestServerConfigRequest $request): JsonResponse
    {
        return response()->json(['data' => ServerConfig::testConnection($request->string('url')->value())]);
    }

    public function update(UpdateServerConfigRequest $request): JsonResponse
    {
        ServerConfig::update($request->string('api_base_url')->value(), $request->user());

        return $this->show();
    }

    public function history(Request $request): AnonymousResourceCollection
    {
        $rows = SystemSettingHistory::query()
            ->where('setting_key', ServerConfig::KEY_API_BASE_URL)
            ->with('changedBy')
            ->latest('created_at')
            ->paginate($request->integer('per_page', 20));

        return SystemSettingHistoryResource::collection($rows);
    }

    public function rollback(Request $request, SystemSettingHistory $history): JsonResponse
    {
        ServerConfig::rollback($history, $request->user());

        return $this->show();
    }
}

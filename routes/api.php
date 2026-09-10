<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Company\AdminBusAssignmentController;
use App\Http\Controllers\Api\Company\AttendanceController as CompanyAttendanceController;
use App\Http\Controllers\Api\Company\AuditLogController;
use App\Http\Controllers\Api\Company\BusController;
use App\Http\Controllers\Api\Company\CashCountController;
use App\Http\Controllers\Api\Company\CompanyProfileController;
use App\Http\Controllers\Api\Company\CompanySettingController;
use App\Http\Controllers\Api\Company\ConductorBusController;
use App\Http\Controllers\Api\Company\ConfigurationController;
use App\Http\Controllers\Api\Company\DashboardController;
use App\Http\Controllers\Api\Company\DataToolsController;
use App\Http\Controllers\Api\Company\DeviceController;
use App\Http\Controllers\Api\Company\DriverController;
use App\Http\Controllers\Api\Company\EvChargingController;
use App\Http\Controllers\Api\Company\FareMatrixGridController;
use App\Http\Controllers\Api\Company\FareMatrixImportExportController;
use App\Http\Controllers\Api\Company\FranchiseController;
use App\Http\Controllers\Api\Company\FuelRecordController;
use App\Http\Controllers\Api\Company\LiveMonitorController;
use App\Http\Controllers\Api\Company\MobileAppController;
use App\Http\Controllers\Api\Company\OpExpenseController;
use App\Http\Controllers\Api\Company\PassengerTypeArticleController;
use App\Http\Controllers\Api\Company\PassengerTypeController;
use App\Http\Controllers\Api\Company\PermissionController;
use App\Http\Controllers\Api\Company\RemittanceController;
use App\Http\Controllers\Api\Company\ReportController;
use App\Http\Controllers\Api\Company\RoleController as CompanyRoleController;
use App\Http\Controllers\Api\Company\RouteController as CompanyRouteController;
use App\Http\Controllers\Api\Company\RouteStopController;
use App\Http\Controllers\Api\Company\TerminalController;
use App\Http\Controllers\Api\Company\ThermalPrinterController;
use App\Http\Controllers\Api\Company\TripMonitorController;
use App\Http\Controllers\Api\Company\UserController as CompanyUserController;
use App\Http\Controllers\Api\Company\VoidPinController;
use App\Http\Controllers\Api\Company\VoidSecurityController;
use App\Http\Controllers\Api\Conductor\AttendanceController as ConductorAttendanceController;
use App\Http\Controllers\Api\Conductor\DeviceController as ConductorDeviceController;
use App\Http\Controllers\Api\Conductor\DispatchController;
use App\Http\Controllers\Api\Conductor\LocationController;
use App\Http\Controllers\Api\Conductor\LookupController;
use App\Http\Controllers\Api\Conductor\TicketController;
use App\Http\Controllers\Api\Conductor\TripController;
use App\Http\Controllers\Api\Meta\ServerConfigController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\Shared\NavController;
use App\Http\Controllers\Api\Shared\RoleController;
use App\Http\Controllers\Api\SuperAdmin\AuditLogController as PlatformAuditLogController;
use App\Http\Controllers\Api\SuperAdmin\CompanyController;
use App\Http\Controllers\Api\SuperAdmin\CompanyPermissionController;
use App\Http\Controllers\Api\SuperAdmin\SystemConfigurationController;
use App\Http\Controllers\Api\SuperAdmin\UserController as PlatformUserController;
use App\Http\Middleware\EnsureAccountNotLocked;
use App\Http\Middleware\RequirePasswordChange;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

/**
 * Register the 5 standard CRUD routes for a company resource, each guarded
 * by the matching BITS permission key ({prefix}.view / .create / .edit /
 * .delete).
 */
$companyCrud = function (string $uri, string $controller, string $permPrefix, string $param): void {
    Route::get($uri, [$controller, 'index'])->middleware("permission:{$permPrefix}.view");
    Route::post($uri, [$controller, 'store'])->middleware("permission:{$permPrefix}.create");
    Route::get("{$uri}/{{$param}}", [$controller, 'show'])->middleware("permission:{$permPrefix}.view");
    Route::match(['put', 'patch'], "{$uri}/{{$param}}", [$controller, 'update'])->middleware("permission:{$permPrefix}.edit");
    Route::delete("{$uri}/{{$param}}", [$controller, 'destroy'])->middleware("permission:{$permPrefix}.delete");
};

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| TransitFlow's JSON API — consumed by the React SPA and the future mobile
| app. Auth is Laravel Sanctum. Company context is resolved and the
| active-company gate applied for every request by global middleware
| (bootstrap/app.php). Capability checks use `permission:<key>` middleware,
| which mirrors BITS' hasPermission().
|
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'environment' => app()->environment(),
        'laravel' => Application::VERSION,
        'time' => now()->toIso8601String(),
    ]);
});

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('auth.login');

// Public mobile-app meta contract (BITS api/meta/serverConfig + check_update → §K).
// Unauthenticated; a company is identified by its public `?company={code}`.
Route::prefix('meta')->middleware('throttle:60,1')->group(function () {
    Route::get('server-config', [ServerConfigController::class, 'serverConfig'])->name('meta.server-config');
    Route::get('check-update', [ServerConfigController::class, 'checkUpdate'])->name('meta.check-update');
});

Route::post('/auth/pin-login', [AuthController::class, 'pinLogin'])
    ->middleware('throttle:10,1')
    ->name('auth.pin-login');

Route::middleware(['auth:sanctum', EnsureAccountNotLocked::class, RequirePasswordChange::class])->group(function () use ($companyCrud) {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::get('/user', [AuthController::class, 'me'])->name('auth.me.alias'); // legacy alias
    Route::post('/auth/password', [AuthController::class, 'changePassword'])->name('auth.password');
    Route::match(['put', 'patch'], '/auth/profile', [AuthController::class, 'updateProfile'])->name('auth.profile');
    Route::put('/auth/pin', [AuthController::class, 'setPin'])->name('auth.pin.set');
    Route::post('/auth/verify-pin', [AuthController::class, 'verifyPin'])->name('auth.pin.verify');

    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');

    // Permission-gated navigation for the unified mobile app's dynamic menu.
    Route::get('/nav', [NavController::class, 'index'])->name('nav.index');

    /*
    | Platform — Super Admin only.
    */
    Route::prefix('super-admin')->name('super-admin.')->group(function () {
        Route::get('companies', [CompanyController::class, 'index'])->middleware('permission:companies.view')->name('companies.index');
        Route::post('companies', [CompanyController::class, 'store'])->middleware('permission:companies.create')->name('companies.store');
        Route::get('companies/{company}', [CompanyController::class, 'show'])->middleware('permission:companies.view')->name('companies.show');
        Route::match(['put', 'patch'], 'companies/{company}', [CompanyController::class, 'update'])->middleware('permission:companies.edit')->name('companies.update');
        Route::delete('companies/{company}', [CompanyController::class, 'destroy'])->middleware('permission:companies.delete')->name('companies.destroy');
        Route::patch('companies/{company}/status', [CompanyController::class, 'updateStatus'])->middleware('permission:companies.status')->name('companies.status');

        // Which permission keys a company is allowed to use (Super Admin controls availability)
        Route::get('companies/{company}/permissions', [CompanyPermissionController::class, 'show'])->middleware('permission:companies.edit')->name('companies.permissions.show');
        Route::match(['put', 'patch'], 'companies/{company}/permissions', [CompanyPermissionController::class, 'update'])->middleware('permission:companies.edit')->name('companies.permissions.update');

        Route::get('audit-log', [PlatformAuditLogController::class, 'index'])->middleware('permission:companies.view')->name('audit-log.index');

        // Platform-wide Server Base URL (System Configuration — docs/PARITY_CHECKLIST.md §K)
        Route::get('system-configuration', [SystemConfigurationController::class, 'show'])->middleware('permission:system.configuration.view,system.configuration.manage')->name('system-configuration.show');
        Route::put('system-configuration', [SystemConfigurationController::class, 'update'])->middleware(['permission:system.configuration.manage', 'throttle:30,1'])->name('system-configuration.update');
        Route::post('system-configuration/test', [SystemConfigurationController::class, 'test'])->middleware(['permission:system.configuration.manage', 'throttle:30,1'])->name('system-configuration.test');
        Route::get('system-configuration/history', [SystemConfigurationController::class, 'history'])->middleware('permission:system.configuration.view,system.configuration.manage')->name('system-configuration.history');
        Route::post('system-configuration/history/{history}/rollback', [SystemConfigurationController::class, 'rollback'])->middleware(['permission:system.configuration.manage', 'throttle:30,1'])->name('system-configuration.rollback');

        Route::get('users', [PlatformUserController::class, 'index'])->middleware('permission:platform.users.view')->name('users.index');
        Route::post('users', [PlatformUserController::class, 'store'])->middleware('permission:platform.users.create')->name('users.store');
        Route::get('users/{user}', [PlatformUserController::class, 'show'])->middleware('permission:platform.users.view')->name('users.show');
        Route::match(['put', 'patch'], 'users/{user}', [PlatformUserController::class, 'update'])->middleware('permission:platform.users.edit')->name('users.update');
        Route::delete('users/{user}', [PlatformUserController::class, 'destroy'])->middleware('permission:platform.users.delete')->name('users.destroy');
    });

    /*
    | Company scope — the caller's own company. Isolation is enforced by
    | CompanyScope + policies; capability by `permission:` middleware.
    */
    Route::prefix('company')->name('company.')->group(function () use ($companyCrud) {
        Route::get('profile', [CompanyProfileController::class, 'show'])->middleware('permission:company.profile.view')->name('profile.show');
        Route::match(['put', 'patch'], 'profile', [CompanyProfileController::class, 'update'])->middleware('permission:company.profile.edit')->name('profile.update');

        // Per-company branding / receipt / organization-identity settings (BITS system_settings → §K)
        Route::get('settings', [CompanySettingController::class, 'show'])->middleware('permission:company.settings.view,company.settings.manage')->name('settings.show');
        Route::match(['put', 'patch'], 'settings', [CompanySettingController::class, 'update'])->middleware('permission:company.settings.manage')->name('settings.update');
        Route::post('settings/{type}', [CompanySettingController::class, 'uploadImage'])->whereIn('type', ['logo', 'qr-payment'])->middleware('permission:company.settings.manage')->name('settings.image.upload');
        Route::delete('settings/{type}', [CompanySettingController::class, 'deleteImage'])->whereIn('type', ['logo', 'qr-payment'])->middleware('permission:company.settings.manage')->name('settings.image.delete');

        // Read-only runtime configuration snapshot (BITS admin/configuration → §K)
        Route::get('configuration', ConfigurationController::class)->middleware('permission:company.settings.view,company.settings.manage')->name('configuration.show');

        // Registered conductor devices — read + deregister (BITS admin/deviceMonitoring → §K)
        Route::get('devices', [DeviceController::class, 'index'])->middleware('permission:devices.view')->name('devices.index');
        Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->middleware('permission:devices.delete')->name('devices.destroy');

        // Mobile-app distribution — publish version, force-update, APK (BITS admin/mobileapp → §K)
        Route::get('mobile-app', [MobileAppController::class, 'show'])->middleware('permission:mobileapp.view,mobileapp.manage')->name('mobile-app.show');
        Route::match(['put', 'patch'], 'mobile-app', [MobileAppController::class, 'update'])->middleware('permission:mobileapp.manage')->name('mobile-app.update');
        Route::post('mobile-app/apk', [MobileAppController::class, 'uploadApk'])->middleware('permission:mobileapp.manage')->name('mobile-app.apk.upload');
        Route::delete('mobile-app/apk', [MobileAppController::class, 'deleteApk'])->middleware('permission:mobileapp.manage')->name('mobile-app.apk.delete');

        // Activity / audit trail (docs/PARITY_CHECKLIST.md §L)
        Route::get('audit-log', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('audit-log.index');

        // Data tools — per-company export (backup) + Clean Data (BITS admin/backup, admin/cleandata → §K)
        Route::get('data-tools/export', [DataToolsController::class, 'export'])->middleware('permission:backup.download')->name('data-tools.export');
        Route::get('data-tools/history', [DataToolsController::class, 'history'])->middleware('permission:backup.view,backup.download,cleandata.run')->name('data-tools.history');
        Route::get('data-tools/clean-preview', [DataToolsController::class, 'cleanPreview'])->middleware('permission:cleandata.run')->name('data-tools.clean-preview');
        Route::post('data-tools/clean', [DataToolsController::class, 'clean'])->middleware('permission:cleandata.run')->name('data-tools.clean');

        // Fleet — network
        $companyCrud('terminals', TerminalController::class, 'terminals', 'terminal');
        $companyCrud('buses', BusController::class, 'buses', 'bus');
        $companyCrud('drivers', DriverController::class, 'drivers', 'driver');
        $companyCrud('routes', CompanyRouteController::class, 'routes', 'route');

        // Thermal printer inventory, assigned to a conductor (BITS admin/thermalprinters)
        $companyCrud('thermal-printers', ThermalPrinterController::class, 'thermalprinters', 'printer');
        Route::put('thermal-printers/{printer}/assign', [ThermalPrinterController::class, 'assign'])->middleware('permission:thermalprinters.edit');

        // Office-admin ⇄ bus, time-boxed & shift-aware (BITS admin/adminassignments)
        $companyCrud('admin-assignments', AdminBusAssignmentController::class, 'adminassignments', 'assignment');

        // Passenger types + their Manual-Amount article presets
        $companyCrud('passenger-types', PassengerTypeController::class, 'passengertypes', 'passenger_type');
        Route::get('passenger-types/{passenger_type}/articles', [PassengerTypeArticleController::class, 'index'])->middleware('permission:passengertypes.view');
        Route::post('passenger-types/{passenger_type}/articles', [PassengerTypeArticleController::class, 'store'])->middleware('permission:passengertypes.edit');
        Route::match(['put', 'patch'], 'passenger-types/{passenger_type}/articles/{article}', [PassengerTypeArticleController::class, 'update'])->middleware('permission:passengertypes.edit');
        Route::delete('passenger-types/{passenger_type}/articles/{article}', [PassengerTypeArticleController::class, 'destroy'])->middleware('permission:passengertypes.edit');

        // Franchises + their fare-matrix grid (the only way fares are written)
        $companyCrud('franchises', FranchiseController::class, 'franchises', 'franchise');
        Route::get('franchises/{franchise}/fare-matrix', [FareMatrixGridController::class, 'show'])
            ->middleware('permission:farematrix.view');
        Route::put('franchises/{franchise}/fare-matrix/cell', [FareMatrixGridController::class, 'saveCell'])
            ->middleware('permission:farematrix.edit');
        Route::get('franchises/{franchise}/fare-matrix/template', [FareMatrixImportExportController::class, 'template'])
            ->middleware('permission:farematrix.view');
        Route::post('franchises/{franchise}/fare-matrix/import', [FareMatrixImportExportController::class, 'import'])
            ->middleware('permission:farematrix.edit');
        Route::get('franchises/{franchise}/stops', [RouteStopController::class, 'index'])
            ->middleware('permission:farematrix.view');
        Route::put('franchises/{franchise}/stops', [RouteStopController::class, 'save'])
            ->middleware('permission:franchises.edit');

        Route::get('users', [CompanyUserController::class, 'index'])->middleware('permission:accounts.view')->name('users.index');
        Route::post('users', [CompanyUserController::class, 'store'])->middleware('permission:accounts.create')->name('users.store');
        Route::get('users/{user}', [CompanyUserController::class, 'show'])->middleware('permission:accounts.view')->name('users.show');
        Route::match(['put', 'patch'], 'users/{user}', [CompanyUserController::class, 'update'])->middleware('permission:accounts.edit')->name('users.update');
        Route::delete('users/{user}', [CompanyUserController::class, 'destroy'])->middleware('permission:accounts.delete')->name('users.destroy');

        // Assign buses to a conductor account
        Route::get('users/{user}/buses', [ConductorBusController::class, 'show'])->middleware('permission:accounts.view');
        Route::put('users/{user}/buses', [ConductorBusController::class, 'sync'])->middleware('permission:accounts.edit');

        // Lock / unlock an account (BITS admin/accounts.php Lock/Unlock button — §B, §C)
        Route::post('users/{user}/lock', [CompanyUserController::class, 'lock'])->middleware('permission:accounts.lock');
        Route::post('users/{user}/unlock', [CompanyUserController::class, 'unlock'])->middleware('permission:accounts.lock');

        // Attendance oversight (BITS admin/attendance)
        Route::get('attendance', [CompanyAttendanceController::class, 'index'])->middleware('permission:attendance.view');
        Route::post('attendance/{attendance}/close', [CompanyAttendanceController::class, 'close'])->middleware('permission:attendance.manage');

        // Live monitoring — office board polled every ~3s (BITS office/dashboard + fleet map)
        Route::get('live', LiveMonitorController::class)->middleware('permission:tracking.view');

        // Trip monitoring — the live board + force-end (BITS tripmonitoring)
        Route::get('trip-monitor', [TripMonitorController::class, 'index'])->middleware('permission:tripmonitoring.view');
        Route::get('trip-monitor/{trip}', [TripMonitorController::class, 'show'])->middleware('permission:tripmonitoring.view');
        Route::post('trip-monitor/{trip}/force-end', [TripMonitorController::class, 'forceEnd'])->middleware('permission:tripmonitoring.forceend');
        Route::get('trip-monitor/{trip}/receipt/{kind}', [ReceiptController::class, 'trip'])->middleware('permission:tripmonitoring.view');

        // Remittance desk — receive (count cash) → approve; manager-only void (BITS remittances)
        Route::get('remittances', [RemittanceController::class, 'index'])->middleware('permission:remittances.view');
        Route::get('remittances/{trip}', [RemittanceController::class, 'show'])->middleware('permission:remittances.view');
        Route::post('remittances/{trip}/lock', [RemittanceController::class, 'lock'])->middleware('permission:remittances.receive');
        Route::delete('remittances/{trip}/lock', [RemittanceController::class, 'unlock'])->middleware('permission:remittances.receive');
        Route::post('remittances/{trip}/receive', [RemittanceController::class, 'receive'])->middleware('permission:remittances.receive');
        Route::post('remittances/{trip}/void', [RemittanceController::class, 'void'])->middleware('permission:remittances.void');
        Route::post('remittances/{trip}/approve', [RemittanceController::class, 'approve'])->middleware('permission:remittances.approve');
        Route::post('remittances/{trip}/flag', [RemittanceController::class, 'flag'])->middleware('permission:remittances.approve');
        Route::get('remittances/{trip}/receipt/{kind}', [ReceiptController::class, 'trip'])->middleware('permission:remittances.view');

        // Cash rollup (read-only) + the acting manager's void PIN (BITS cashcount)
        Route::get('void-pin', [VoidPinController::class, 'show']);
        Route::put('void-pin', [VoidPinController::class, 'update'])->middleware('permission:voidpin.manage');

        // Void Security console (BITS admin/voidsecurity)
        Route::get('void-security', [VoidSecurityController::class, 'index'])->middleware('permission:voidsecurity.view');
        Route::get('void-security/attempts', [VoidSecurityController::class, 'attempts'])->middleware('permission:voidsecurity.view');
        Route::post('void-security/{user}/reset', [VoidSecurityController::class, 'reset'])->middleware('permission:voidsecurity.manage');
        Route::post('void-security/{user}/unlock', [VoidSecurityController::class, 'unlock'])->middleware('permission:voidsecurity.manage');
        Route::get('cash-counts', [CashCountController::class, 'index'])->middleware('permission:cashcount.view');
        Route::get('cash-counts/{cashCount}', [CashCountController::class, 'show'])->middleware('permission:cashcount.view');
        Route::post('cash-counts/{cashCount}/adjust', [CashCountController::class, 'adjust'])->middleware('permission:cashcount.adjust');

        // Operational expenses drawn from a bus's takings (BITS expenses)
        Route::get('expenses', [OpExpenseController::class, 'index'])->middleware('permission:expenses.view');
        Route::post('expenses', [OpExpenseController::class, 'store'])->middleware('permission:expenses.create');
        Route::get('expenses/{expense}', [OpExpenseController::class, 'show'])->middleware('permission:expenses.view');
        Route::post('expenses/{expense}/void', [OpExpenseController::class, 'void'])->middleware('permission:expenses.void');

        // Fuel purchases + EV charging sessions (BITS fuel / ev_charging)
        Route::get('fuel', [FuelRecordController::class, 'index'])->middleware('permission:fuel.view');
        Route::post('fuel', [FuelRecordController::class, 'store'])->middleware('permission:fuel.record');
        Route::get('fuel/{fuelRecord}', [FuelRecordController::class, 'show'])->middleware('permission:fuel.view');
        Route::delete('fuel/{fuelRecord}', [FuelRecordController::class, 'destroy'])->middleware('permission:fuel.delete');
        Route::get('charging', [EvChargingController::class, 'index'])->middleware('permission:charging.view');
        Route::post('charging', [EvChargingController::class, 'store'])->middleware('permission:charging.record');
        Route::get('charging/{session}', [EvChargingController::class, 'show'])->middleware('permission:charging.view');
        Route::post('charging/{session}/end', [EvChargingController::class, 'end'])->middleware('permission:charging.record');

        // Reports & Analytics (BITS admin/reports, dailyoperations, expensereport,
        // cashcountreport, fuelenergyreport) — JSON, ?format=pdf, ?format=xlsx
        Route::get('dashboard', DashboardController::class)->middleware('permission:dashboard.view');
        Route::get('reports/income', [ReportController::class, 'income'])->middleware('permission:reports.view');
        Route::get('reports/trip-income', [ReportController::class, 'tripIncome'])->middleware('permission:reports.view');
        Route::get('reports/daily-operations', [ReportController::class, 'dailyOperations'])->middleware('permission:dailyops.view');
        Route::get('reports/expenses', [ReportController::class, 'expenses'])->middleware('permission:expensereport.view');
        Route::get('reports/cash-count', [ReportController::class, 'cashCount'])->middleware('permission:cashcountreport.view');
        Route::get('reports/fuel-energy', [ReportController::class, 'fuelEnergy'])->middleware('permission:fuelenergyreport.view');

        // Per-company roles (BITS Roles admin page)
        Route::get('roles', [CompanyRoleController::class, 'index'])->middleware('permission:roles.view');
        Route::post('roles', [CompanyRoleController::class, 'store'])->middleware('permission:roles.manage');
        Route::get('roles/{role}', [CompanyRoleController::class, 'show'])->middleware('permission:roles.view');
        Route::match(['put', 'patch'], 'roles/{role}', [CompanyRoleController::class, 'update'])->middleware('permission:roles.manage');
        Route::delete('roles/{role}', [CompanyRoleController::class, 'destroy'])->middleware('permission:roles.manage');

        Route::get('permissions', [PermissionController::class, 'catalogue'])->middleware('permission:permissions.view')->name('permissions.catalogue');
        Route::get('users/{user}/permissions', [PermissionController::class, 'showUser'])->middleware('permission:permissions.view')->name('users.permissions.show');
        Route::put('users/{user}/permissions', [PermissionController::class, 'syncUser'])->middleware('permission:permissions.manage')->name('users.permissions.sync');
        Route::post('users/{user}/permissions/reset', [PermissionController::class, 'resetUser'])->middleware('permission:permissions.manage')->name('users.permissions.reset');
    });

    /*
    | Conductor — the authenticated conductor's own trips + ticketing. The
    | same endpoints back the SPA and the future mobile app.
    */
    Route::prefix('conductor')->name('conductor.')->group(function () {
        Route::get('attendance', [ConductorAttendanceController::class, 'show'])->middleware('permission:attendance.view');
        Route::post('attendance/toggle', [ConductorAttendanceController::class, 'toggle'])->middleware('permission:attendance.clock');

        Route::get('lookup/buses', [LookupController::class, 'assignedBuses'])->middleware('permission:trips.start');
        Route::get('lookup/drivers', [LookupController::class, 'drivers'])->middleware('permission:trips.start');
        Route::get('lookup/terminals', [LookupController::class, 'terminals'])->middleware('permission:trips.start');
        Route::get('lookup/coverage', [LookupController::class, 'coverageOptions'])->middleware('permission:trips.start');
        Route::get('lookup/passenger-types', [LookupController::class, 'passengerTypes'])->middleware('permission:tickets.issue');
        Route::get('trips/{trip}/fares', [LookupController::class, 'tripFares'])->middleware('permission:tickets.issue');
        Route::get('trips/{trip}/stops', [LookupController::class, 'stops'])->middleware('permission:tickets.issue');

        Route::get('trips/active', [TripController::class, 'active'])->middleware('permission:trips.view');
        Route::get('trips/history', [TripController::class, 'history'])->middleware('permission:trips.view');
        Route::get('trips/{trip}/remittance', [TripController::class, 'remittance'])->middleware('permission:trips.view');
        Route::get('trips/{trip}/receipt/{kind}', [ReceiptController::class, 'trip'])->middleware('permission:trips.view');
        Route::get('trips/{trip}', [TripController::class, 'show'])->middleware('permission:trips.view');
        Route::post('trips', [TripController::class, 'start'])->middleware('permission:trips.start');
        Route::post('trips/mark-on-trip', [TripController::class, 'markOnTrip'])->middleware('permission:trips.markontrip');
        Route::post('trips/location', [LocationController::class, 'store'])->middleware('permission:tracking.ping');
        Route::post('devices/register', [ConductorDeviceController::class, 'register'])->middleware('permission:devices.register');
        Route::post('trips/end', [TripController::class, 'end'])->middleware('permission:trips.end');
        Route::post('trips/cancel', [TripController::class, 'cancel'])->middleware('permission:trips.cancel');

        Route::post('tickets', [TicketController::class, 'store'])->middleware('permission:tickets.issue');
        Route::post('ticket-groups', [TicketController::class, 'storeGroup'])->middleware('permission:tickets.issue');
        Route::get('trips/{trip}/tickets', [TicketController::class, 'index'])->middleware('permission:tickets.view');
        Route::get('tickets/{ticket}/receipt', [ReceiptController::class, 'ticket'])->middleware('permission:tickets.view');

        // Barker dispatch payouts on the conductor's own live trip (§4.3)
        Route::post('dispatches', [DispatchController::class, 'store'])->middleware('permission:dispatch.issue');
        Route::get('trips/{trip}/dispatches', [DispatchController::class, 'index'])->middleware('permission:dispatch.view');
        Route::get('dispatches/{dispatch}/receipt', [ReceiptController::class, 'dispatch'])->middleware('permission:dispatch.view');
        Route::delete('trips/{trip}/dispatches/{dispatch}', [DispatchController::class, 'destroy'])->middleware('permission:dispatch.issue');

        // End-of-shift sales summary across the conductor's Active buses
        Route::get('receipts/shift-summary', [ReceiptController::class, 'shiftSummary'])->middleware('permission:trips.view');

        // Confirm end of shift: re-verify PIN, lock the account (shift_end), clock out (§4, §B)
        Route::post('shift-end/confirm', [ConductorAttendanceController::class, 'confirmShiftEnd'])->middleware('permission:attendance.clock');
    });
});

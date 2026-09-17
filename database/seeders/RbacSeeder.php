<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\PermissionGroup;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the permission catalogue and the role → permission defaults.
 *
 * `permission_key` strings match BITS (docs/MIGRATION_MAP.md §3) wherever a
 * BITS equivalent exists, so the Phase 5 module migrations only add keys,
 * never rename these. Idempotent — safe to re-run.
 */
class RbacSeeder extends Seeder
{
    /**
     * group => [ [key, name, navLabel|null, navUrl|null, navIcon|null], ... ]
     *
     * @var array<string, list<array{0:string,1:string,2:?string,3:?string,4:?string}>>
     */
    private array $catalogue = [
        'Companies' => [
            ['companies.view', 'View Companies', 'Companies', '/super-admin/companies', 'bi-buildings'],
            ['companies.create', 'Create Company', null, null, null],
            ['companies.edit', 'Edit Company', null, null, null],
            ['companies.delete', 'Delete Company', null, null, null],
            ['companies.status', 'Change Company Status', null, null, null],
        ],
        'Platform Users' => [
            ['platform.users.view', 'View Platform Users', 'Platform Users', '/super-admin/users', 'bi-person-gear'],
            ['platform.users.create', 'Create Platform / Company-Admin User', null, null, null],
            ['platform.users.edit', 'Edit Platform / Company-Admin User', null, null, null],
            ['platform.users.delete', 'Delete Platform / Company-Admin User', null, null, null],
        ],
        'System Configuration' => [
            ['system.configuration.view', 'View Server Configuration', 'System Configuration', '/super-admin/system-configuration', 'bi-hdd-network'],
            ['system.configuration.manage', 'Update / Test / Roll Back Server Configuration', null, null, null],
        ],
        'Legal Documents' => [
            ['legal.view', 'View Legal Documents', 'Legal Documents', '/super-admin/legal-documents', 'bi-file-earmark-text'],
            ['legal.manage', 'Publish / Edit Legal Documents', null, null, null],
        ],
        'Company Profile' => [
            ['company.profile.view', 'View Company Profile', 'Company Profile', '/company/profile', 'bi-building'],
            ['company.profile.edit', 'Edit Company Profile', null, null, null],
            ['company.settings.view', 'View Company Settings', 'Settings', '/company/settings', 'bi-sliders'],
            ['company.settings.manage', 'Manage Company Settings (branding, receipt, org identity)', null, null, null],
        ],
        'Accounts' => [
            ['accounts.view', 'View Accounts', 'Accounts', '/company/users', 'bi-people'],
            ['accounts.create', 'Create Account', null, null, null],
            ['accounts.edit', 'Edit Account', null, null, null],
            ['accounts.delete', 'Delete Account', null, null, null],
            ['accounts.lock', 'Lock / Unlock Account', null, null, null],
        ],
        'Permissions' => [
            ['permissions.view', 'View Permissions', 'Permissions', '/company/permissions', 'bi-shield-lock'],
            ['permissions.manage', 'Manage Per-User Permissions', null, null, null],
        ],
        'Roles' => [
            ['roles.view', 'View Roles', 'Roles', '/company/roles', 'bi-person-badge-fill'],
            ['roles.manage', 'Create / Edit / Delete Roles', null, null, null],
        ],
        'Terminals' => [
            ['terminals.view', 'View Terminals', 'Terminals', '/company/terminals', 'bi-signpost-split'],
            ['terminals.create', 'Add Terminal', null, null, null],
            ['terminals.edit', 'Edit Terminal', null, null, null],
            ['terminals.delete', 'Delete Terminal', null, null, null],
        ],
        'Drivers' => [
            ['drivers.view', 'View Drivers', 'Drivers', '/company/drivers', 'bi-person-badge'],
            ['drivers.create', 'Add Driver', null, null, null],
            ['drivers.edit', 'Edit Driver', null, null, null],
            ['drivers.delete', 'Delete Driver', null, null, null],
        ],
        'Passenger Types' => [
            ['passengertypes.view', 'View Passenger Types', 'Passenger Types', '/company/passenger-types', 'bi-people-fill'],
            ['passengertypes.create', 'Add Passenger Type', null, null, null],
            ['passengertypes.edit', 'Edit Passenger Type / Articles', null, null, null],
            ['passengertypes.delete', 'Delete Passenger Type', null, null, null],
        ],
        'Franchises' => [
            ['franchises.view', 'View Franchises', 'Franchises', '/company/franchises', 'bi-file-earmark-text'],
            ['franchises.create', 'Add Franchise', null, null, null],
            ['franchises.edit', 'Edit Franchise (incl. its route stops)', null, null, null],
            ['franchises.delete', 'Delete Franchise', null, null, null],
        ],
        'Fare Matrix' => [
            ['farematrix.view', 'View Fare Matrix Grid', null, null, null],
            ['farematrix.edit', 'Edit Fares (grid cells + discount overrides)', null, null, null],
        ],
        'Routes' => [
            ['routes.view', 'View Routes', 'Routes', '/company/routes', 'bi-signpost-split'],
            ['routes.create', 'Add Route', null, null, null],
            ['routes.edit', 'Edit Route', null, null, null],
            ['routes.delete', 'Delete Route', null, null, null],
        ],
        'Buses' => [
            ['buses.view', 'View Buses', 'Buses', '/company/buses', 'bi-bus-front'],
            ['buses.create', 'Add Bus', null, null, null],
            ['buses.edit', 'Edit Bus', null, null, null],
            ['buses.delete', 'Delete Bus', null, null, null],
        ],
        'Thermal Printers' => [
            ['thermalprinters.view', 'View Thermal Printers', 'Thermal Printers', '/company/thermal-printers', 'bi-printer-fill'],
            ['thermalprinters.create', 'Add Thermal Printer', null, null, null],
            ['thermalprinters.edit', 'Edit / Assign Thermal Printer', null, null, null],
            ['thermalprinters.delete', 'Delete Thermal Printer', null, null, null],
        ],
        'Admin Bus Assignments' => [
            ['adminassignments.view', 'View Admin Bus Assignments', 'Admin Assignments', '/company/admin-assignments', 'bi-person-lines-fill'],
            ['adminassignments.create', 'Create Admin Bus Assignment', null, null, null],
            ['adminassignments.edit', 'Edit / End Admin Bus Assignment', null, null, null],
            ['adminassignments.delete', 'Delete Admin Bus Assignment', null, null, null],
        ],
        'Trips' => [
            ['trips.view', 'View Own Trips', 'My Trips', '/conductor/trips', 'bi-bus-front-fill'],
            ['trips.start', 'Start Trip', null, null, null],
            ['trips.markontrip', 'Mark Trip On-Trip', null, null, null],
            ['trips.end', 'End Trip', null, null, null],
            ['trips.cancel', 'Cancel Trip', null, null, null],
            ['tripmonitoring.view', 'View Trip Monitoring', 'Trip Monitoring', '/company/trip-monitor', 'bi-clipboard-data'],
            ['tripmonitoring.forceend', 'Force-End a Trip', null, null, null],
        ],
        'Tickets' => [
            ['tickets.issue', 'Issue Tickets', null, null, null],
            ['tickets.view', 'View Trip Tickets', null, null, null],
        ],
        'Dispatch' => [
            ['dispatch.issue', 'Record Barker Dispatch', null, null, null],
            ['dispatch.view', 'View Trip Dispatches', null, null, null],
        ],
        'Remittances' => [
            ['remittances.view', 'View Remittances', 'Remittances', '/company/remittances', 'bi-cash-coin'],
            ['remittances.receive', 'Receive Remittance (count cash)', null, null, null],
            ['remittances.void', 'Void a Received Remittance (manager, needs void-PIN)', null, null, null],
            ['remittances.approve', 'Approve Remittance', null, null, null],
        ],
        'Attendance' => [
            ['attendance.clock', 'Clock In / Out', null, null, null],
            ['attendance.view', 'View Attendance', 'Attendance', '/company/attendance', 'bi-clock-history'],
            ['attendance.manage', 'Force-Close Attendance', null, null, null],
        ],
        'Cash Count' => [
            ['cashcount.view', 'View Cash Rollup', 'Cash Count', '/company/cash-counts', 'bi-cash-stack'],
            ['cashcount.adjust', 'Adjust Rollup Denominations (manager, needs void-PIN)', null, null, null],
            ['voidpin.manage', 'Set Own Void PIN', null, null, null],
        ],
        'Void Security' => [
            ['voidsecurity.view', 'View Void Security Console', 'Void Security', '/company/void-security', 'bi-shield-lock-fill'],
            ['voidsecurity.manage', 'Reset PIN / Clear Lockout', null, null, null],
        ],
        'Expenses' => [
            ['expenses.view', 'View Operational Expenses', 'Expenses', '/company/expenses', 'bi-receipt-cutoff'],
            ['expenses.create', 'Record an Expense', null, null, null],
            ['expenses.void', 'Void an Expense (manager, needs void-PIN)', null, null, null],
        ],
        'Fuel & Energy' => [
            ['fuel.view', 'View Fuel & Charging', 'Fuel & Energy', '/company/fuel', 'bi-fuel-pump'],
            ['fuel.record', 'Record a Fuel Purchase', null, null, null],
            ['fuel.delete', 'Delete a Fuel Record', null, null, null],
            ['charging.view', 'View EV Charging Sessions', null, null, null],
            ['charging.record', 'Start / End an EV Charging Session', null, null, null],
        ],
        'Live Monitoring' => [
            ['tracking.view', 'View Live Fleet Board', 'Live Monitor', '/company/live', 'bi-broadcast-pin'],
            ['tracking.ping', 'Report Bus GPS Position (conductor device)', null, null, null],
        ],
        'Devices' => [
            ['devices.view', 'View Registered Devices', 'Devices', '/company/devices', 'bi-phone'],
            ['devices.delete', 'Deregister a Device', null, null, null],
            ['devices.register', 'Register / Heartbeat Own Device (mobile app)', null, null, null],
        ],
        'Mobile App' => [
            ['mobileapp.view', 'View Mobile App Distribution', 'Mobile App', '/company/mobile-app', 'bi-google-play'],
            ['mobileapp.manage', 'Publish App Version / Upload APK / Force Update', null, null, null],
        ],
        'Data Tools' => [
            ['backup.view', 'View Data Tools & History', 'Data Tools', '/company/data-tools', 'bi-database-gear'],
            ['backup.download', 'Export Company Data (JSON backup)', null, null, null],
            ['cleandata.run', 'Clean Data — wipe transactional records', null, null, null],
        ],
        'Audit Trail' => [
            ['audit.view', 'View Activity / Audit Log', 'Audit Log', '/company/audit-log', 'bi-journal-text'],
        ],
        'Reports & Analytics' => [
            ['dashboard.view', 'View Dashboard', 'Dashboard', '/dashboard', 'bi-speedometer2'],
            ['reports.view', 'View Income Monitoring', 'Income Monitoring', '/company/reports/income', 'bi-graph-up-arrow'],
            ['dailyops.view', 'View Daily Operations Report', 'Daily Operations', '/company/reports/daily-operations', 'bi-clipboard-data'],
            ['expensereport.view', 'View Expense Report', 'Expense Report', '/company/reports/expenses', 'bi-receipt'],
            ['cashcountreport.view', 'View Cash Count Report', 'Cash Count Report', '/company/reports/cash-count', 'bi-cash-stack'],
            ['fuelenergyreport.view', 'View Fuel & Energy Report', 'Fuel & Energy Report', '/company/reports/fuel-energy', 'bi-fuel-pump'],
        ],
    ];

    /**
     * role key => list of granted permission keys ('*' = every key).
     *
     * @var array<string, list<string>>
     */
    private array $roleGrants = [
        'super_admin' => ['*'],
        'company_admin' => [
            'dashboard.view',
            'reports.view', 'dailyops.view', 'expensereport.view', 'cashcountreport.view', 'fuelenergyreport.view',
            'company.profile.view', 'company.profile.edit', 'company.settings.view', 'company.settings.manage',
            'mobileapp.view', 'mobileapp.manage',
            'backup.view', 'backup.download', 'cleandata.run',
            'audit.view',
            'accounts.view', 'accounts.create', 'accounts.edit', 'accounts.delete', 'accounts.lock',
            'permissions.view', 'permissions.manage',
            'roles.view', 'roles.manage',
            'voidsecurity.view', 'voidsecurity.manage',
            'terminals.view', 'terminals.create', 'terminals.edit', 'terminals.delete',
            'drivers.view', 'drivers.create', 'drivers.edit', 'drivers.delete',
            'passengertypes.view', 'passengertypes.create', 'passengertypes.edit', 'passengertypes.delete',
            'franchises.view', 'franchises.create', 'franchises.edit', 'franchises.delete',
            'farematrix.view', 'farematrix.edit',
            'routes.view', 'routes.create', 'routes.edit', 'routes.delete',
            'buses.view', 'buses.create', 'buses.edit', 'buses.delete',
            'thermalprinters.view', 'thermalprinters.create', 'thermalprinters.edit', 'thermalprinters.delete',
            'adminassignments.view', 'adminassignments.create', 'adminassignments.edit', 'adminassignments.delete',
            'tripmonitoring.view', 'tripmonitoring.forceend',
            'tracking.view',
            'remittances.view', 'remittances.receive', 'remittances.approve', 'dispatch.view',
            'devices.view', 'devices.delete',
            'attendance.view', 'attendance.manage',
            'cashcount.view',
            'expenses.view', 'expenses.create',
            'fuel.view', 'fuel.record', 'fuel.delete', 'charging.view', 'charging.record',
        ],
        'manager' => [
            'dashboard.view',
            'reports.view', 'dailyops.view', 'expensereport.view', 'cashcountreport.view', 'fuelenergyreport.view',
            'company.profile.view', 'accounts.view', 'permissions.view', 'roles.view',
            'terminals.view', 'buses.view', 'routes.view', 'drivers.view', 'passengertypes.view',
            'thermalprinters.view', 'thermalprinters.edit',
            'adminassignments.view', 'adminassignments.create', 'adminassignments.edit',
            'franchises.view', 'franchises.create', 'franchises.edit', 'franchises.delete',
            'farematrix.view', 'farematrix.edit',
            'tripmonitoring.view', 'tripmonitoring.forceend',
            'tracking.view',
            'remittances.view', 'remittances.receive', 'remittances.void', 'remittances.approve', 'dispatch.view',
            'devices.view', 'devices.delete',
            'attendance.view', 'attendance.manage',
            'cashcount.view', 'cashcount.adjust', 'voidpin.manage',
            'expenses.view', 'expenses.create', 'expenses.void',
            'fuel.view', 'fuel.record', 'fuel.delete', 'charging.view', 'charging.record',
        ],
        'chairman' => [
            'dashboard.view',
            'reports.view', 'dailyops.view', 'expensereport.view', 'cashcountreport.view', 'fuelenergyreport.view',
            'company.profile.view', 'company.settings.view', 'accounts.view', 'voidsecurity.view',
            'audit.view',
            'mobileapp.view',
            'backup.view',
            'terminals.view', 'buses.view', 'routes.view', 'drivers.view', 'passengertypes.view',
            'thermalprinters.view', 'adminassignments.view',
            'franchises.view', 'franchises.create', 'franchises.edit', 'franchises.delete',
            'farematrix.view', 'farematrix.edit',
            'tripmonitoring.view', 'tripmonitoring.forceend',
            'tracking.view',
            'remittances.view', 'remittances.approve', 'dispatch.view',
            'devices.view',
            'attendance.view',
            'cashcount.view',
            'expenses.view',
            'fuel.view', 'charging.view',
        ],
        'office' => [
            'dashboard.view',
            'reports.view', 'dailyops.view', 'expensereport.view', 'cashcountreport.view', 'fuelenergyreport.view',
            'accounts.view', 'terminals.view', 'buses.view', 'routes.view', 'drivers.view',
            'thermalprinters.view', 'adminassignments.view',
            'passengertypes.view', 'franchises.view', 'farematrix.view',
            'tripmonitoring.view', 'remittances.view', 'remittances.receive', 'dispatch.view',
            'tracking.view',
            'devices.view',
            'attendance.view',
            'cashcount.view',
            'expenses.view', 'expenses.create',
            'fuel.view', 'fuel.record', 'fuel.delete', 'charging.view', 'charging.record',
        ],
        'conductor' => [
            'dashboard.view',
            'trips.view', 'trips.start', 'trips.markontrip', 'trips.end', 'trips.cancel',
            'tickets.issue', 'tickets.view',
            'dispatch.issue', 'dispatch.view',
            'attendance.clock', 'attendance.view',
            'tracking.ping',
            'devices.register',
            'fuel.view', 'fuel.record', 'charging.view', 'charging.record',
        ],
    ];

    /**
     * The platform role TEMPLATES (`roles.company_id = null`). Every company
     * gets an editable clone of the non-platform ones via
     * App\Actions\SeedCompanyRoles.
     *
     * @var array<string, array{name:string, description:string, is_platform:bool, is_admin?:bool}>
     */
    private array $roles = [
        'super_admin' => ['name' => 'Super Admin', 'description' => 'Operates the TransitFlow platform.', 'is_platform' => true],
        'company_admin' => ['name' => 'Company Admin', 'description' => 'Administers one company.', 'is_platform' => false, 'is_admin' => true],
        'manager' => ['name' => 'Manager', 'description' => 'Oversees operations and reports.', 'is_platform' => false],
        'chairman' => ['name' => 'Chairman', 'description' => 'Highest company oversight role.', 'is_platform' => false],
        'office' => ['name' => 'Office', 'description' => 'Office staff — monitoring and desk work.', 'is_platform' => false],
        'conductor' => ['name' => 'Conductor', 'description' => 'Issues tickets and runs trips.', 'is_platform' => false],
    ];

    public function run(): void
    {
        $groupSort = 0;
        $navSort = 0;

        foreach ($this->catalogue as $groupName => $permissions) {
            $group = PermissionGroup::query()->updateOrCreate(
                ['name' => $groupName],
                ['sort_order' => $groupSort += 10],
            );

            foreach ($permissions as [$key, $name, $navLabel, $navUrl, $navIcon]) {
                Permission::query()->updateOrCreate(
                    ['permission_key' => $key],
                    [
                        'permission_group_id' => $group->id,
                        'name' => $name,
                        'nav_label' => $navLabel,
                        'nav_url' => $navUrl,
                        'nav_icon' => $navIcon,
                        'nav_order' => $navLabel !== null ? $navSort += 10 : 0,
                    ],
                );
            }
        }

        $allKeys = Permission::query()->pluck('id', 'permission_key');
        $roleSort = 0;

        foreach ($this->roles as $key => $meta) {
            $role = Role::query()->updateOrCreate(
                ['company_id' => null, 'key' => $key],
                [...$meta, 'is_admin' => $meta['is_admin'] ?? false, 'sort_order' => $roleSort += 10],
            );

            $granted = $this->roleGrants[$key] ?? [];
            $keys = $granted === ['*'] ? $allKeys->keys()->all() : $granted;

            $pivot = [];
            foreach ($keys as $permKey) {
                if (isset($allKeys[$permKey])) {
                    $pivot[$allKeys[$permKey]] = ['allowed' => true];
                }
            }

            $role->permissions()->sync($pivot);
        }
    }
}

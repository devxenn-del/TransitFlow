# Parity Checklist — BITS vs TransitFlow

Feature-by-feature parity between the legacy **BITS — Bus Income Tracking System**
and **TransitFlow — Smart Transport Management Platform**.

A feature is **at parity** only when TransitFlow reproduces the legacy business
rules and calculations (see `MIGRATION_MAP.md` §4) **with tests proving it**.

> ⬜ not started · 🟡 in progress · ✅ parity · ⭐ improved beyond legacy · ➖ intentionally dropped

Populated from the Phase 2 analysis. Statuses stay ⬜ until built in Phases 3–5.

---

## A. Platform & foundation (new in TransitFlow — no BITS equivalent)

| Capability | Status | Notes |
| --- | --- | --- |
| Laravel + React + Bootstrap base app runs | ✅ | Phase 1 |
| `GET /api/health` smoke endpoint | ✅ | Phase 1 |
| MySQL (`transitflow`) configured | ✅ | Phase 1 |
| Sanctum API auth scaffolding installed | ✅ | Phase 1; `HasApiTokens` on User in Phase 3 |
| `companies` table + `Company` model + `CompanyStatus` enum | ✅ | Phase 3 |
| `company_settings` table (per-company split of BITS `system_settings`) | ✅ | Phase 3 (branding/receipt/org subset; rest in Phase 5) |
| `users.company_id` (nullable = platform) + `users.role` + `users.status` | ✅ | Phase 3; `UserRole` enum (super_admin / company_admin / company_user) |
| `BelongsToCompany` trait + `CompanyScope` global scope + `CompanyContext` | ✅ | Phase 3 — auto-scoping + auto-fill `company_id` |
| Company data isolation enforced in backend (URL/param tamper-proof) | ✅ | Phase 3 — `ResolveCompanyContext` runs before route-model binding, so another company's record 404s at the router; covered by `CompanyIsolationTest` |
| Company status gate (`EnsureCompanyIsActive`) | ✅ | Phase 3 — suspended/inactive company → 403; `CompanyStatusTest` |
| `Gate::before` Super Admin bypass + `CompanyPolicy` / `BusPolicy` | ✅ | Phase 3 (policies are company-boundary only; per-permission-key checks in Phase 4) |
| Super Admin: company CRUD + activate / deactivate / suspend | ✅ | Phase 3 — `/api/super-admin/companies` + `ProvisionCompany` action; `CompanyManagementTest` |
| Company Admin: own-company profile (`/api/company/profile`) | ✅ | Phase 3 |
| Company-owned resource reference impl (`Bus` + `/api/company/buses`) | ✅ | Phase 3 — proves the isolation pattern; full Fleet module in Phase 5 |
| Company-aware authorization (user × role × **permission** × company × status) | ✅ | Phase 4 — full RBAC (`permissions`/`roles`/`role_permissions`/`user_permissions`), `EnsurePermission` middleware, `Gate::before` |
| **Super Admin controls per-company permission availability** | ✅ | `CompanyPermissionAvailabilityTest` — `company_permissions` overrides table (`enabled` bool; only deviations stored, empty = all available). `GET/PUT /api/super-admin/companies/{company}/permissions` (`permission:companies.edit`) — Super Admin switches a key (or a whole feature group) off for one company. `Company::disabledPermissionKeys()` (request-cached); `User::grantedPermissionKeys()` subtracts them so a disabled key **stops working live** for every user of that company even with `user_permissions.allowed=1`; a Company Admin's `RoleController`/`PermissionController` grants strip disabled keys. SPA: "Feature access" modal on the Super Admin Companies page (grouped checklist) |
| Super Admin: manage Company Admin accounts | ✅ | Phase 4 — `/api/super-admin/users` (any company, any role); `permission:platform.users.*` |
| Web SPA — login, RBAC-gated nav, dashboard | ✅ | Phase 5 — token auth, `AuthContext`/`ProtectedRoute`/`AppLayout` |
| Web SPA — Companies + Platform Users (Super Admin) | ✅ | Phase 5 — full CRUD + status + provision-with-admin |
| Web SPA — Company Profile / Users / Permissions matrix / Buses | ✅ | Phase 5 — verified in browser |
| Web SPA — BITS design system (dark amber sidebar, destination-sign branding, ticket-stub cards, split-screen login, Barlow Condensed + Inter + JetBrains Mono) | ✅ | Phase 5 — ported from `BITS/assets/css` + `admin/assets/css/sidebar.css` + `login.css` |
| Web SPA — Terminals / Routes / Fare Matrix pages | ✅ | Phase 5 — CRUD, `DataTable` component, verified in browser |
| Fare calculation (§4.1) | ✅ | `App\Support\FareCalculator` — unit-tested; wired into Tickets in a later step |
| Web (SPA session) + token authentication + login/logout/me endpoints | ✅ | Phase 4 — `/api/auth/*`, Sanctum stateful session + bearer token, throttled |
| Timezone pinned to Asia/Manila (app + DB) | ✅ | `config/app.php` `timezone` = `env('APP_TIMEZONE', 'Asia/Manila')`; `config/database.php` mysql/mariadb `timezone` = `env('DB_TIMEZONE', '+08:00')` (mirrors BITS' `SET time_zone='+08:00'`). 215 tests still green |

## B. Authentication & access control

| Capability | BITS behaviour to reproduce | Status | Tests |
| --- | --- | --- | --- |
| Web login | bcrypt (`Hash::check`); status must be `Active`; company must be Active; friendly messages | ✅ | ✅ `AuthenticationTest` |
| Login throttling | 5 failed/email+IP/min → 429 (improvement over BITS, which had none) | ✅ | ✅ |
| "Keep me signed in" | `remember` flag on the Sanctum stateful session (replaces BITS' rotating selector:validator table) | 🟡 | — SPA session path in place; needs SPA cookie flow exercised in Phase 5 |
| Mobile/API login | same endpoint issues a bearer token; conductor-only restriction is Phase 5 (needs the conductor role/perms) | ✅ | ✅ `PinLoginTest` — the mobile/PIN path (`POST /api/auth/pin-login`) is conductor-role + `trips.view`-restricted; `/auth/login` (email+password) stays open to every role for the web SPA, same split BITS drew between its web and API login endpoints |
| Logout | bearer: delete that one token; session: invalidate | ✅ | ✅ `AuthenticationTest` |
| PIN login / verify | conductor `pin` (bcrypt) for quick unlock | ✅ | ✅ `PinLoginTest` — `POST /api/auth/pin-login` (email+PIN, issues a token, same account/lock gates as `/auth/login`); `POST /api/auth/verify-pin` (re-check PIN on an existing session, no new token — "quick unlock"); `PUT /api/auth/pin` self-service set/change; admin can set a conductor's PIN via `POST/PUT /api/company/users`. **Adapted**: BITS' bus→driver→conductor login wizard is dropped — TransitFlow already picks bus/driver at Start Trip (§E), so PIN login only re-implements the credential/account checks. SPA: user-menu "Set/Change App PIN" for conductor accounts |
| Account lock (shift_end / admin) | re-checked every request; on-read expiry ~03:59 | ✅ | ✅ `AccountLockTest`, `ConfirmShiftEndTest` — `App\Support\AccountLock` (`users.locked_at/lock_type/locked_by`); `EnsureAccountNotLocked` middleware on every authenticated request (423, grace=true); `/auth/login` + `/auth/pin-login` check with grace=false. `'admin'`: `POST/POST /api/company/users/{id}/lock`\|`/unlock` (`accounts.edit`), SPA lock/unlock button + "Locked" badge on the Users page. `'shift_end'`: `POST /api/conductor/shift-end/confirm` (PIN re-verify) — `App\Actions\ConfirmShiftEnd` locks + Clocks out, auto-expires at the next 3:59 AM |
| Permission checks | live check reads **`user_permissions.allowed = 1`** only; `super_admin` bypass | ✅ | ✅ `PermissionEnforcementTest` — `hasPermissionTo()` + `Gate::before` + `permission:` middleware |
| Role → default permissions | copied into `user_permissions` at account creation | ✅ | ✅ `SyncUserRolePermissions` action; `UserManagementTest` |
| Per-user permission overrides | add/revoke individual keys; reset to role defaults | ✅ | ✅ `PUT/POST /api/company/users/{id}/permissions*` |
| Nav metadata | `permissions.nav_label/url/icon/order` carried in schema + seed | ✅ | — served via `/api/company/permissions`; SPA nav render in Phase 5 |
| Roles present | `super_admin, company_admin, manager, chairman, office, conductor` (BITS `admin` → `company_admin`) | ✅ | `RbacSeeder` — now **platform templates** (`roles.company_id = null`) |
| **Per-company configurable roles** | each company owns its role set (cloned from templates on provisioning); admins add/edit/delete roles + set default grants | ✅ | ✅ `CompanyRolesTest` — `roles.company_id` + `is_admin`; `App\Actions\SeedCompanyRoles`; `GET/POST/PUT/DELETE /api/company/roles` (`roles.view`/`roles.manage`); SPA `/company/roles`; company roles can't be granted platform-only permissions |
| Manager void-PIN | separate `void_pin_hash`; failed-count lockout; audit trail | ✅ | ✅ `RemittanceReceiptTest` — `App\Support\ManagerVoidPin` (see §F) |
| Security PIN | `security_pin_hash` (`src/SecurityPin.php`) | ⬜ | ⬜ — later |

## C. Accounts & people

| Capability | BITS behaviour | Status | Tests |
| --- | --- | --- | --- |
| Account management (company) | create/edit/delete/deactivate, company-scoped; role assignment | ✅ | ✅ `UserManagementTest` — `/api/company/users` |
| Account management (platform) | Super Admin creates users for any company / platform | ✅ | ✅ `UserManagementTest` — `/api/super-admin/users` |
| No self-delete / no privilege escalation | company admin can't delete self, can't mint a Super Admin | ✅ | ✅ `UserManagementTest`, `UserPolicy` |
| Platform account-creation gate | Super Admin toggles `companies.can_create_accounts`; when off a company admin cannot create accounts (edit/deactivate still allowed) — the Super Admin always can | ✅ | ✅ `UserManagementTest` / `CompanyManagementTest` — `UserPolicy::create` + `SuperAdmin\CompanyController`; SPA checkbox on Companies, notice on Users |
| Company welcome email | on provisioning, the **company's own email address** gets the sign-in URL + temporary password (queued; never fails provisioning; falls back to the admin email only if the company has none) | ✅ | ✅ `CompanyManagementTest` — `App\Mail\CompanyAdminWelcome` sent from `ProvisionCompany` (skipped when the password is pre-hashed, e.g. the seeder). `.env` wired for Gmail SMTP; guide at Desktop `TransitFlow-email-setup.txt` |
| Forced first-login password change | a new company admin has `users.must_change_password`; the whole API is 423-locked (except auth/logout/password) until they set their own via `POST /api/auth/password` | ✅ | ✅ `ForcedPasswordChangeTest` — `RequirePasswordChange` middleware + `AuthController::changePassword` (revokes other tokens); SPA `ForcePasswordChange` full-screen gate |
| Conductor management | assign one or more buses (`conductor_buses`); lock/unlock | ✅ | ✅ bus assignment — §E `PUT /api/company/users/{id}/buses`; lock/unlock — `AccountLockTest`, `POST /api/company/users/{id}/lock`\|`/unlock`, verified in browser (SPA lock/unlock button + "Locked" badge on the Users page) |
| Profile self-service | edit own profile | ✅ | ✅ `ProfileSelfServiceTest` — `PUT /api/auth/profile` (`App\Http\Controllers\Api\Auth\AuthController::updateProfile`); SPA `pages/MyProfile.jsx` (nav: user menu → "My profile"), verified in browser |
| Employee ID | auto-format `E-YYMM-#######` | ✅ | ✅ `ProfileSelfServiceTest` — `App\Models\User::booted()` (mirrors `Driver`'s convention: `E-{YYMM}-{id padded 7}`), unique per company |
| Contact details | `account_details` (name parts, email, phone, address, sex) | ✅ | ✅ `ProfileSelfServiceTest` — merged into `users` (`first_name/middle_name/last_name/phone/address/sex`) rather than a separate table; `sex` is `Male`\|`Female` (BITS' seeded `sexes` values) |

## D. Fleet & network master data

| Capability | BITS behaviour | Status | Tests |
| --- | --- | --- | --- |
| Buses | unique bus_number + plate (per company); status Active/Inactive/Maintenance | ✅ | ✅ `FleetModuleTest` — `capacity`/`model`/`vehicle_type` (electric\|diesel\|gasoline, default diesel) added; SPA form + table updated; verified in browser (Company Admin, real create) |
| Drivers | login-free; `license_number, contact_number`; picked per trip; auto `employee_id` `E-YYMM-#######` (from 1,000,000+id) | ✅ | ✅ Phase 5 — `/api/company/drivers` + SPA page; `DriverAndPassengerTypeTest` (availability guard = Phase 5 trips) |
| Passenger types | Fare Matrix vs Manual Amount; `discount_percent` (forced 0 for Manual); `sort_order`; seed Regular / Senior 20 / PWD 20 / Articles Sales | ✅ | ✅ Phase 5 — `/api/company/passenger-types` + SPA page |
| Passenger-type articles | preset label + amount for Manual Amount types; label unique per type; cascade on type delete | ✅ | ✅ Phase 5 — nested `/passenger-types/{id}/articles` + inline modal |
| Fare matrix — Excel template + import | download the on-screen grid as `.xlsx`, fill it in, re-import (additive; `clear_blanks` option); reports unknown stops / bad values | ✅ | ✅ Phase 5 — `FareMatrixImportExportController` (phpspreadsheet), Template / Import buttons on the grid; `FareMatrixExcelTest` (5); round-trip verified |
| Manage stops — remove with fares | lists the blocking origin→destination pairs; "clear fares & remove" via `force` flag; fare-less phantom routes auto-removed | ✅ | ✅ Phase 5 — `RouteStopController` + SweetAlert confirm |
| Terminals | `boarding_mode` Both/Terminal/Pickup (`skipsTerminalBoarding()`); `default_route_origin` reverse-map; per-company unique name | ✅ | ✅ Phase 5 — `/api/company/terminals` + SPA page; `FleetModuleTest` |
| Franchises | LTFRB grant; company-owned; the **scope of a fare matrix** (owns its stop list + routes) | ✅ | ✅ Phase 5 — `/api/company/franchises` CRUD; "Fare Matrix" nav → franchises table; `FranchiseFareMatrixTest` |
| Route stops | ordered per **franchise**; `sort_order` lays out the grid axes; a stop with a fare can't be removed | ✅ | ✅ Phase 5 — `PUT /api/company/franchises/{id}/stops` + "Manage stops" modal |
| Routes | origin→destination pair within a franchise; created implicitly by a grid cell; `isPriced()` / `priced` scope; per-franchise unique pair | ✅ | ✅ Phase 5 — grid + `/api/company/routes` list; `FleetModuleTest` |
| Fare matrix — Excel grid | franchise → stop×stop grid, one editable cell per pair, **instant per-cell save** (BITS' autosave), discount-override button, clear-to-unprice | ✅ | ✅ Phase 5 — `App\Actions\SaveFareCell` (upsert route+fare), `FareMatrixGridController`, `FareMatrixGrid.jsx`; `FranchiseFareMatrixTest` (10) |
| Fare calculation (§4.1) | `round(amount·(1−disc%/100))` or `discounted_amount` override | ✅ | ✅ `App\Support\FareCalculator` — `FareCalculatorTest` (unit); wired into Tickets later |
| Excel template export / bulk import | download / upload the whole matrix | ⬜ | ⬜ — deferred |
| Thermal printers | `device_id`, `mac_address`, model; assigned to a bus; 58 mm label print | ✅ | ✅ `ThermalPrinterTest` — **corrected from the migration map**: legacy actually assigns a printer to a **conductor account** (`users.thermal_printer_id`, unique), not a bus — `thermal_printers` + `company_id`; `$companyCrud('thermal-printers', ...)` + `PUT .../assign` (reassigning frees the previous holder, one query, transactional); `thermalprinters.view/create/edit/delete`; SPA `pages/company/ThermalPrinters.jsx` (nav "Thermal Printers" under Fleet) — verified in browser (create + assign to a conductor). 58 mm label print itself already covered by the existing Receipt module (§J) — printers here are just the inventory/holder record |
| Admin ⇄ bus assignments | time-boxed, shift-aware (Morning/Evening), `days_mask`, `start/end_time` | ✅ | ✅ `AdminBusAssignmentTest` — `admin_bus_assignments` (`App\Models\AdminBusAssignment`), `days_mask`/`start_time`/`end_time` carried for schema parity but not yet surfaced in the UI (legacy's own save flow left them at their defaults too); conflict rule ported from BITS `App\AdminBusAccess::scheduleConflict()` — same bus **and** shift, overlapping `[effective_from, effective_to]` → rejected with the holder's name, a different shift or non-overlapping range is fine; target must be a non-conductor, active account. `$companyCrud('admin-assignments', ...)`; `adminassignments.view/create/edit/delete`; SPA `pages/company/AdminAssignments.jsx` (nav "Admin Assignments" under Fleet) — verified in browser (create + live conflict rejection). **Deferred, as already noted in §J**: using these assignments to scope reports/remittances/live-monitoring to "my buses only" (BITS `App\AdminBusAccess`) — this lands only the assignment records + management UI |

## E. Trip & ticketing operations

| Capability | BITS behaviour (see MIGRATION_MAP §4) | Status | Tests |
| --- | --- | --- | --- |
| Conductor ⇄ bus assignment | `bus_user` pivot (BITS `conductor_buses`); admin assigns via Users page | ✅ | Phase 5 — `PUT /api/company/users/{id}/buses`; checkbox modal |
| Start trip | guards: clocked in, no active trip, assigned+Active bus not already live, Active driver, Active origin, priced coverage route | ✅ | ✅ `TripLifecycleTest` — `App\Actions\StartTrip` (clocked-in gate now enforced via `conductor_attendance`) |
| Trip status machine | Departure → OnTrip → Arrived (+ Cancelled); Pickup-only origin starts OnTrip | ✅ | ✅ `TripLifecycleTest` |
| Trip reference | `T-MMDDYY-BUSNUMBER-XXXX-XXXX`, unique, generated on create | ✅ | ✅ `TripLifecycleTest` — `Trip::makeReference()` via a `creating` hook (seeder passes it explicitly — `WithoutModelEvents`) |
| Ended trip → remittance queue | ending a trip on the app makes it appear on the Remittance desk (stage `pending`) for receiving | ✅ | ✅ `TripLifecycleTest::test_an_ended_trip_appears_on_the_remittance_desk_for_receiving` |
| Mark On-Trip | bus leaves terminal; Terminal-boarding tickets blocked afterward | ✅ | ✅ `TripLifecycleTest` / `TicketingTest` |
| End trip | compute collected; `remitted_amount` defaults to full collected; balance shown | ✅ | ✅ `TripLifecycleTest` — `POST /api/conductor/trips/end` |
| Cancel trip | reason required | ✅ | ✅ `TripLifecycleTest` (per-ticket refund flagging = later) |
| Force-end trip | `tripmonitoring.forceend`; stamps `force_ended_by` | ✅ | ✅ `TripMonitoringTest` — `POST /api/company/trip-monitor/{trip}/force-end` (`force_ended_at` + reason) |
| Issue ticket | fare **server-resolved** (`FareCalculator` §4.1); passenger type + route or manual/article; qty 1–50 | ✅ | ✅ `TicketingTest` — client `fare` ignored |
| Boarding type | Terminal vs Pickup; Terminal disallowed once OnTrip | ✅ | ✅ `TicketingTest` |
| Payment method | Cash / QR (6-char reference required) / E-Wallet | ✅ | ✅ `TicketingTest` |
| Offline replay | `client_uuid` idempotency — resubmit never double-issues/charges | ✅ | ✅ `TicketingTest` |
| Ticket list | per trip, running total | ✅ | ✅ `GET /api/conductor/trips/{id}/tickets` + SPA panel |
| Group tickets | ≤30 passenger lines, one printed ticket, one payment; all-or-nothing | ✅ | ✅ `GroupTicketTest` — `ticket_groups` + `tickets.ticket_group_id`; `App\Actions\IssueTicketGroup` reuses `IssueTicket` per line inside one transaction (one bad line rolls the whole group back); `POST /api/conductor/ticket-groups`; `client_uuid` replay; SPA Single/Group toggle on the ticketing panel |
| Dispatch (barker payout) | `dispatches` row; reduces suggested remit | ✅ | ✅ `DispatchAndRemittanceTest` — `POST/DELETE /api/conductor/dispatches` on own live trip; `App\Support\TripRemittance` |
| Conductor SPA | one page: start-trip form ↔ live trip + ticketing panel + ticket list | ✅ | Phase 5 — `/conductor/trips`, verified in browser |

## F. Remittance & cash reconciliation

| Capability | BITS behaviour (see MIGRATION_MAP §4.3–4.4) | Status | Tests |
| --- | --- | --- | --- |
| Remittance lifecycle | Remitted (conductor) → **Received** (cashier counts denominations, stamps `remittance_received_at/by`) → **Approved** (manager, `remittance_approved_at/by`) | ✅ | ✅ `RemittanceReceiptTest` — `POST /api/company/remittances/{trip}/receive` (`ReceiveRemittance`), `/approve`; approve blocked until received; double-approve 422 |
| Per-trip cash count | denominations per received remittance; `counted_total`, `expected_amount`, `variance` | ✅ | ✅ `RemittanceReceiptTest` — `remittance_cash_counts` table; short count → negative variance |
| Bus/day/shift rollup | auto-built from received counts; shift by 17:00 cutoff on trip start; a manager may re-tally the denominations (void-PIN) | ✅ | ✅ `RemittanceReceiptTest` — `RollUpBusDayCashCount` sums received counts + active expenses; `GET /api/company/cash-counts`; `POST .../{id}/adjust` (`cashcount.adjust`, manager-only + void-PIN) via `AdjustCashCountDenominations` — once adjusted the rollup keeps the manual count. SPA breakdown modal: editable `BILLS \| QUANTITY \| AMOUNT` table; contributions show Trip \| Counted \| Expected \| Status \| Received by |
| Remittance summary | collected, by method, by boarding, passenger vs article, dispatch, balance, suggested, stage, counted_total | ✅ | ✅ `DispatchAndRemittanceTest` — `App\Support\TripRemittance::for($trip)` |
| Excess / short | `remittance_excess_amount` / `remittance_short_amount` from counted vs remitted at approval | ✅ | ✅ `RemittanceReceiptTest` |
| Remittance flag | `remittance_flagged` + note for disputes | ✅ | ✅ `RemittanceReceiptTest` — `POST /api/company/remittances/{trip}/flag` (set + clear) |
| Void a received count | **manager-only** (`remittances.void`) + void-PIN; rolls back out of the rollup, reopens receive; blocked once approved | ✅ | ✅ `RemittanceReceiptTest` — `App\Actions\VoidRemittanceReceipt` + `App\Support\ManagerVoidPin`; 5-try lockout (15 min); `cash_count_void_attempts` audit; per-company `void_feature_enabled`/`void_pin_required` |
| Concurrent-edit lock | `src/RemittanceLock.php` | ✅ | ✅ `RemittanceReceiptTest` — `trips.remittance_locked_by/_at` (3-min TTL); `POST/DELETE /api/company/remittances/{trip}/lock`; `receive`/`void`/second `lock` → **423** while another cashier holds it; expired lock is ignored + can be taken over; SPA holds the lock while the Receive modal is open (90 s heartbeat) + shows a "🔒 {name}" badge |
| Operational expenses | keyed to bus/day/shift; denomination breakdown; netted into the rollup (`net_cash = counted − expenses`); `expenses.void` (manager, void-PIN) restores cash | ✅ | ✅ `OperationalExpenseTest` — `op_day_expenses` + `RecordExpense`/`VoidExpense` (`ManagerVoidPin`); `GET/POST /api/company/expenses` + `/{id}/void`; `RollUpBusDayCashCount` re-nets |
| Cash count history ledger | append-only `cash_count_history` (RemitReceived/RemitVoid/Expense/ExpenseVoid) | ✅ | ✅ `RemittanceReceiptTest` + `OperationalExpenseTest` — all four event types written |
| Void Security console | per-manager PIN status, reset/unlock, audit (`admin/voidsecurity.php`) | ✅ | ✅ `VoidSecurityTest` — `GET /api/company/void-security` (+ `/attempts` feed), `POST .../{user}/reset` (clear PIN) / `/unlock` (clear lockout). Perms `voidsecurity.view/manage` → company_admin (+ view → chairman); managers can't open it. SPA `/company/void-security` under Company |

## G. Attendance

| Capability | BITS behaviour | Status | Tests |
| --- | --- | --- | --- |
| Clock in / out | `api/attendance/toggle.php`, `status.php`; source tag (app/web) | ✅ | ✅ `AttendanceTest` — `POST /api/conductor/attendance/toggle` (`App\Actions\ToggleAttendance`, row-locked so never two open periods); `GET /api/conductor/attendance` |
| Trip start gate | open `conductor_attendance` period required | ✅ | ✅ `TripLifecycleTest::test_cannot_start_a_trip_without_clocking_in` — `StartTrip` throws `attendance` error |
| Force-close | admin closes a forgotten clock-out (`attendance.manage`) | ✅ | ✅ `AttendanceTest` — `POST /api/company/attendance/{id}/close` stamps `closed_by` + note; rejects an already-closed period |
| Attendance page | filter by date + conductor (`admin/attendance.php`) | ✅ | ✅ `AttendanceTest` — `GET /api/company/attendance` (`date`, `conductor_id`, `open` filters); SPA `/company/attendance` + conductor clock bar on `/conductor/trips` |

## H. Live monitoring & tracking

| Capability | BITS behaviour | Status | Tests |
| --- | --- | --- | --- |
| Office live dashboard | fleet_status + arrived_trips, updated ≤3 s (WebSocket, DB poll) | ✅ | ✅ `LiveMonitoringTest` — `App\Support\LiveBoard` + `GET /api/company/live` (`permission:tracking.view`): `counters` (live / on_trip / at_terminal / arrived_today / stale), `live_trips` (bus, conductor, coverage, ticket count, collected, latest fix + `is_stale`), `arrived_today` (with remittance stage). SPA `pages/company/LiveMonitor.jsx` polls every 3 s |
| Fleet map | `route_bus_locations`; live→stale flip ~2 min; 30 s heartbeat | ✅ | `components/FleetMap.jsx` — Leaflet 1.9.4 + OpenStreetMap tiles, a coloured `divIcon` pin per live trip that has a fix (green → red once stale at `BusLocation::STALE_AFTER_SECONDS` = 120 s), popup with bus / conductor / coverage / tickets / collected, auto-fit bounds. Rendered on `LiveMonitor.jsx` above the fleet cards, redrawn on every 3 s poll |
| Admin Recent Trips card | `recent_trips` (newest 6) live | ✅ | ✅ `LiveBoard` returns `recent_trips` (newest 6, any status) in the same polled payload |
| Trip Monitoring page | every trip + print Arrival/Remittance receipts; force-end | ✅ | ✅ `TripMonitoringTest` + `ReceiptTest` — SPA `/company/trip-monitor` (list + filters + detail drawer with §4.3 breakdown, force-end, approve, flag); Arrival/Cancellation + Remittance receipt buttons in the detail drawer (`src=monitor`, `permission:tripmonitoring.view`) |
| Bus location ingest | `api/trips/updateLocation.php`; only accepted while conductor has a live trip on that bus | ✅ | ✅ `LiveMonitoringTest` — `POST /api/conductor/trips/location` (`permission:tracking.ping`, conductor-only): resolves the caller's own live trip (409 if none), asserts the posted `bus_id` matches it (422 otherwise), clamps a future/>1 h-stale client `recorded_at` to now, appends a `bus_locations` row |
| Transport (Reverb vs poll) | decision pending — MIGRATION_MAP §6 | ✅ | **Decided: 3 s polling** (`GET /api/company/live`), no Reverb. Payload shape is transport-agnostic so a WebSocket push can be layered on later without changing it |

## I. Fuel & energy

| Capability | BITS behaviour | Status | Tests |
| --- | --- | --- | --- |
| Diesel / gasoline records | amount_paid (= liters × price/L), station, odometer; recorder name + role stamped | ✅ | ✅ `FuelEnergyTest` — `fuel_records` + `RecordFuel`; `GET/POST/DELETE /api/company/fuel` (`fuel.view/record/delete`) |
| EV charging sessions | start/end battery %, one active session per bus, battery gained + duration | ✅ | ✅ `FuelEnergyTest` — `ev_charging_sessions` + `StartEvCharging`/`EndEvCharging` (unique `active_bus_id`); `GET/POST /api/company/charging` + `/{id}/end` (`charging.view/record`) |
| Fuel & Energy UI | tabbed page under Operations | ✅ | SPA `pages/company/FuelEnergy.jsx` (Fuel table + record modal, EV Charging table + start/end) |
| Fuel & Energy report | Reports & Analytics section | ✅ | ✅ see §J — `App\Support\Reports\FuelEnergyReport` + `/api/company/reports/fuel-energy` |

## J. Reports & receipts

| Capability | BITS behaviour | Status | Tests |
| --- | --- | --- | --- |
| Income Monitoring report | daily/weekly/monthly bus income; franchise letterhead | ✅ | ✅ `ReportsTest` — `App\Support\Reports\BusIncomeReport` + `ReportPeriod` (Mon–Sun weeks / calendar months, `ReportPeriodTest`); `GET /api/company/reports/income?period=&date=` (`reports.view`); idle buses show zeros; company-isolated; SPA `pages/company/reports/IncomeMonitoring.jsx` |
| Trip Income report | per-trip breakdown (`src/TripIncomeReport.php`) | ✅ | ✅ `ReportsTest` — `App\Support\Reports\TripIncomeReport` (per-trip Terminal/Pickup split, `net_total = fare − dispatch`); `GET /api/company/reports/trip-income?bus_id=&date=` |
| Daily Operations report | `src/DailyOperationsReport.php` | ✅ | ✅ `ReportsTest` — `App\Support\Reports\DailyOperationsReport` off `trips.op_date`/`shift`; `gross`/`remaining`/`cash_on_hand`/shift nets (AM+PM = remaining); `GET /api/company/reports/daily-operations?from=&to=&bus_id=` (`dailyops.view`). **`admin_bus_assignments` scoping deferred** — covers every company bus |
| Expense report | `src/OpDayExpenseReport.php` | ✅ | ✅ `ReportsTest` — `App\Support\Reports\ExpenseReport` (active `op_day_expenses` grouped by op_date, voided excluded); `GET /api/company/reports/expenses?from=&to=&bus_id=` (`expensereport.view`); SPA `ExpenseReport.jsx` |
| Cash Count report | `src/CashCountReport.php` | ✅ | ✅ `ReportsTest` — `App\Support\Reports\CashCountReport` (denomination + counted/remitted/net-cash totals); `GET /api/company/reports/cash-count?from=&to=&bus_id=` (`cashcountreport.view`); SPA `CashCountReport.jsx` |
| Fuel & Energy report | `src/FuelEnergyReport.php` | ✅ | ✅ `ReportsTest` — `App\Support\Reports\FuelEnergyReport` (fuel litres/cost/avg-price + EV session/battery/minute totals); `GET /api/company/reports/fuel-energy?from=&to=&bus_id=&type=` (`fuelenergyreport.view`); SPA `FuelEnergyReport.jsx` |
| Dashboard stats | Trips Today / Collected Today, 7-day collection chart, per-permission cards | ✅ | ✅ `ReportsTest` — `App\Support\Reports\DashboardStats` (today trips/tickets/collected, by-method split, 7-day zero-filled series, fleet counters, recent trips); `GET /api/company/dashboard` (`dashboard.view`); wired into SPA `Dashboard.jsx` (no-lib bar chart) |
| PDF export | `dompdf`; global paper/orientation/margins (`pdf_settings`) | ✅ | ✅ `barryvdh/laravel-dompdf` v3; `?format=pdf` on every report → `resources/views/reports/pdf/*` (shared `layout.blade.php` = letterhead + Prepared By); `ReportsTest` asserts `application/pdf`. Per-company `pdf_settings` paper size = Phase §K |
| Excel export | `phpspreadsheet` (routes, fare matrix, reports) | ✅ | ✅ `?format=xlsx` on every report → `App\Support\Reports\ReportSpreadsheet` (title + amber heading row + bold totals row); `ReportsTest` asserts the spreadsheet content-type. (Fare matrix Excel was already done in Phase 5.) |
| Report letterhead / prepared-by | `src/ReportLetterhead.php`, `src/PreparedBy.php` | ✅ | ✅ `App\Support\Reports\ReportLetterhead::forCompany()` (org name / reg + OTC numbers / contact / optional franchise route line, logo as base64 data URI) + `PreparedBy::forUser()` (name / role / generated-at); rendered by `reports/pdf/layout.blade.php` |
| Departure receipt | thermal width; org name/footer from settings | ✅ | ✅ `ReceiptTest` — `ReceiptFactory::departure()`; plain opening slip → "TERMINAL RECEIPT" with Cash/QR/Total once `marked_on_trip_at` is set |
| Ticket receipt | passenger type, route, boarding, method, fare, footer | ✅ | ✅ `ReceiptTest` — `ReceiptFactory::ticket()`; `?qty=` multiplies (Qty / Unit Fare / TOTAL rows), QR ref only for QR payments |
| Dispatch receipt | barker name + amount | ✅ | ✅ `ReceiptTest` — `ReceiptFactory::dispatch()` |
| Remittance receipt | full summary; balance | ✅ | ✅ `ReceiptTest` — `ReceiptFactory::remittance()` off `TripRemittance` §4.3; + `arrival()` ("ARRIVAL SUCCESSFULLY CLOSED" / "TRIP CANCELLED") |
| Shift summary receipt | end-of-shift totals | ✅ | ✅ `ReceiptTest` — `ReceiptFactory::shiftSummary()`; every trip today on the conductor's Active buses, gross/discount/net, BITS discount formula |
| Receipt print view | thermal-width HTML, auto-print | ✅ | `pages/ReceiptView.jsx` at `--tf-receipt-w` = `receipt_width_mm`; `@media print` strips chrome; opened in a new tab via `lib/receipt.js` `openReceipt()`; wired on Remittances, Trip Monitor detail, Conductor Trip (header + ticket/dispatch rows + post-end prompt) |

## K. Settings, branding & platform tooling

| Capability | BITS behaviour | TransitFlow change | Status |
| --- | --- | --- | --- |
| Branding (logo, color palette) | single global `system_settings` row | **per-company** `company_settings` | ✅ `CompanySettingsTest` — `Api\Company\CompanySettingController` (`GET/PUT /api/company/settings`), `color_accent`/`color_accent_dark` (hex-validated); logo upload `POST /api/company/settings/logo` (png/jpg/webp ≤2 MB → `public` disk `company-{id}/branding`, old file swept), `DELETE` to clear; SPA `pages/company/CompanySettings.jsx` (nav "Settings" under Company) |
| QR payment image | `qr_payment_path` | per-company | ✅ `CompanySettingsTest` — `POST/DELETE /api/company/settings/qr-payment` (same upload pipeline) |
| Receipt text | `receipt_org_name`, `ticket_footer`, `receipt_width_mm` | per-company | ✅ `CompanySettingsTest` — `receipt_width_mm` range-checked 40–120; live receipt preview on the Settings page; already consumed by `ReceiptFactory` + report letterhead |
| Organization identity | `org_name`, registration/OTC accreditation numbers, address parts, email, contact | per-company | ✅ `CompanySettingsTest` — `registration_number` / `otc_accreditation_number` / `org_email` / `org_contact_number` on `company_settings`; nullable fields coalesced to `''` (columns are `NOT NULL DEFAULT ''`). Address parts stay on `companies` (Company Profile) |
| Configuration (read-only view) | `admin/configuration.php` shows `.env`-derived values | per-company + platform split | ✅ `CompanySettingsTest` — `GET /api/company/configuration` (`Api\Company\ConfigurationController`, `permission:company.settings.view`): non-secret snapshot only (app name / env / timezone / DB timezone / currency / Laravel + PHP versions / queue + mail drivers, the company's status + `can_create_accounts`, and its feature flags + PDF setup). Never a key/password/DSN. Shown on the Settings page's "Runtime configuration" card |
| PDF generation settings | `pdf_settings` + audit | platform or per-company (pending) | ✅ **per-company** (MIGRATION_MAP §6 decision #1 resolved) — `company_settings.pdf_paper_size` (a4/letter/legal) / `pdf_orientation` (portrait/landscape) / `pdf_margin_mm` (0–40). `ReportController` reads them for `Pdf::setPaper()` + `@page { margin }` in `reports/pdf/layout.blade.php`; editable on the Settings page. `CompanySettingsTest` — stored, validated, report still renders |
| Franchises | LTFRB franchise list for Income Monitoring letterhead | per-company; **not a tenant** | ✅ Phase 5 §D — `/api/company/franchises` CRUD; the letterhead pulls a franchise's route line via `ReportLetterhead::forCompany($company, $franchise)` |
| Mobile App distribution | APK upload/publish, version code, force-update, self-updating base URL | per-company; keep `api/meta/serverConfig.php` contract | ✅ `MobileAppTest` — `mobile_app_settings` table + `MobileAppSetting` model (`updateStatusFor()` = version_compare + versionCode + minimum-version floor). **Public contract:** `GET /api/meta/server-config?company={code}` + `GET /api/meta/check-update?company=&version=&version_code=` (unauthenticated, throttled, Active company only). **Admin:** `GET/PUT /api/company/mobile-app` (`mobileapp.view`/`mobileapp.manage`), `POST/DELETE .../apk` (`extensions:apk`, ≤150 MB → `public` disk). SPA `pages/company/MobileApp.jsx` (version fields, force-update, APK upload, copy-able server-config URL). App itself still not built — contract only |
| Backup & Restore | full DB dump / restore-all | rethink for multi-tenant (per-company export) | ✅ `DataToolsTest` — `GET /api/company/data-tools/export` (`permission:backup.download`) streams a pretty-printed JSON of every row this company owns across `App\Support\CompanyDataTables::MASTER + ::TRANSACTIONAL` (`App\Actions\ExportCompanyData`); audited in `data_operations`. **Restore is intentionally not built** (per-company import is high-risk; export covers the backup need) |
| Clean Data | wipe transactional rows, keep structure + master data; audit | per-company scope | ✅ `DataToolsTest` — `App\Actions\CleanCompanyData` deletes only `CompanyDataTables::TRANSACTIONAL` (13 tables, child→parent) for the caller's own company, in one transaction; master data + users + roles kept. `GET .../clean-preview` (counts), `POST .../clean` (`permission:cleandata.run`, requires `confirm` == company `code` via `hash_equals`), `GET .../history` audit feed. SPA `pages/company/DataTools.jsx` (export button + danger-zone Clean with type-to-confirm + history) |
| Device monitoring | registered conductor devices, last-seen | per-company | ✅ `DeviceMonitoringTest` — `devices` table + `Device` model (`STALE_AFTER_MINUTES = 15`, `isOnline()`, `scopeStale`). `POST /api/conductor/devices/register` (`permission:devices.register`, conductor) upserts by `(company, device_uuid)` and refreshes `last_seen_at` — doubles as the heartbeat (201 on create / 200 on re-register). `GET /api/company/devices` (`devices.view`, filters: `platform`, `stale`) + `DELETE .../{device}` (`devices.delete`, manager/admin). SPA `pages/company/Devices.jsx` (nav "Devices" under Company) — online/offline badge, last-seen, deregister |

| **System Configuration — platform-wide Server Base URL** | no BITS equivalent (single-tenant, one hardcoded server) | new — Super Admin only | ✅ `SystemConfigurationTest` — `system_settings` (key/value) + `system_setting_history` (append-only, mirrors `cash_count_history`'s shape); `App\Support\ServerConfig` validates (well-formed, HTTPS outside `local`/`testing`, never a loopback host) then **tests** (`GET {url}/health`) BEFORE ever applying — a failed test never overwrites the working URL, only logs a `failed` history row. `GET/PUT /api/super-admin/system-configuration`, `POST .../test` (no side effects), `GET .../history`, `POST .../history/{id}/rollback` (re-tests the target before restoring it); `system.configuration.view`/`.manage` (Super Admin only — `Permission::PLATFORM_GROUPS`). SPA `pages/superadmin/SystemConfiguration.jsx` (nav "System Configuration" under Platform), verified in browser (live failed-connection round trip). `Api\Meta\ServerConfigController::publicApiBaseUrl()` resolution order: a company's own `mobile_app_settings.api_base_url` override → this platform-wide default → routable `config('app.url')`; `/api/meta/server-config` now also returns `config_version` so a client can tell a real change from a repeat. **Android** (`AndroidStudioProjects\TransitFlow`): `SessionStore` gained `previous_base_url`/`config_version`/`last_verified_at`; `MetaRepository.refreshServerConfig()` (called once per Home-screen load) probes a pushed candidate URL on its own short-lived client — independent of the shared Retrofit/OkHttp singleton — and only calls `SessionStore.applyResolvedBaseUrl()` (which snapshots the previous URL as the fallback) once that candidate's own `/health` responds; a failed probe leaves the working server untouched and retries next load. The existing manual Server Config screen (`ServerConfigViewModel`/`AuthRepository.setup()`) is unchanged. |

## L. Cross-cutting quality

| Concern | Status | Notes |
| --- | --- | --- |
| Responsive UI | 🟡 | Bootstrap 5 baseline in place (Phase 1) |
| Input validation (Form Requests) | ✅ | every Phase-5 write endpoint uses a `FormRequest` (`StoreFuelRecordRequest`, `UpdateCompanySettingsRequest`, `RecordBusLocationRequest`, `CleanCompanyDataRequest`, …) or an inline `$request->validate()`; no ad-hoc PHP guards remain |
| Authorization policies per resource | ✅ | `CompanyPolicy` / `BusPolicy` / `TripPolicy` / `UserPolicy` + `Gate::before` super-admin bypass + `permission:<key>` middleware on **every** route + `CompanyScope` global scope on every company-owned model (cross-company id 404s at the router — `CompanyIsolationTest`) |
| Idempotency for offline sync | ✅ | `tickets.client_uuid` / `ticket_groups.client_uuid` — resubmitting returns the original rows, never double-issues or double-charges (`TicketingTest`, `GroupTicketTest`); `devices.register` and `SaveFareCell` are upserts |
| Audit trails | ✅ | `AuditLogTest` — consolidated `audit_logs` table + `App\Support\Audit::record()` (best-effort, never throws) + `App\Models\Concerns\LogsActivity` trait (auto-logs create/update/delete on `CompanySetting` / `MobileAppSetting` with the changed columns). Explicit calls on: remittance approve/void, trip force-end, role create/update/delete, per-user permission sync/reset, company status change / provision / feature-access change. Plus the pre-existing dedicated logs — `cash_count_history`, `cash_count_void_attempts`, `data_operations`. `GET /api/company/audit-log` (`permission:audit.view`, company-scoped, filterable) + `GET /api/super-admin/audit-log` (platform-wide incl. `company_id`-null actions); SPA `pages/company/AuditLog.jsx` |
| Seed data for local dev | ✅ | `RbacSeeder` (permission catalogue + role templates + grants, from BITS §3) + `PlatformSeeder` + `DemoCompanySeeder` (two demo companies with fleet, franchises + fare grid, passenger types, users per role, live/arrived/received operational data, conductors clocked in) — `php artisan migrate:fresh --seed` |
| Automated tests per migrated module | ✅ | 269 feature/unit tests, 975 assertions — every migrated module has its own feature test as the parity gate |

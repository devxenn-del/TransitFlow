# Migration Map — BITS → TransitFlow

Maps the legacy **BITS — Bus Income Tracking System** (`C:\xampp\htdocs\BITS`,
vanilla PHP + MySQL/MariaDB, read-only reference) onto the new **TransitFlow —
Smart Transport Management Platform** (Laravel 13 API + React 19 SPA).

> Status legend: ⬜ not started · 🟡 in progress · ✅ done

Phase 2 analysis is complete. Implementation status stays ⬜ until each area is
built in Phases 3–5.

---

## 0. Executive summary

BITS is a **single-tenant** system for one transport cooperative. Conductors run
trips and issue tickets (web pages **or** a native Android app hitting a parallel
JSON API); admins manage the fleet, fares, accounts and permissions; office staff
watch trips live over a WebSocket; managers reconcile cash. There is **no company
/ tenant concept anywhere** — not in the schema, auth, or business logic. The
`franchises` table is LTFRB franchise reference data for one report, **not** a
tenant boundary.

Implications for TransitFlow:

- **Multi-company is 100% net-new** (Phase 3). Almost every BITS master/
  transactional table becomes company-owned and needs a `company_id` +
  global scope; a handful stay platform-global.
- **Auth is rebuilt on Laravel** (Sanctum). BITS' bespoke session +
  `selector:validator` token scheme maps cleanly onto Sanctum SPA cookies (web)
  and Sanctum personal access tokens (mobile).
- **Permission model is portable as-is**: BITS already has
  `permissions` / `permission_groups` / `role_permissions` / `user_permissions`
  with per-user overrides. Keep the shape; add company scoping.
- **Business calculations must be carried over verbatim** — fare, remittance,
  cash-count, expense-void. See §4.

---

## 1. Legacy surface area

| BITS path | Purpose | TransitFlow target | Status |
| --- | --- | --- | --- |
| `index.php`, `auth/authenticate_login.php` | Web login (session + bcrypt + remember-me) | `POST /api/auth/login` (Sanctum SPA), React login page | ⬜ |
| `auth/authorize.php` | Per-request login guard, remember-me restore, role/portal routing, Manila TZ pin | Laravel auth middleware + `EnsureFrontendRequestsAreStateful`; TZ in `config/app.php` | ⬜ |
| `auth/hasPermission.php` | `hasPermission($key)` → `user_permissions` lookup | Gate / Policy + `permission:` middleware | ⬜ |
| `auth/apiAuth.php` | Bearer-token auth for the mobile app (`api_tokens`), conductor-only, daily auto-logout | Sanctum tokens with abilities; scheduled/`tokenable` expiry | 🟡 — conductor-only entry point done via `/auth/pin-login` (§B); daily auto-logout / token expiry still open |
| `auth/accountLock.php` | Shift-end / admin account locks, on-read expiry at 03:59 | `users.locked_at/lock_type` + middleware check | ✅ — `App\Support\AccountLock` + `EnsureAccountNotLocked` middleware (§B) |
| `admin/*.php` (≈229 files) | Back-office: fleet, fares, accounts, permissions, reports, settings | `app/Http/Controllers/Admin/*` + React `pages/admin/*` | ⬜ |
| `conductor/*.php` | Web conductor app: start/end trip, ticketing, dispatch, remittance | `Controllers/Conductor/*` + React `pages/conductor/*` (web parity) | ⬜ |
| `office/*.php` | Live read-only trip/fleet monitoring + office-scoped ops pages | `Controllers/Office/*` + React `pages/office/*` | ⬜ |
| `api/**` (JSON, bearer token) | Mobile conductor API — parallel copy of the conductor workflow | **Unify** into the single `/api` used by SPA + mobile | ⬜ |
| `receipt/conductor/*.php` | Thermal receipts (departure, ticket, dispatch, remittance, shift summary) | API endpoints returning receipt DTOs; React/print + mobile render | ✅ Phase 5 — `App\Support\Receipts\{ReceiptFactory,ReceiptDocument}`; `ReceiptController`; `pages/ReceiptView.jsx` |
| `src/*.php` (`App\` namespace) | Shared domain classes (reports, cash count, settings, trip monitor, PDF/Excel) | `app/Services/*`, `app/Support/*`, Eloquent models | ⬜ |
| `websocket/server.php`, `websocket/TripMonitorServer.php` | Ratchet WS, broadcast-only, DB polled every 3 s | Laravel Reverb (or polling endpoint) — decide in Phase 5 | ⬜ |
| `config/` | `.env` loader, mysqli connection, session, route-stop helpers | Laravel `config/*` + `.env` (already done in Phase 1) | ✅ n/a |
| `BITS_DB.sql` | Structure + append-only migration log (each block its own txn) | `database/migrations/*` | 🟡 |
| `BITS_DB_SEED.sql` | Reference/starter data (roles, permissions, routes, fares, accounts) | `database/seeders/*` | ⬜ |
| Vendor libs: `dompdf/dompdf`, `phpoffice/phpspreadsheet`, `cboden/ratchet` | PDF, Excel, WebSocket | `barryvdh/laravel-dompdf` or `spatie/laravel-pdf`; `maatwebsite/excel`; Reverb | ⬜ |

Third project (out of scope, reference only): **BITSCONDUCTOR** native Android app
— consumes `api/`. TransitFlow keeps the API mobile-ready but does not build the app now.

---

## 2. Database mapping

Classification: **P** platform-wide · **G** global reference · **C** company-owned
· **U** user-specific · **X** drop / replace with framework equivalent.

### 2.1 Identity, roles & permissions

| Legacy table | Class | TransitFlow | Notes | Status |
| --- | --- | --- | --- | --- |
| `users` (`id, name, email, password, role_id, status, pin, void_pin_hash, security_pin_hash, locked_at, lock_type, locked_by, employee_id, bus_id, driver_id, bus_assigned_on, remember_token, timestamps`) | C | `users` + `companies` FK (`company_id` nullable — null = platform Super Admin) | Split PIN/lock/employee fields into a `user_profiles` or keep flat; `bus_id`/`driver_id` move to pivots (see below) | 🟡 — `void_pin_hash`, `employee_id`, `pin_hash`, `locked_at`/`lock_type`/`locked_by`, `bus_user`/`conductor_buses` pivot all done; only `security_pin_hash` still open (deferred — §B) |
| `account_details` (`user_id, first/middle/last_name, email, phone, address, sex_id`) | C | merge into `users` or `user_profiles` | BITS already de-duped contacts into here | ✅ — kept flat on `users` (`first_name`/`middle_name`/`last_name`/`phone`/`address`/`sex`); `sex` is a plain `Male`\|`Female` string, no `sexes` table |
| `account_contacts` | X | drop — superseded by `account_details` | migration log already nulled/merged it | ✅ — never created |
| `sexes` | G | `users.sex` plain string | legacy seed only ever had 2 rows (Male/Female) — no lookup table, validated `in:Male,Female` | ✅ |
| `roles` (`super_admin, admin, manager, chairman, conductor, office`) | P (templates) + **C (per-company copies)** | `roles` + `company_id` (null = platform template) + `is_admin` | ✅ Phase 5 — templates seeded; `SeedCompanyRoles` clones the 5 non-platform ones (+ grants) into every company; companies edit their own set via `/api/company/roles` |
| `permissions` (`permission_key`, `permission_group_id`, `nav_label/url/icon/order`) | P | `permissions` — carry `permission_key` verbatim, keep nav metadata | ~120+ keys; see §3 for the full group list | ⬜ |
| `permission_groups` | P | `permission_groups` | ~30 groups | ⬜ |
| `role_permissions` (`role_id, permission_id, allowed`) | P/C | `role_permissions` — default grants per role | BITS seeds these at account creation, not enforced live | ⬜ |
| `user_permissions` (`user_id, permission_id, allowed`) | U | `user_permissions` — **the live authorization source** in BITS | Per-user override; `allowed=1` required. Keep this semantics. | ⬜ |
| `remember_tokens` (`selector, validator_hash, expires_at`) | X | Sanctum SPA session cookie | drop table | ⬜ |
| `api_tokens` (`selector, validator_hash, expires_at`) | X | `personal_access_tokens` (Sanctum) | conductor-only ability; 365-day expiry; daily auto-logout → scheduled prune | ⬜ |
| `devices` / (old `conductor_devices`) | C | `devices` (`company_id`, `user_id`, `device_id`, `device_model`, seen timestamps) | device monitoring page | ⬜ |

### 2.2 Fleet & network

| Legacy table | Class | TransitFlow | Notes | Status |
| --- | --- | --- | --- | --- |
| `buses` (`bus_number, plate_number, capacity, model, vehicle_type[electric/diesel/gasoline], status[Active/Inactive/Maintenance], thermal_printer_id`) | C | `buses` + `company_id`; unique keys become `(company_id, bus_number)` / `(company_id, plate_number)` | `thermal_printer_id` actually lives on `users` in legacy, not here — see the `thermal_printers` row below | ✅ |
| `drivers` (`name, employee_id, license_number, contact_number, status`) | C | `drivers` + `company_id` | login-free record, picked per trip | ⬜ |
| `terminals` (`name, default_route_origin, boarding_mode[Both/Terminal/Pickup], status`) | C | `terminals` + `company_id` | `boarding_mode` drives trip start (Pickup-only → trip starts `OnTrip`) | ⬜ |
| `routes` (`name, origin, destination, status`) | C | `routes` + `company_id` | origin/destination are free-text stop names | ⬜ |
| `route_stops` (`name, sort_order, status`) | C | `route_stops` + `company_id` | pick-up point catalog | ⬜ |
| `fare_matrix` (`route_id UNIQUE, amount, discounted_amount, status`) | C | `fare_matrix` (`route_id` unique) | `discounted_amount` = manual override for discounted pax — see §4.1 | ⬜ |
| `passenger_types` (`name, fare_mode[Fare Matrix/Manual Amount], discount_percent, sort_order, status`) | C | `passenger_types` + `company_id` | seed: Regular 0%, Senior 20%, PWD 20%, Articles Sales (manual) | ⬜ |
| `passenger_type_articles` (`passenger_type_id, label, amount, sort_order, status`) | C | `passenger_type_articles` | preset articles for Manual Amount types | ⬜ |
| `thermal_printers` (`device_id, mac_address, model, status`) | C | `thermal_printers` + `company_id`; `users.thermal_printer_id` FK (unique — legacy assigns to a **conductor**, not a bus) | | ✅ |
| `conductor_buses` (`user_id, bus_id`) | C | pivot `bus_user` (conductor ⇄ bus, many-to-many) | replaced the old `users.bus_id` single assignment | ✅ Phase 5 |
| `admin_bus_assignments` (`bus_id, user_id, effective_from/to, shift[Morning/Evening], start_time, end_time, days_mask, status, assigned_by`) | C | `admin_bus_assignments` + `company_id` | office-admin ⇄ bus, time-boxed, shift-aware; conflict = same bus + shift + overlapping dates (`App\Models\AdminBusAssignment::scopeConflicting`) | ✅ — records + CRUD only; report/remittance scoping by these assignments (BITS `App\AdminBusAccess`) is a deferred follow-up (§J) |

### 2.3 Operations (trips, tickets, dispatch, tracking)

| Legacy table | Class | TransitFlow | Notes | Status |
| --- | --- | --- | --- | --- |
| `trips` | C | `trips` + `company_id` (denormalized from conductor/bus) | Columns: `conductor_id, origin, coverage_origin, coverage_destination, bus_number, bus_id, driver_id, status[Departure/OnTrip/Arrived/Cancelled], started_at, ended_at, terminal_receipt_printed_at, cancelled_at, cancellation_reason, force_ended_by, remitted_amount, remittance_approved_at/by, remittance_received_at/by, remittance_excess_amount, remittance_short_amount, remittance_flagged, remittance_flag_note`. See §4.2–4.3 | ⬜ |
| `tickets` | C (via trip) | `tickets` | `trip_id, ticket_group_id, route_id (nullable), boarding_type[Terminal/Pickup], payment_method[Cash/E-Wallet/QR], qr_reference(6), passenger_type_id, article_label, fare, refunded_at, issued_at, client_uuid` | ⬜ |
| `ticket_groups` (`trip_id, client_uuid, boarding_type, payment_method, line_count, passenger_count, total_fare, issued_by`) | C (via trip) | `ticket_groups` | group ticket = many pax boarding together, one printed ticket | ✅ Phase 5 — `App\Models\TicketGroup` + `IssueTicketGroup` (all-or-nothing) |
| `dispatches` (`trip_id, barker_name, amount, dispatched_at`) | C (via trip) | `dispatches` | barker (dispatcher) payouts, deducted from remit suggestion | ✅ Phase 5 — `App\Models\Dispatch`; conductor CRUD on own live trip |
| `bus_locations` (`bus_id UNIQUE, trip_id, lat, lng, accuracy_meters, recorded_at`) | C | `bus_locations` | one current-position row per bus (upsert) | ⬜ |
| `conductor_attendance` (`user_id, clock_in_at, clock_out_at, clock_in_source, clock_out_source`) | C | `conductor_attendance` | conductor must have an open period to start a trip | ✅ Phase 5 — `App\Models\Attendance` (+ `closed_by`/`closed_note` for admin force-close) |

### 2.4 Cash reconciliation & expenses

| Legacy table | Class | TransitFlow | Notes | Status |
| --- | --- | --- | --- | --- |
| `bus_day_cash_counts` (`bus_id, user_id, op_date, shift[Morning/Evening], q1000..q1, total_amount, status[Completed/Voided/Replaced], voided_by/at, void_reason, replaced_by_id, active_bus_day(unique), completed_at/by`) | C | `bus_day_cash_counts` | denomination count per bus/day/shift; unique on `active_bus_day` while Completed; void needs manager PIN | ✅ Phase 5 — `App\Models\CashCount`; `RecordCashCount` / `VoidCashCount` actions |
| `op_day_expenses` (`user_id, op_date, cash_count_id, bus_id, shift, exp_time, description, amount, q1000..q1, status[Active/Voided], voided_by/at, void_reason`) | C | `op_day_expenses` | operational expense w/ denomination breakdown; void restores cash | ✅ Phase 5 — `App\Models\OpExpense`; keyed by `(bus_id,op_date,shift)`; netted into `bus_day_cash_counts.net_cash`; `RecordExpense`/`VoidExpense` |
| `cash_count_history` (`cash_count_id, bus_id, op_date, shift, type[CashCount/Expense/ExpenseEdit/ExpenseVoid], ref_code, description, amount, q1000..q1, recorded_by(_name), authorized_by(_name)`) | C | `cash_count_history` | append-only ledger of every cash-count/expense event | 🟡 Phase 5 — `App\Models\CashCountHistory` (CashCount + void events; Expense* with the expenses slice) |
| `cash_count_void_attempts` (`manager_id, requested_by, cash_count_id, success, detail, attempted_at`) | C | `cash_count_void_attempts` | manager void-PIN audit | ✅ Phase 5 — `App\Models\CashCountVoidAttempt` (written on every PIN check) |
| `fuel_records` (`bus_id, fuel_type[diesel/gasoline], amount_paid, liters, price_per_liter, fueled_at, station, notes, created_by(_name/_role)`) | C | `fuel_records` | `amount_paid` derived = liters×price/L | ✅ Phase 5 — `App\Models\FuelRecord` + `RecordFuel` |
| `ev_charging_sessions` (`bus_id, status[charging/completed], start_at, battery_start_pct, end_at, battery_end_pct, notes, started/ended_by(_name), active_bus_id(unique)`) | C | `ev_charging_sessions` | one active session per bus (`active_bus_id` unique) | ✅ Phase 5 — `App\Models\EvChargingSession` + Start/End actions |

### 2.5 Platform / settings

| Legacy table | Class | TransitFlow | Notes | Status |
| --- | --- | --- | --- | --- |
| `system_settings` (single row id=1): `logo_path, qr_payment_path, mobile_app_*`, color palette (`color_accent…color_muted`), `receipt_width_mm, receipt_org_name, ticket_footer, org_name, registration_number, otc_accreditation_number, address_* , org_email, org_contact_number, mobile_auto_logout_time, void_feature_enabled, void_pin_required` | **C** | `company_settings` (one row per company) | BITS' single global row becomes **per-company branding + receipt + org identity + mobile config**. This is a major multi-tenant split. | ⬜ |
| `pdf_settings` (single row: `paper_size, paper_width/height_mm, orientation, margins`) + `pdf_settings_audit` | P or C | `pdf_settings` — decide platform-global vs per-company in Phase 3 | leaning per-company | ⬜ |
| `franchises` (`applicant_name, route_description, route_origin, route_destination, case_no, status`) | C | `franchises` + `company_id` | LTFRB franchise list for the Income Monitoring report — **not** a tenant | ⬜ |
| `clean_data_audit` (`run_by(_name), backup_filename/bytes, tables_json, total_deleted, status, notes`) | P | `clean_data_audit` | Super Admin "wipe transactional data" audit | ⬜ |
| Laravel framework tables | X | `sessions`, `cache`, `jobs`, `personal_access_tokens`, `migrations` | already created in Phase 1 | ✅ |

---

## 3. Module mapping

Nav order / permission groups observed in BITS (group → key prefix):

Dashboard (`dashboard.view.*`), Accounts (`accounts.*`, `accounts.lock`),
Permissions (`permission.*`), Access Control (`accesscontrol.*`),
Conductors (`conductors.*`), Conductor portal (`conductor.view`),
Routes (`routes.*`), Fare Matrix (`farematrix.*`), Terminals (`terminals.*`),
Buses (`buses.*`), Drivers (`drivers.*`), Passenger Types (`passengertypes.*`),
Thermal Printers (`thermalprinters.*`, `.print`), Tracking (`tracking.view`),
Devices (`devices.view/delete`), Trip Monitoring (`tripmonitoring.view/forceend`),
Remittances (`remittances.view/approve`), Attendance (`attendance.view/manage/clock`),
Income Monitoring (`reports.view`), Daily Operations Report (`dailyops.view`),
Admin Assignments (`admin_assignments.*`), Office Admin Expenses (`expenses.*`, `.void`),
Cash Count (`cashcount.*`, `.void`), Void Security (`voidsecurity.manage`),
Expense Report (`expensereport.view`), Cash Count Report (`cashcountreport.view`),
Fuel & Energy Management (`fuelenergy.view`, `fuel.diesel.*`, `fuel.gasoline.*`, `ev.charging.*`),
Fuel & Energy Report (`fuelenergyreport.view`), Office (`office.view`),
Settings group (`settings.*`, `printing.*`, `payment.*`, `franchises.*`,
`configuration.manage`, `mobileapp.*`, `pdfsettings.*`, `cleandata.run`),
Backup & Restore (`backup.manage/restore/download`).

| Legacy module | BITS entry points | Business rules to preserve | TransitFlow API (planned) | TransitFlow UI | Status |
| --- | --- | --- | --- | --- | --- |
| Authentication | `index.php`, `auth/*`, `api/auth/*` | bcrypt; status must be `Active`; account-lock check (shift_end/admin, on-read expiry ~03:59); conductor mobile login is conductor-role + `conductor.view` only; remember-me 30 d; API token 365 d + optional daily auto-logout time | `POST /api/auth/login|logout`, `GET /api/auth/user`, `POST /api/auth/pin-login`, `POST /api/auth/verify-pin` | React login, PIN unlock | ⬜ |
| Roles & Permissions | `admin/permission.php`, `admin/accessscontrol.php`, `process/permission`, `process/accesscontrol` | Live checks read **`user_permissions`** only; role grants are copied to `user_permissions` at account creation via `src/AccountDefaults.php`; nav is built from `permissions.nav_*` | `GET/PUT /api/admin/permissions`, `/roles` | Permissions matrix, Access Control | ⬜ |
| Accounts / Users | `admin/accounts.php`, `admin/conductors.php`, `admin/profile.php`, `fetch/account`, `process/account`, `process/conductors` | employee_id auto-format `E-YYMM-#######`; lock/unlock; conductor gets `conductor.view` + bus assignment(s); `src/ProfilePolicy.php`, `src/EmployeeId.php`, `src/AccountDefaults.php` | `/api/admin/users`, `/api/admin/conductors` | Accounts, Conductors, Profile | ⬜ |
| Companies *(new)* | — | none in BITS | `/api/superadmin/companies` (CRUD, activate/suspend), `/api/company/settings` | Super Admin console, Company settings | ⬜ |
| Buses | `admin/buses.php`, `process/buses`, `fetch/buses` | unique bus_number & plate; `vehicle_type` gates Fuel vs EV; `status` Maintenance excludes from trips | `/api/company/buses` | Buses | ⬜ |
| Drivers | `admin/drivers.php`, `src/DriverAvailability.php` | login-free; picked per trip; a driver can't be on two live trips | `/api/company/drivers` | Drivers | ⬜ |
| Terminals | `admin/terminals.php` | `boarding_mode` (Both/Terminal/Pickup); Pickup-only ⇒ trip starts `OnTrip`; `default_route_origin` reverse-maps the "To" picker | `/api/company/terminals` | Terminals | ⬜ |
| Routes | `admin/routes.php`, `admin/export/routes`, `config/routeStopHelpers.php` | origin/destination are stop names; Active + priced (fare_matrix Active) required to be usable | `/api/company/routes` | Routes | ⬜ |
| Fare Matrix | `admin/farematrix.php`, `admin/export/farematrix` | one row per route; `amount` + optional `discounted_amount` override; **see §4.1** | `/api/company/fare-matrix` | Fare Matrix | ⬜ |
| Passenger Types | `admin/passengertypes.php` | `fare_mode` Fare Matrix vs Manual Amount; `discount_percent`; Manual Amount + presets ⇒ must match a preset | `/api/company/passenger-types` | Passenger Types | ⬜ |
| Trips (conductor) | `conductor/start_trip.php`, `process/trips/*`, `api/trips/*` | one active trip/conductor; one live trip/bus; must be clocked in; status machine Departure→OnTrip→Arrived (+Cancelled); force-end (`tripmonitoring.forceend`); shift-end confirmation | `/api/conductor/trips` (start/end/cancel/mark-on-trip/active/history) | Conductor trip flow | ⬜ |
| Ticketing | `conductor/ticketing.php`, `process/tickets/issueTicket.php`, `api/tickets/*` | **fare always server-resolved**; Terminal sales blocked once `OnTrip`; QR requires 6-digit ref; group tickets (≤30 lines, ≤50 qty/line); offline replay via `client_uuid` (idempotent) | `/api/conductor/tickets` (issue, list, article-sales) | Ticketing | ⬜ |
| Dispatch | `conductor/process/dispatch/issueDispatch.php`, `api/dispatch/issue.php` | barker payout; reduces suggested remit | `/api/conductor/dispatches` (+ `DELETE .../trips/{trip}/dispatches/{id}`) | Dispatch panel on Conductor page | ✅ Phase 5 |
| Remittance | `conductor/remittance.php`, `api/trips/remittance*.php`, `office/remittances.php`, `admin/remittances.php` | **see §4.3**; approve + receive + excess/short + flag workflow; `src/RemittanceLock.php` | `/api/company/remittances/{trip}/{receive,void,approve,flag,lock}` + `/api/company/trip-monitor/{trip}/force-end` | Remittances + Trip Monitoring pages | ✅ Phase 5 — receive/void/approve/excess-short/flag/force-end + `RemittanceLock` (3-min advisory lock) + thermal remittance/arrival receipts |
| Attendance | `admin/attendance.php`, `api/attendance/*`, `src/Attendance.php` | clock in/out; open period required for trip start; force-close forgotten clock-outs | `/api/conductor/attendance(/toggle)`, `/api/company/attendance(/{id}/close)` | Attendance page + conductor clock bar | ✅ Phase 5 |
| Live monitoring | `office/dashboard.php`, `office/tracking.php`, `admin/tripmonitoring.php`, `admin/tracking.php`, `websocket/*`, `src/TripMonitor.php`, `src/BusLocation.php`, `src/OfficeDashboardStats.php` | broadcast-only; DB polled every 3 s; payloads fleet_status / arrived_trips / recent_trips / route_bus_locations | `GET /api/company/live` poll (3 s) + `POST /api/conductor/trips/location` ingest | `pages/company/LiveMonitor.jsx` + Trip Monitoring | ✅ Phase 5 — `LiveMonitoringTest`; tile map deferred (§H) |
| Cash Count | `admin/cashcount.php`, `office/cashcount.php`, `src/CashCount.php`, `src/CashCountHistory.php`, `src/ManagerVoidPin.php` | **see §4.4**; per bus/day/shift; denominations; void needs manager void-PIN; history ledger | `/api/company/cash-counts(/{id}/void)`, `/api/company/void-pin` | Cash Count page | 🟡 Phase 5 — count + void + replace + void-PIN done; op-expenses + admin void-security console deferred |
| Operational Expenses | `admin/expenses.php`, `office/expenses.php`, `src/OpExpense.php` | expense tied to a cash count; denomination breakdown; `expenses.void` restores cash | `/api/company/expenses` | Operational Expenses | ⬜ |
| Void Security | `admin/voidsecurity.php`, `src/SecurityPin.php`, `src/ManagerVoidPin.php` | per-manager void-PIN status, reset/unlock, lockout after failed attempts, audit | `/api/company/void-security` (+ `/attempts`, `/{user}/reset`, `/{user}/unlock`) | Void Security | ✅ Phase 5 — company_admin console; `App\Support\ManagerVoidPin` |
| Fuel & Energy | `admin/fuelenergy.php`, `src/FuelEnergy.php` | diesel/gasoline records; EV charging sessions (one active/bus); role stamped on each row | `/api/company/fuel-energy` | Fuel & Energy | ⬜ |
| Reports | `admin/reports.php`, `admin/dailyoperations.php`, `admin/expensereport.php`, `admin/cashcountreport.php`, `admin/fuelenergyreport.php`, `src/BusIncomeReport.php`, `src/TripIncomeReport.php`, `src/DailyOperationsReport.php`, `src/OpDayExpenseReport.php`, `src/CashCountReport.php`, `src/FuelEnergyReport.php` | daily/weekly/monthly income; franchise letterhead; PDF (`dompdf`) + Excel (`phpspreadsheet`); `src/ReportPdfBuilder.php`, `src/ReportLetterhead.php`, `src/PdfConfig.php`, `src/PreparedBy.php` | `/api/company/reports/*` (+ `?format=pdf|xlsx`) | Reports & Analytics | ⬜ |
| Receipts | `receipt/conductor/*.php` | thermal-width layout (`receipt_width_mm`); org name / footer from settings; departure, ticket, dispatch, remittance, shift-summary | `/api/{conductor,company}/…/receipt/{kind}`, `/api/conductor/{tickets,dispatches}/{id}/receipt`, `/api/conductor/receipts/shift-summary` (all return a `ReceiptDocument` DTO) | `pages/ReceiptView.jsx` (`/receipt?src=…`), print buttons on Remittances / Trip Monitor / Conductor Trip | ✅ Phase 5 — `ReceiptTest` (10) |
| Settings & branding | `admin/settings.php`, `admin/printing.php`, `admin/payment.php`, `admin/configuration.php`, `admin/pdfsettings.php`, `src/Settings.php`, `src/LogoProcessor.php`, `src/PdfConfig.php` | logo, color palette, QR payment image, receipt text, org identity, PDF paper — **all become per-company** | `/api/company/settings` | Company Settings | ⬜ |
| Mobile App dist | `admin/mobileapp.php`, `download-app.php`, `api/meta/serverConfig.php`, `api/meta/check_update.php` | APK upload/publish, version code, force-update flag, self-updating server base URL | `/api/company/mobile-app`, public `/api/meta/*` | Mobile App | ⬜ |
| Backup & Restore | `admin/backup.php`, `src/DatabaseBackup.php`, `src/CleanData.php` | full DB dump download; restore replaces all; Clean Data wipes transactional rows only, keeps structure + master data | Super Admin tooling (rethink for multi-tenant — per-company export) | ⬜ |
| Devices | `admin/deviceMonitoring.php`, `api/devices/register.php` | registered conductor devices; last-seen | `/api/company/devices` | Device Monitoring | ⬜ |

---

## 4. Business calculations to carry over **verbatim**

### 4.1 Fare (`api/tickets/issue.php::computeFare`, mirrored in `conductor/process/tickets/issueTicket.php`)

```
base            = fare_matrix.amount           (for the ticket's route_id, status Active)
discountPercent = passenger_types.discount_percent

if discountPercent > 0 AND fare_matrix.discounted_amount IS NOT NULL:
        fare = fare_matrix.discounted_amount        # manual override, used as-is
else:
        fare = round( base * (1 - discountPercent/100) )   # PHP round(): half away from zero, to integer peso
```

- **Regular** pax (0%) never touch `discounted_amount`.
- **Manual Amount** passenger types (e.g. "Articles Sales"): if Active
  `passenger_type_articles` presets exist, the ticket **must** name one and its
  `amount` is used; otherwise the conductor's typed `manual_amount` (must be > 0).
- Fare is **always** resolved server-side from DB rows; the client value is never trusted.
- Route lookup for a **Terminal** sale is scoped to the trip's origin
  (`routes.origin = trip.coverage_origin || trip.origin`); a **Pickup** sale
  trusts `route_id` alone.
- Group ticket: each passenger line resolves its own fare with the same rules;
  a single bad line fails the whole group (no partial issue). Quantity unrolls to
  one `tickets` row per passenger so every report/remittance query stays per-row.

### 4.2 Trip status machine (`api/trips/start.php`, `end.php`, `markOnTrip.php`, `cancel.php`)

- Start requires: no existing `Departure`/`OnTrip` trip for the conductor; an open
  `conductor_attendance` period; `bus_id` ∈ conductor's `conductor_buses` and
  `status='Active'`; that bus not already on a live trip; a valid Active `driver_id`;
  Active origin terminal; the coverage route Active **and** priced.
- Initial status = `OnTrip` if the origin terminal's `boarding_mode = 'Pickup'`,
  else `Departure`.
- `Departure → OnTrip` (bus leaves terminal) → `Arrived` (end). `Cancelled` from
  either live state, with `cancellation_reason` and per-ticket `refunded_at` on the
  tickets the conductor flags.
- Force-end (`tripmonitoring.forceend`) sets `force_ended_by`.

### 4.3 Remittance (`api/trips/remittance.php`, `conductor/remittance.php`)

```
collected (total_cash)  = SUM(tickets.fare)                     WHERE trip_id = ?
totals_by_method        = SUM(fare) GROUP BY payment_method     (Cash / QR / E-Wallet)
totals_by_boarding      = SUM(fare) GROUP BY boarding_type      (Terminal / Pickup), passenger tickets only
passenger vs article    = split on passenger_types.fare_mode ('Manual Amount' = article)
total_dispatch          = SUM(dispatches.amount)                WHERE trip_id = ?
refunded (Cancelled)    = COUNT(*), SUM(fare)                   WHERE refunded_at IS NOT NULL

remitted_amount   = end.php input if numeric & >= 0, else = collected   (default: full)
balance           = collected - remitted_amount                         (null until remitted)
suggested_remit   = max(0, collected - total_dispatch)
```

Review workflow (office/admin): `remittances.view` lists trips awaiting approval;
`remittances.approve` stamps `remittance_approved_at/by`; separately
`remittance_received_at/by` records physical receipt; `remittance_excess_amount` /
`remittance_short_amount` capture the difference; `remittance_flagged` +
`remittance_flag_note` mark disputes. `src/RemittanceLock.php` guards concurrent edits.

### 4.4 Cash count & expense void

- `bus_day_cash_counts.total_amount` = `1000·q1000 + 500·q500 + 200·q200 + 100·q100
  + 50·q50 + 20·q20 + 10·q10 + 5·q5 + 1·q1`.
- One **Completed** count per `(bus_id, op_date, shift)` — enforced by the unique
  `active_bus_day` = `"{bus_id}_{op_date}_{shift}"` (null when Voided/Replaced).
- Void: requires the acting manager's **void-PIN** (`users.void_pin_hash`,
  bcrypt); wrong PIN increments `void_pin_failed_count` and can set
  `void_pin_locked_until`; every attempt logged to `cash_count_void_attempts`.
  Gated by `system_settings.void_feature_enabled` / `void_pin_required` (→ per-company).
- `op_day_expenses`: each expense carries its own denomination breakdown and is
  linked to a `cash_count_id`; `expenses.void` reverses it and restores the cash;
  every event appends to `cash_count_history`.
- Shifts: `Morning` / `Evening`; `admin_bus_assignments.start_time >= 17:00` ⇒ Evening.

### 4.5 Employee ID format (`src/EmployeeId.php`)

`users`:   `E-{YYMM of created_at}-{id zero-padded to 7}`
`drivers`: `E-{YYMM}-{(1_000_000 + id) padded to 7}`

---

## 5. Auth & authorization design (BITS → Laravel/Sanctum)

| BITS mechanism | TransitFlow |
| --- | --- |
| `session_start()` + `$_SESSION['user_id'/'role'/…]` | Sanctum SPA (stateful cookie) for the React app |
| `remember_tokens` (selector:validator, 30 d, rotated on use) | Sanctum session cookie / "remember" — drop the bespoke table |
| `api_tokens` (selector:validator bearer, 365 d, conductor-only, daily auto-logout) | `personal_access_tokens` w/ ability `conductor`, `expires_at`; scheduled prune for daily auto-logout time |
| `hasPermission($key)` → `user_permissions.allowed = 1` | `Gate::before` for `super_admin`; `Gate::define` per `permission_key` reading `user_permissions`; `permission:<key>` route middleware |
| `require_role([...])` + portal redirects (`enforce_conductor_app_only`, `enforce_office_portal_only`) | Frontend route guards for UX **only**; backend authorizes on permission + company |
| `require_api_permission($key)` on every `api/*.php` | Same middleware stack on `/api` routes |
| Account lock re-checked every request | Middleware checking `users.locked_at` on every authenticated request |
| Manila timezone pinned in `config/database.php` + `SET time_zone='+08:00'` | `config/app.php` `'timezone' => 'Asia/Manila'`; ensure DB connection matches |
| **(none)** company scoping | Global scope on every company-owned model + `company_id` from the authenticated user (or route for Super Admin); **all** cross-company checks server-side |

Roles: `super_admin` (→ TransitFlow **platform** Super Admin, `company_id = null`),
`admin`, `manager`, `chairman`, `conductor`, `office` (→ **company-scoped**).

---

## 6. Open decisions (resolve during Phase 3)

1. `pdf_settings` — **RESOLVED: per-company.** `company_settings.pdf_paper_size` /
   `pdf_orientation` / `pdf_margin_mm`, applied by `ReportController` via dompdf.
2. Real-time: **RESOLVED — 3 s polling.** `GET /api/company/live` (`App\Support\LiveBoard`),
   the SPA polls it every 3 s. No Reverb; the payload is transport-agnostic so a
   WebSocket push can be added later without changing it.
3. Backup/Restore & Clean Data — **RESOLVED: per-company.** `GET /api/company/data-tools/export`
   is a per-company JSON dump; Clean Data (`POST .../clean`) wipes only that
   company's `App\Support\CompanyDataTables::TRANSACTIONAL` rows, audited in
   `data_operations`. Restore/import not built (too risky; export covers backup).
4. Whether `chairman` keeps a distinct permission set or is an alias of `manager`
   (BITS seeds it but grants it little).
5. Group tickets & `client_uuid` offline replay — keep the idempotency contract
   exactly (mobile app depends on it).
6. Keep `permission_key` strings identical to BITS to ease the seeder & muscle memory.

<?php

namespace App\Support;

/**
 * The canonical split of a company's tables for the data tools
 * (docs/MIGRATION_MAP.md §K).
 *
 * `TRANSACTIONAL` is what Clean Data wipes — day-to-day operational records.
 * `MASTER` is everything Clean Data keeps and Export also includes: fleet,
 * network, people, fares and settings. Every listed table has a
 * `company_id` column.
 *
 * `TRANSACTIONAL` is ordered child → parent so a plain per-table delete
 * never trips a foreign key.
 */
class CompanyDataTables
{
    /** @var list<string> */
    public const TRANSACTIONAL = [
        'bus_locations',
        'cash_count_void_attempts',
        'cash_count_history',
        'remittance_cash_counts',
        'dispatches',
        'tickets',
        'ticket_groups',
        'op_day_expenses',
        'bus_day_cash_counts',
        'fuel_records',
        'ev_charging_sessions',
        'conductor_attendance',
        'trips',
    ];

    /** @var list<string> */
    public const MASTER = [
        'buses',
        'drivers',
        'terminals',
        'franchises',
        'route_stops',
        'routes',
        'fare_matrix',
        'passenger_types',
        'passenger_type_articles',
        'company_settings',
        'mobile_app_settings',
        'devices',
    ];
}

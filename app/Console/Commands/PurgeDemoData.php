<?php

namespace App\Console\Commands;

use App\Models\Bus;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Franchise;
use App\Models\PassengerType;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off clean-up of what the removed DemoCompanySeeder left behind, so the
 * operator can start over with real data. Matches only the seeder's exact
 * identifiers (company codes, @….test emails, the demo franchise's case no.,
 * PERJODA-00x buses, the three demo drivers, two terminals, four passenger
 * types) — anything an operator created is never touched.
 *
 * Lists what it would remove and changes nothing unless --confirm is given.
 * Refuses while a demo account is PERJODA's only Company Admin, so running
 * it can't lock the company out.
 */
#[Signature('transitflow:purge-demo-data {--confirm : Actually delete; without it, only list what would be removed}')]
#[Description('Remove the demo companies, accounts, franchise, fleet and passenger types the old DemoCompanySeeder created')]
class PurgeDemoData extends Command
{
    private const DEMO_COMPANY = 'PERJODA';

    private const DEMO_ONLY_COMPANY = 'SOUTHLINE';

    private const DEMO_EMAILS = ['admin@perjoda.test', 'manager@perjoda.test', 'staff@perjoda.test', 'conductor@perjoda.test'];

    private const DEMO_FRANCHISE_CASE_NO = 'CASE 2019-0417';

    private const DEMO_BUSES = ['PERJODA-001', 'PERJODA-002', 'PERJODA-003'];

    private const DEMO_DRIVERS = ['JUAN DELA CRUZ', 'PEDRO SANTOS', 'MARIO REYES'];

    private const DEMO_TERMINALS = ['SM PALA-PALA', 'EPZA (ROSARIO)'];

    private const DEMO_PASSENGER_TYPES = ['Regular', 'Senior', 'PWD', 'Articles Sales'];

    public function handle(): int
    {
        $southline = Company::query()->where('code', self::DEMO_ONLY_COMPANY)->first();
        $perjoda = Company::query()->where('code', self::DEMO_COMPANY)->first();

        $users = User::query()->whereIn('email', self::DEMO_EMAILS)->get();
        $franchises = $perjoda ? Franchise::withoutGlobalScopes()->where('company_id', $perjoda->id)->where('case_no', self::DEMO_FRANCHISE_CASE_NO)->get() : collect();
        $buses = $perjoda ? Bus::withoutGlobalScopes()->where('company_id', $perjoda->id)->whereIn('bus_number', self::DEMO_BUSES)->get() : collect();
        $drivers = $perjoda ? Driver::withoutGlobalScopes()->where('company_id', $perjoda->id)->whereIn('name', self::DEMO_DRIVERS)->get() : collect();
        $terminals = $perjoda ? Terminal::withoutGlobalScopes()->where('company_id', $perjoda->id)->whereIn('name', self::DEMO_TERMINALS)->get() : collect();
        $types = $perjoda ? PassengerType::withoutGlobalScopes()->where('company_id', $perjoda->id)->whereIn('name', self::DEMO_PASSENGER_TYPES)->get() : collect();

        $this->line('Demo data found:');
        $this->line('  Southline company:  '.($southline ? "{$southline->name} (with its ".$southline->users()->count().' account(s))' : 'none'));
        $this->line('  Demo accounts:      '.($users->pluck('email')->implode(', ') ?: 'none').' — their trips, tickets and attendance go with them');
        $this->line('  Demo franchise:     '.($franchises->map(fn ($f) => "{$f->case_no} ({$f->stops()->count()} stops, ".$f->routes()->count().' routes + fares)')->implode(', ') ?: 'none'));
        $this->line('  Buses:              '.($buses->pluck('bus_number')->implode(', ') ?: 'none'));
        $this->line('  Drivers:            '.($drivers->pluck('name')->implode(', ') ?: 'none'));
        $this->line('  Terminals:          '.($terminals->pluck('name')->implode(', ') ?: 'none'));
        $this->line('  Passenger types:    '.($types->pluck('name')->implode(', ') ?: 'none'));

        if ($perjoda && ! $this->hasOwnAdmin($perjoda)) {
            $this->error("{$perjoda->name} has no Company Admin besides the demo accounts. Create your own Company Admin first, then run this again.");

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->warn('Nothing was deleted. Re-run with --confirm to remove everything listed above.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($southline, $users, $franchises, $buses, $drivers, $terminals, $types): void {
            // Same as Super Admin > Companies > Delete: the company is
            // soft-deleted, its accounts are removed for good.
            if ($southline) {
                $southline->users()->each(function (User $user): void {
                    $user->tokens()->delete();
                    $user->delete();
                });
                $southline->delete();
            }

            foreach ($users as $user) {
                $user->tokens()->delete();
                $user->delete(); // cascades their trips (→ tickets, dispatches) and attendance
            }

            $franchises->each->delete(); // cascades its route stops, routes and fares
            $types->each->delete();
            $buses->each->delete();
            $drivers->each->delete();
            $terminals->each->delete();
        });

        $this->info('Demo data removed.');

        return self::SUCCESS;
    }

    /** A Company Admin account in this company that isn't one of the demo logins. */
    private function hasOwnAdmin(Company $company): bool
    {
        return User::query()
            ->where('company_id', $company->id)
            ->whereHas('accessRole', fn ($q) => $q->where('key', 'company_admin'))
            ->whereNotIn('email', self::DEMO_EMAILS)
            ->exists();
    }
}

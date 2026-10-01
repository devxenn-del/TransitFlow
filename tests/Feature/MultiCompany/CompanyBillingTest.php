<?php

namespace Tests\Feature\MultiCompany;

use App\Enums\BillingFrequency;
use App\Enums\PricingPlan;
use App\Mail\BillingStatementIssued;
use App\Mail\BillingStatementPaid;
use App\Models\AuditLog;
use App\Models\BillingStatement;
use App\Models\Company;
use App\Models\CompanyFeeRate;
use App\Models\Fee;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsRbac;
use Tests\TestCase;

/**
 * Company-side Billing & Fees: a company sees only its own assigned fees
 * and statements — never other companies' pricing, standard-vs-special
 * internals or internal notes — and generated statements keep the amounts
 * actually charged when pricing later changes.
 */
class CompanyBillingTest extends TestCase
{
    use RefreshDatabase, SeedsRbac;

    private Company $companyA;

    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();
        // Calendar-month cycles keep these scenarios easy to follow.
        $this->companyA = Company::factory()->create(['pricing_plan' => PricingPlan::Special, 'billing_cycle_day' => 1]);
        $this->companyB = Company::factory()->create(['billing_cycle_day' => 1]);
    }

    public function test_company_admin_sees_only_their_own_fees_without_internal_details(): void
    {
        $systemFee = Fee::factory()->create(['name' => 'System Fee', 'amount' => 5000]);
        Fee::factory()->create(['name' => 'Support Fee', 'amount' => 1000]);
        Fee::factory()->assignedTo($this->companyB)->create(['name' => 'Rental Fee']);
        Fee::factory()->inactive()->create(['name' => 'Retired Fee']);
        CompanyFeeRate::factory()->create([
            'company_id' => $this->companyA->id,
            'fee_id' => $systemFee->id,
            'amount' => 3500,
            'internal_notes' => 'Secret negotiation note',
        ]);

        Sanctum::actingAs(User::factory()->companyAdmin($this->companyA)->create());

        $response = $this->getJson('/api/company/billing/fees')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Support Fee')
            ->assertJsonPath('data.0.rate_label', 'Standard Rate')
            ->assertJsonPath('data.1.name', 'System Fee')
            ->assertJsonPath('data.1.amount', '3500.00')
            ->assertJsonPath('data.1.rate_label', 'Special Company Rate')
            ->assertJsonMissingPath('data.1.standard_amount')
            ->assertJsonMissingPath('data.1.special_rate_id');

        $this->assertStringNotContainsString('Secret negotiation note', $response->getContent());
        $this->assertStringNotContainsString('Rental Fee', $response->getContent());
    }

    public function test_company_users_without_billing_permission_are_blocked(): void
    {
        Sanctum::actingAs(User::factory()->forCompany($this->companyA)->withRole('conductor')->create());

        $this->getJson('/api/company/billing/fees')->assertForbidden();
    }

    public function test_statements_keep_the_amount_charged_after_pricing_changes(): void
    {
        $this->travelTo('2026-01-10');
        $fee = Fee::factory()->create(['name' => 'System Fee', 'amount' => 5000, 'effective_date' => '2025-12-01']);
        $superAdmin = User::factory()->superAdmin()->create();
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/super-admin/companies/{$this->companyA->id}/billing-statements", ['period' => '2026-01'])
            ->assertCreated()
            ->assertJsonPath('data.total', '5000.00')
            ->assertJsonPath('data.items.0.pricing_type', 'standard');

        $this->travelTo('2026-02-03');
        $this->postJson("/api/super-admin/companies/{$this->companyA->id}/special-rates", [
            'fee_id' => $fee->id,
            'amount' => 3500,
            'starts_on' => '2026-02-01',
        ])->assertCreated();

        $this->postJson("/api/super-admin/companies/{$this->companyA->id}/billing-statements", ['period' => '2026-02'])
            ->assertCreated()
            ->assertJsonPath('data.total', '3500.00')
            ->assertJsonPath('data.items.0.amount', '3500.00')
            ->assertJsonPath('data.items.0.standard_amount', '5000.00')
            ->assertJsonPath('data.items.0.rate_label', 'Special Company Rate');

        $fee->update(['amount' => 9000]);

        $january = BillingStatement::query()->whereDate('period_start', '2026-01-01')->sole();
        $this->assertSame('5000.00', (string) $january->total);
        $this->assertSame('5000.00', (string) $january->items()->sole()->amount);
    }

    public function test_a_month_cannot_be_billed_twice(): void
    {
        Fee::factory()->create();
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $period = today()->format('Y-m');

        $this->postJson("/api/super-admin/companies/{$this->companyB->id}/billing-statements", ['period' => $period])->assertCreated();
        $this->postJson("/api/super-admin/companies/{$this->companyB->id}/billing-statements", ['period' => $period])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');
    }

    public function test_disabled_and_not_yet_due_fees_are_not_billed(): void
    {
        $this->travelTo('2026-02-10');
        Fee::factory()->create(['name' => 'Monthly', 'amount' => 1000, 'effective_date' => '2026-01-01']);
        Fee::factory()->frequency(BillingFrequency::Quarterly)->create(['amount' => 3000, 'effective_date' => '2026-01-01']);
        Fee::factory()->inactive()->create(['amount' => 7000, 'effective_date' => '2026-01-01']);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson("/api/super-admin/companies/{$this->companyB->id}/billing-statements", ['period' => '2026-02'])
            ->assertCreated()
            ->assertJsonPath('data.total', '1000.00')
            ->assertJsonCount(1, 'data.items');

        $this->travelTo('2026-04-10');
        $this->postJson("/api/super-admin/companies/{$this->companyB->id}/billing-statements", ['period' => '2026-04'])
            ->assertCreated()
            ->assertJsonPath('data.total', '4000.00');
    }

    public function test_a_statement_follows_the_company_cycle_numbering_and_due_date(): void
    {
        $this->travelTo('2026-08-24');
        $company = Company::factory()->create(['code' => 'PERJODA', 'billing_cycle_day' => 24, 'next_billing_number' => 56]);
        Fee::factory()->create(['name' => 'System Fee', 'amount' => 2000, 'effective_date' => '2026-01-01']);
        Fee::factory()->create(['name' => 'Support Fee', 'amount' => 535.50, 'effective_date' => '2026-01-24']);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson("/api/super-admin/companies/{$company->id}/billing-statements", ['period' => '2026-07'])
            ->assertCreated()
            ->assertJsonPath('data.company_code', 'PERJODA')
            ->assertJsonPath('data.billing_number', 56)
            ->assertJsonPath('data.reference', 'PERJODA-56')
            ->assertJsonPath('data.period_start', '2026-07-24')
            ->assertJsonPath('data.period_end', '2026-08-23')
            ->assertJsonPath('data.total', '2535.50')
            ->assertJsonPath('data.due_on', '2026-09-13');

        $this->assertSame(57, $company->fresh()->next_billing_number);
    }

    public function test_generating_a_statement_emails_it_to_the_company(): void
    {
        Mail::fake();
        $this->travelTo('2026-08-24');
        $company = Company::factory()->create(['code' => 'PERJODA', 'email' => 'billing@perjoda.test', 'billing_cycle_day' => 24, 'next_billing_number' => 56]);
        $fee = Fee::factory()->create(['name' => 'System Fee', 'amount' => 2535.50, 'effective_date' => '2026-01-01']);
        $company->update(['pricing_plan' => PricingPlan::Special]);
        CompanyFeeRate::factory()->create(['company_id' => $company->id, 'fee_id' => $fee->id, 'amount' => 2535.50, 'internal_notes' => 'Secret negotiation note']);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson("/api/super-admin/companies/{$company->id}/billing-statements", ['period' => '2026-07'])
            ->assertCreated()
            ->assertJsonPath('email.sent', true)
            ->assertJsonPath('email.to', 'billing@perjoda.test');

        Mail::assertQueued(BillingStatementIssued::class, function (BillingStatementIssued $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('billing@perjoda.test')
                && str_contains($mail->envelope()->subject, 'Billing No. 56')
                && str_contains($html, 'PERJODA')
                && str_contains($html, '07/24/2026-08/23/2026')
                && str_contains($html, 'PHP2,535.50')
                && str_contains($html, '09/13/2026')
                && ! str_contains($html, 'Secret negotiation note');
        });

        $this->assertTrue(AuditLog::query()->where('action', 'billing.statement.emailed')->where('company_id', $company->id)->exists());
    }

    public function test_a_company_without_an_email_is_still_billed_and_can_be_emailed_later(): void
    {
        Mail::fake();
        $this->companyB->update(['email' => null]);
        Fee::factory()->create();
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $statementId = $this->postJson("/api/super-admin/companies/{$this->companyB->id}/billing-statements", ['period' => today()->format('Y-m')])
            ->assertCreated()
            ->assertJsonPath('email.sent', false)
            ->json('data.id');
        Mail::assertNothingQueued();

        $this->companyB->update(['email' => 'accounts@southline.test']);
        $this->postJson("/api/super-admin/companies/{$this->companyB->id}/billing-statements/{$statementId}/email")
            ->assertOk()
            ->assertJsonPath('email.sent', true);
        Mail::assertQueued(BillingStatementIssued::class, fn (BillingStatementIssued $mail) => $mail->hasTo('accounts@southline.test'));

        Sanctum::actingAs(User::factory()->companyAdmin($this->companyB)->create());
        $this->postJson("/api/super-admin/companies/{$this->companyB->id}/billing-statements/{$statementId}/email")->assertForbidden();
    }

    public function test_a_special_rate_starting_mid_period_is_billed_on_the_fees_charge_date(): void
    {
        $this->travelTo('2026-10-13');
        $company = Company::factory()->create(['pricing_plan' => PricingPlan::Special, 'billing_cycle_day' => 13]);
        $systemFee = Fee::factory()->create(['name' => 'System Fee', 'amount' => 2000, 'effective_date' => '2026-10-01']);
        Fee::factory()->create(['name' => 'Maintenance Fee', 'amount' => 500, 'effective_date' => '2026-10-01']);
        CompanyFeeRate::factory()->create(['company_id' => $company->id, 'fee_id' => $systemFee->id, 'amount' => 1500, 'starts_on' => '2026-10-01']);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        // Period 09/13 – 10/12: both fees are charged on 10/01, when the special rate is in effect.
        $this->postJson("/api/super-admin/companies/{$company->id}/billing-statements", ['period' => '2026-09'])
            ->assertCreated()
            ->assertJsonPath('data.period_start', '2026-09-13')
            ->assertJsonPath('data.total', '2000.00')
            ->assertJsonPath('data.items.1.fee_name', 'System Fee')
            ->assertJsonPath('data.items.1.amount', '1500.00')
            ->assertJsonPath('data.items.1.rate_label', 'Special Company Rate');
    }

    public function test_a_special_rate_that_ended_before_the_charge_date_is_not_applied(): void
    {
        $this->travelTo('2026-10-13');
        $company = Company::factory()->create(['pricing_plan' => PricingPlan::Special, 'billing_cycle_day' => 13]);
        $fee = Fee::factory()->create(['amount' => 2000, 'effective_date' => '2026-01-20']);
        CompanyFeeRate::factory()->create(['company_id' => $company->id, 'fee_id' => $fee->id, 'amount' => 1500, 'starts_on' => '2026-01-01', 'ends_on' => '2026-09-15']);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        // Period 09/13 – 10/12: the fee is charged on 09/20, after the special rate ended on 09/15.
        $this->postJson("/api/super-admin/companies/{$company->id}/billing-statements", ['period' => '2026-09'])
            ->assertCreated()
            ->assertJsonPath('data.total', '2000.00')
            ->assertJsonPath('data.items.0.pricing_type', 'standard');
    }

    public function test_an_unpaid_statement_can_be_recalculated_with_a_later_special_rate(): void
    {
        $this->travelTo('2026-10-13');
        $company = Company::factory()->create(['billing_cycle_day' => 13, 'next_billing_number' => 2]);
        $systemFee = Fee::factory()->create(['name' => 'System Fee', 'amount' => 2000, 'effective_date' => '2026-10-01']);
        Fee::factory()->create(['name' => 'Maintenance Fee', 'amount' => 500, 'effective_date' => '2026-10-01']);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $id = $this->postJson("/api/super-admin/companies/{$company->id}/billing-statements", ['period' => '2026-09'])
            ->assertCreated()
            ->assertJsonPath('data.total', '2500.00')
            ->json('data.id');

        // The special pricing is set up after the bill was made.
        $company->update(['pricing_plan' => PricingPlan::Special]);
        CompanyFeeRate::factory()->create(['company_id' => $company->id, 'fee_id' => $systemFee->id, 'amount' => 1500, 'starts_on' => '2026-10-01']);

        $url = "/api/super-admin/companies/{$company->id}/billing-statements/{$id}/recalculate";
        $this->postJson($url)
            ->assertOk()
            ->assertJsonPath('data.billing_number', 2)
            ->assertJsonPath('data.period_start', '2026-09-13')
            ->assertJsonPath('data.due_on', '2026-11-02')
            ->assertJsonPath('data.total', '2000.00')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.1.amount', '1500.00')
            ->assertJsonPath('data.items.1.rate_label', 'Special Company Rate');

        $log = AuditLog::query()->where('action', 'billing.statement.recalculated')->sole();
        $this->assertSame(['from' => '2500.00', 'to' => '2000.00'], $log->context['changes']['total']);

        // Paid statements are history.
        $this->patchJson("/api/super-admin/companies/{$company->id}/billing-statements/{$id}/status", ['status' => 'paid'])->assertOk();
        $this->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('statement');

        Sanctum::actingAs(User::factory()->companyAdmin($company)->create());
        $this->postJson($url)->assertForbidden();
    }

    public function test_a_short_payment_is_added_to_the_next_bill(): void
    {
        Mail::fake();
        $this->travelTo('2026-10-05');
        Fee::factory()->create(['name' => 'System Fee', 'amount' => 2000, 'effective_date' => '2026-01-01']);
        $this->companyB->update(['email' => 'accounts@southline.test']);
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $base = "/api/super-admin/companies/{$this->companyB->id}/billing-statements";

        $first = $this->postJson($base, ['period' => '2026-09'])->assertCreated()->json('data');

        $this->patchJson("{$base}/{$first['id']}/status", ['status' => 'paid', 'amount_received' => 1500])
            ->assertOk()
            ->assertJsonPath('data.amount_received', '1500.00')
            ->assertJsonPath('data.carry_over_amount', '500.00');

        Mail::assertQueued(BillingStatementPaid::class, fn (BillingStatementPaid $mail) => str_contains($mail->render(), 'PHP500.00</strong> short'));

        $this->postJson($base, ['period' => '2026-10'])
            ->assertCreated()
            ->assertJsonPath('data.total', '2500.00')
            ->assertJsonPath('data.items.1.kind', 'carry_over')
            ->assertJsonPath('data.items.1.fee_name', "Balance from Billing No. {$first['billing_number']}")
            ->assertJsonPath('data.items.1.amount', '500.00');

        $this->getJson("/api/company/billing/statements/{$first['id']}", ['X-Company-Id' => $this->companyB->id])
            ->assertOk()
            ->assertJsonPath('data.carried_to_billing_number', $first['billing_number'] + 1);

        // Its shortage is on the next bill now, so the paid bill is locked.
        $this->patchJson("{$base}/{$first['id']}/status", ['status' => 'unpaid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_an_excess_payment_is_deducted_from_the_next_bill_and_only_once(): void
    {
        $this->travelTo('2026-11-05');
        Fee::factory()->create(['amount' => 2000, 'effective_date' => '2026-01-01']);
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $base = "/api/super-admin/companies/{$this->companyB->id}/billing-statements";

        $first = $this->postJson($base, ['period' => '2026-09'])->json('data.id');
        $this->patchJson("{$base}/{$first}/status", ['status' => 'paid', 'amount_received' => 2600])
            ->assertJsonPath('data.carry_over_amount', '-600.00');

        $second = $this->postJson($base, ['period' => '2026-10'])
            ->assertCreated()
            ->assertJsonPath('data.total', '1400.00')
            ->assertJsonPath('data.items.1.fee_name', 'Excess payment from Billing No. 1')
            ->assertJsonPath('data.items.1.amount', '-600.00')
            ->json('data.id');

        // Recalculating keeps the carried credit.
        $this->postJson("{$base}/{$second}/recalculate")->assertOk()->assertJsonPath('data.total', '1400.00');

        // Voiding the bill that carried the credit releases it to the next bill — never twice.
        $this->patchJson("{$base}/{$second}/status", ['status' => 'void'])->assertOk();
        $this->postJson($base, ['period' => '2026-11'])
            ->assertCreated()
            ->assertJsonPath('data.total', '1400.00');
        $this->assertSame(1, BillingStatement::query()->where('carried_to_statement_id', '!=', null)->count());

        // A void bill that carried balances can't be reopened.
        $this->patchJson("{$base}/{$second}/status", ['status' => 'unpaid'])->assertUnprocessable();
    }

    public function test_amount_received_is_only_accepted_when_marking_paid(): void
    {
        $statement = BillingStatement::factory()->create(['company_id' => $this->companyB->id, 'total' => 1000]);
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $url = "/api/super-admin/companies/{$this->companyB->id}/billing-statements/{$statement->id}/status";

        $this->patchJson($url, ['status' => 'void', 'amount_received' => 500])->assertJsonValidationErrors('amount_received');
        $this->patchJson($url, ['status' => 'paid', 'amount_received' => -1])->assertJsonValidationErrors('amount_received');

        // Without an amount, it's paid exactly.
        $this->patchJson($url, ['status' => 'paid'])
            ->assertOk()
            ->assertJsonPath('data.amount_received', '1000.00')
            ->assertJsonPath('data.carry_over_amount', '0.00');
    }

    public function test_marking_a_statement_paid_emails_a_confirmation_once(): void
    {
        Mail::fake();
        $this->companyB->update(['code' => 'SOUTHLINE', 'email' => 'accounts@southline.test']);
        $statement = BillingStatement::factory()->create(['company_id' => $this->companyB->id, 'billing_number' => 7, 'total' => 2535.50]);
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $url = "/api/super-admin/companies/{$this->companyB->id}/billing-statements/{$statement->id}/status";

        $this->patchJson($url, ['status' => 'paid'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('email.sent', true)
            ->assertJsonPath('email.to', 'accounts@southline.test');

        Mail::assertQueued(BillingStatementPaid::class, function (BillingStatementPaid $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('accounts@southline.test')
                && str_contains($mail->envelope()->subject, 'Payment Received — Billing No. 7')
                && str_contains($html, 'Amount Paid')
                && str_contains($html, 'PHP2,535.50')
                && str_contains($html, 'PAID');
        });

        // Marking it paid again is not a change — no second confirmation.
        $this->patchJson($url, ['status' => 'paid'])->assertOk()->assertJsonPath('email', null);
        Mail::assertQueuedCount(1);

        // Other status changes never email.
        $this->patchJson($url, ['status' => 'void'])->assertOk()->assertJsonPath('email', null);
        Mail::assertQueuedCount(1);

        // And a void statement can't be (re)sent.
        $this->postJson("/api/super-admin/companies/{$this->companyB->id}/billing-statements/{$statement->id}/email")
            ->assertOk()
            ->assertJsonPath('email.sent', false);
        Mail::assertQueuedCount(1);
    }

    public function test_the_payment_request_says_unpaid_and_when_it_is_due(): void
    {
        $statement = BillingStatement::factory()->create(['company_id' => $this->companyB->id]);

        $html = (new BillingStatementIssued($statement))->render();

        $this->assertStringContainsString('Amount to Pay', $html);
        $this->assertStringContainsString('UNPAID', $html);
        $this->assertStringContainsString($statement->due_on->format('m/d/Y'), $html);
    }

    public function test_the_daily_run_bills_each_company_the_day_after_its_period_ends(): void
    {
        $this->travelTo('2026-08-24');
        $cycle24 = Company::factory()->create(['billing_cycle_day' => 24]);
        Fee::factory()->create(['amount' => 1000, 'effective_date' => '2026-01-01']);

        $this->artisan('billing:generate')->assertSuccessful();

        $this->assertSame('2026-07-24', $cycle24->billingStatements()->sole()->period_start->toDateString());
        // Companies on the 1st-of-month cycle are not due today.
        $this->assertSame(0, $this->companyB->billingStatements()->count());

        $this->artisan('billing:generate')->assertSuccessful();
        $this->assertSame(1, $cycle24->billingStatements()->count());
    }

    public function test_super_admin_sets_the_billing_cycle_and_next_number(): void
    {
        BillingStatement::factory()->create(['company_id' => $this->companyB->id, 'billing_number' => 40]);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->putJson("/api/super-admin/companies/{$this->companyB->id}/pricing", ['next_billing_number' => 40])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('next_billing_number');
        $this->putJson("/api/super-admin/companies/{$this->companyB->id}/pricing", ['billing_cycle_day' => 29])
            ->assertJsonValidationErrors('billing_cycle_day');

        $this->putJson("/api/super-admin/companies/{$this->companyB->id}/pricing", ['billing_cycle_day' => 24, 'next_billing_number' => 56])
            ->assertOk()
            ->assertJsonPath('billing_cycle_day', 24)
            ->assertJsonPath('next_billing_number', 56)
            ->assertJsonPath('pricing_plan', 'standard');

        Sanctum::actingAs(User::factory()->companyAdmin($this->companyB)->create());
        $this->putJson("/api/super-admin/companies/{$this->companyB->id}/pricing", ['next_billing_number' => 100])->assertForbidden();
    }

    public function test_next_billing_date_follows_each_fees_frequency(): void
    {
        $this->travelTo('2026-03-15');
        Fee::factory()->create(['name' => 'A Monthly', 'effective_date' => '2025-12-20']);
        Fee::factory()->frequency(BillingFrequency::Quarterly)->create(['name' => 'B Quarterly', 'effective_date' => '2026-01-10']);
        Fee::factory()->frequency(BillingFrequency::OneTime)->create(['name' => 'C Setup', 'effective_date' => '2026-01-01']);
        Fee::factory()->frequency(BillingFrequency::Yearly)->create(['name' => 'D Yearly', 'effective_date' => '2026-06-01']);
        Sanctum::actingAs(User::factory()->companyAdmin($this->companyB)->create());

        $this->getJson('/api/company/billing/fees')
            ->assertOk()
            ->assertJsonPath('data.0.next_billing_date', '2026-03-20')
            ->assertJsonPath('data.1.next_billing_date', '2026-04-10')
            ->assertJsonPath('data.2.next_billing_date', null)
            ->assertJsonPath('data.3.next_billing_date', '2026-06-01')
            ->assertJsonPath('data.3.status', 'scheduled');
    }

    public function test_a_fee_billed_today_next_bills_one_interval_later(): void
    {
        $this->travelTo('2026-10-01');
        Fee::factory()->create(['name' => 'A Starts Today', 'effective_date' => '2026-10-01']);
        Fee::factory()->create(['name' => 'B Billing Day Today', 'effective_date' => '2026-07-01']);
        Fee::factory()->frequency(BillingFrequency::OneTime)->create(['name' => 'C One-time Today', 'effective_date' => '2026-10-01']);
        Fee::factory()->frequency(BillingFrequency::Quarterly)->create(['name' => 'D Quarterly Today', 'effective_date' => '2026-10-01']);
        Sanctum::actingAs(User::factory()->companyAdmin($this->companyB)->create());

        $this->getJson('/api/company/billing/fees')
            ->assertOk()
            ->assertJsonPath('data.0.next_billing_date', '2026-11-01')
            ->assertJsonPath('data.1.next_billing_date', '2026-11-01')
            ->assertJsonPath('data.2.next_billing_date', null)
            ->assertJsonPath('data.3.next_billing_date', '2027-01-01');
    }

    public function test_a_company_cannot_open_another_companys_statement(): void
    {
        $other = BillingStatement::factory()->create(['company_id' => $this->companyB->id]);
        $own = BillingStatement::factory()->create(['company_id' => $this->companyA->id]);
        Sanctum::actingAs(User::factory()->companyAdmin($this->companyA)->create());

        $this->getJson('/api/company/billing/statements')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/company/billing/statements/{$own->id}")->assertOk();
        $this->getJson("/api/company/billing/statements/{$other->id}")->assertNotFound();
    }

    public function test_pricing_audit_entries_are_hidden_from_the_company_audit_log(): void
    {
        $admin = User::factory()->companyAdmin($this->companyA)->create();
        Audit::record('billing.special_rate.created', $this->companyA, ['previous' => 'x'], company: $this->companyA, actor: $admin);
        Audit::record('company.settings.updated', $this->companyA, company: $this->companyA, actor: $admin);
        Sanctum::actingAs($admin);

        $this->getJson('/api/company/audit-log')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'company.settings.updated');
    }

    public function test_pricing_plan_is_only_exposed_to_the_super_admin(): void
    {
        Sanctum::actingAs(User::factory()->companyAdmin($this->companyA)->create());
        $this->getJson("/api/companies/{$this->companyA->id}")->assertOk()->assertJsonMissingPath('data.pricing_plan');

        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->getJson("/api/companies/{$this->companyA->id}")->assertOk()->assertJsonPath('data.pricing_plan', 'special');
    }
}

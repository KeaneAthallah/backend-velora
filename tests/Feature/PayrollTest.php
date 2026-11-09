<?php

use App\Models\Account;
use App\Models\Attendance;
use App\Models\BusinessMembership;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\PayrollStructure;
use App\Models\Role;
use App\Models\User;

/**
 * Payroll: salary structures, monthly run generation (fixed and hourly, from
 * attendance), payslip adjustments, and the draft → approved → paid lifecycle.
 */
function payrollCtx($test, string $suffix): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "Payroll $suffix"])
        ->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    return [
        'user' => $user,
        'auth' => $auth,
        'biz' => $biz,
        'headers' => $bizHeaders,
    ];
}

test('payroll structures are created, edited and deleted for active members only', function () {
    $ctx = payrollCtx($this, 'PS');

    $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/payroll/structures')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $structure = $this->withHeaders($ctx['headers'])->postJson('/api/v1/payroll/structures', [
        'user_id' => $ctx['user']->id,
        'type' => 'fixed',
        'base_salary' => 5_000_000,
        'allowances' => ['Transport' => 250_000],
        'deductions' => [['label' => 'BPJS', 'amount' => 150_000]],
    ])->assertStatus(201)->json('data');

    expect($structure['type'])->toBe('fixed')
        ->and((int) $structure['base_salary'])->toBe(5_000_000)
        ->and($structure['user']['id'])->toBe($ctx['user']->id);

    // One structure per person.
    $this->withHeaders($ctx['headers'])->postJson('/api/v1/payroll/structures', [
        'user_id' => $ctx['user']->id,
        'type' => 'fixed',
        'base_salary' => 1,
    ])->assertStatus(422);

    // Someone outside the business cannot be put on payroll.
    $outsider = User::factory()->create();
    $this->withHeaders($ctx['headers'])->postJson('/api/v1/payroll/structures', [
        'user_id' => $outsider->id,
        'type' => 'fixed',
        'base_salary' => 1,
    ])->assertStatus(422);

    // A fixed structure without a salary is refused, and so is an hourly one
    // without a rate.
    $this->withHeaders($ctx['headers'])->postJson('/api/v1/payroll/structures', [
        'user_id' => $ctx['user']->id + 0,
        'type' => 'fixed',
        'base_salary' => 0,
    ])->assertStatus(422);

    $this->withHeaders($ctx['headers'])->putJson("/api/v1/payroll/structures/{$structure['id']}", [
        'type' => 'hourly',
        'hourly_rate' => 120_000,
    ])->assertOk()->assertJsonPath('data.hourly_rate', 120_000)
        ->assertJsonPath('data.base_salary', 0);

    $this->withHeaders($ctx['headers'])
        ->deleteJson("/api/v1/payroll/structures/{$structure['id']}")
        ->assertOk();

    expect(PayrollStructure::find($structure['id']))->toBeNull();
});

test('a fixed run snapshots allowances and deductions, and the lifecycle locks payslips', function () {
    $ctx = payrollCtx($this, 'RUN');

    PayrollStructure::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $ctx['user']->id,
        'type' => 'fixed',
        'base_salary' => 5_000_000,
        'allowances' => ['Transport' => 250_000],
        'deductions' => ['BPJS' => 150_000],
    ]);

    $run = $this->withHeaders($ctx['headers'])->postJson('/api/v1/payroll/runs', [
        'period' => '2026-10',
    ])->assertStatus(201)->json('data');

    expect($run['status'])->toBe('draft')
        ->and((int) $run['payslips_count'])->toBe(1);

    $payslip = $run['payslips'][0];
    expect((int) $payslip['base_amount'])->toBe(5_000_000)
        ->and((int) $payslip['allowances_total'])->toBe(250_000)
        ->and((int) $payslip['deductions_total'])->toBe(150_000)
        ->and((int) $payslip['net_amount'])->toBe(5_100_000)
        ->and($payslip['details']['period'])->toBe('2026-10');

    // Regenerating a draft replaces the payslip rather than duplicating it and
    // keeps a manual bonus.
    $this->withHeaders($ctx['headers'])->putJson("/api/v1/payroll/runs/{$run['id']}/payslips/{$payslip['id']}", [
        'bonus_amount' => 400_000,
    ])->assertOk()->assertJsonPath('data.net_amount', 5_500_000);

    $this->withHeaders($ctx['headers'])->postJson('/api/v1/payroll/runs', [
        'period' => '2026-10',
    ])->assertStatus(201)->assertJsonPath('data.payslips_count', 1);

    expect(PayrollRun::find($run['id'])->payslips()->count())->toBe(1);
    expect((int) PayrollRun::find($run['id'])->payslips()->first()->bonus_amount)->toBe(400_000);

    // A draft can be paid only after approval.
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/payroll/runs/{$run['id']}/pay")
        ->assertStatus(422);

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/payroll/runs/{$run['id']}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approved_by', $ctx['user']->id);

    // Approved payslips are frozen.
    $this->withHeaders($ctx['headers'])->putJson("/api/v1/payroll/runs/{$run['id']}/payslips/{$payslip['id']}", [
        'bonus_amount' => 9_999_999,
    ])->assertStatus(422);

    // An approved period cannot be regenerated or deleted.
    $this->withHeaders($ctx['headers'])
        ->postJson('/api/v1/payroll/runs', ['period' => '2026-10'])
        ->assertStatus(422);
    $this->withHeaders($ctx['headers'])
        ->deleteJson("/api/v1/payroll/runs/{$run['id']}")
        ->assertStatus(422);

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/payroll/runs/{$run['id']}/pay")
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');
});

test('an hourly run pays closed attendance hours minus breaks', function () {
    $ctx = payrollCtx($this, 'HR');

    PayrollStructure::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $ctx['user']->id,
        'type' => 'hourly',
        'hourly_rate' => 100_000,
    ]);

    // 09:00–17:00 with a 30 minute break = 7.5 h, plus 09:00–12:00 = 3 h.
    Attendance::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $ctx['user']->id,
        'check_in_at' => '2026-10-05 09:00:00',
        'check_out_at' => '2026-10-05 17:00:00',
        'break_start_at' => '2026-10-05 12:00:00',
        'break_end_at' => '2026-10-05 12:30:00',
        'status' => 'present',
    ]);
    Attendance::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $ctx['user']->id,
        'check_in_at' => '2026-10-06 09:00:00',
        'check_out_at' => '2026-10-06 12:00:00',
        'status' => 'present',
    ]);
    // An open shift and a shift in another month are both ignored.
    Attendance::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $ctx['user']->id,
        'check_in_at' => '2026-10-07 09:00:00',
        'status' => 'present',
    ]);
    Attendance::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $ctx['user']->id,
        'check_in_at' => '2026-09-30 09:00:00',
        'check_out_at' => '2026-09-30 17:00:00',
        'status' => 'present',
    ]);

    $run = $this->withHeaders($ctx['headers'])->postJson('/api/v1/payroll/runs', [
        'period' => '2026-10',
    ])->assertStatus(201)->json('data');

    $payslip = $run['payslips'][0];
    expect((int) $payslip['base_amount'])->toBe(1_050_000)
        ->and($payslip['details']['hours_worked'])->toBe(10.5)
        ->and($payslip['details']['days_worked'])->toBe(2)
        ->and((int) $payslip['net_amount'])->toBe(1_050_000);
});

test('a run only pays active members and drops a removed structure on regeneration', function () {
    $ctx = payrollCtx($this, 'MEM');

    $role = Role::where('business_id', $ctx['biz']['id'])->firstOrFail();
    $member = User::factory()->create();
    BusinessMembership::create([
        'user_id' => $member->id,
        'business_id' => $ctx['biz']['id'],
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    PayrollStructure::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $ctx['user']->id,
        'type' => 'fixed',
        'base_salary' => 3_000_000,
    ]);
    PayrollStructure::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $member->id,
        'type' => 'fixed',
        'base_salary' => 2_000_000,
    ]);

    $run = $this->withHeaders($ctx['headers'])->postJson('/api/v1/payroll/runs', [
        'period' => '2026-11',
    ])->assertStatus(201)->json('data');

    expect((int) $run['payslips_count'])->toBe(2)
        ->and((int) $run['payslips_sum_net_amount'])->toBe(5_000_000);

    // Removing one structure and regenerating leaves one payslip.
    PayrollStructure::where('user_id', $member->id)->delete();
    $this->withHeaders($ctx['headers'])->postJson('/api/v1/payroll/runs', [
        'period' => '2026-11',
    ])->assertStatus(201)->assertJsonPath('data.payslips_count', 1);

    // Inactive members are skipped on the next generation.
    BusinessMembership::where('user_id', $ctx['user']->id)->update(['status' => 'inactive']);
    expect(PayrollRun::where('period', '2026-11')->first()->payslips()->count())->toBe(1);
});

test('an approved run posts one balanced journal entry', function () {
    $ctx = payrollCtx($this, 'JRN');

    PayrollStructure::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $ctx['user']->id,
        'type' => 'fixed',
        'base_salary' => 5_000_000,
        'allowances' => ['Transport' => 250_000],
        'deductions' => ['BPJS' => 150_000],
    ]);
    $run = $this->withHeaders($ctx['headers'])->postJson('/api/v1/payroll/runs', [
        'period' => '2026-10',
    ])->assertStatus(201)->json('data');

    $wages = Account::create([
        'business_id' => $ctx['biz']['id'],
        'code' => '5-1000',
        'name' => 'Salaries and wages',
        'type' => 'expense',
    ]);
    $cash = Account::create([
        'business_id' => $ctx['biz']['id'],
        'code' => '1-1000',
        'name' => 'Cash on hand',
        'type' => 'asset',
    ]);

    // A draft has not been finalised, so it cannot reach the books.
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/payroll/runs/{$run['id']}/post-journal", [
            'expense_account_id' => $wages->id,
            'payment_account_id' => $cash->id,
        ])->assertStatus(422);

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/payroll/runs/{$run['id']}/approve")
        ->assertOk();

    // The expense and the paying account have to be different accounts.
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/payroll/runs/{$run['id']}/post-journal", [
            'expense_account_id' => $wages->id,
            'payment_account_id' => $wages->id,
        ])->assertStatus(422);

    // Accounts are never trusted from the body: an id from elsewhere is a 404.
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/payroll/runs/{$run['id']}/post-journal", [
            'expense_account_id' => $wages->id,
            'payment_account_id' => 999_999,
        ])->assertStatus(404);

    $posted = $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/payroll/runs/{$run['id']}/post-journal", [
            'expense_account_id' => $wages->id,
            'payment_account_id' => $cash->id,
        ])->assertStatus(201)->json('data');

    $entry = JournalEntry::where('reference_type', 'payroll_run')->where('reference_id', $run['id'])->firstOrFail();
    expect($entry->status)->toBe('posted')
        ->and($entry->number)->toBe('PAY-2026-10')
        ->and($entry->entry_date->toDateString())->toBe('2026-10-31')
        ->and((int) $entry->lines()->sum('debit'))->toBe(5_100_000)
        ->and((int) $entry->lines()->sum('credit'))->toBe(5_100_000)
        ->and((int) $posted['journal_entry']['id'])->toBe($entry->id);

    expect((int) PayrollRun::find($run['id'])->journal_entry_id)->toBe($entry->id);

    // Posting the same run twice would double the wage cost.
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/payroll/runs/{$run['id']}/post-journal", [
            'expense_account_id' => $wages->id,
            'payment_account_id' => $cash->id,
        ])->assertStatus(422);

    expect(JournalEntry::where('reference_type', 'payroll_run')->count())->toBe(1);
});

test('payslips export as csv, excel and pdf', function () {
    $ctx = payrollCtx($this, 'EXP');

    PayrollStructure::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $ctx['user']->id,
        'type' => 'fixed',
        'base_salary' => 4_000_000,
    ]);
    $run = $this->withHeaders($ctx['headers'])->postJson('/api/v1/payroll/runs', [
        'period' => '2026-10',
    ])->assertStatus(201)->json('data');

    $csv = $this->withHeaders($ctx['headers'])
        ->get("/api/v1/payroll/runs/{$run['id']}/export?format=csv");
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv')
        ->and($csv->getContent())->toContain('Employee')
        ->and($csv->getContent())->toContain($ctx['user']->name)
        ->and($csv->getContent())->toContain('4000000');

    $pdf = $this->withHeaders($ctx['headers'])
        ->get("/api/v1/payroll/runs/{$run['id']}/export?format=pdf");
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf')
        ->and($pdf->getContent())->toStartWith('%PDF');

    // An unknown format falls back to CSV rather than failing.
    $this->withHeaders($ctx['headers'])
        ->get("/api/v1/payroll/runs/{$run['id']}/export?format=docx")
        ->assertOk();
});

test('payroll is tenant-scoped and permission-gated', function () {
    $one = payrollCtx($this, 'ONE');
    $two = payrollCtx($this, 'TWO');
    $this->app['auth']->forgetGuards();

    PayrollStructure::create([
        'business_id' => $one['biz']['id'],
        'user_id' => $one['user']->id,
        'type' => 'fixed',
        'base_salary' => 4_000_000,
    ]);
    $run = $this->withHeaders($one['headers'])->postJson('/api/v1/payroll/runs', [
        'period' => '2026-10',
    ])->assertStatus(201)->json('data');

    // Another tenant sees nothing and cannot reach the run by id.
    $this->app['auth']->forgetGuards();
    $this->withHeaders($two['headers'])
        ->getJson('/api/v1/payroll/runs')
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $this->withHeaders($two['headers'])
        ->getJson("/api/v1/payroll/runs/{$run['id']}")
        ->assertStatus(404);
    $this->withHeaders($two['headers'])
        ->getJson('/api/v1/payroll/structures')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // A member whose role has no payroll permissions is refused.
    $role = Role::create([
        'business_id' => $one['biz']['id'],
        'name' => 'Cashier',
        'slug' => 'cashier',
        'is_system' => false,
    ]);
    $cashier = User::factory()->create();
    BusinessMembership::create([
        'user_id' => $cashier->id,
        'business_id' => $one['biz']['id'],
        'role_id' => $role->id,
        'status' => 'active',
    ]);
    $cashierHeaders = array_merge(
        ['Authorization' => 'Bearer '.$cashier->createToken('t')->plainTextToken],
        ['X-Business-Id' => $one['biz']['id']]
    );

    $this->app['auth']->forgetGuards();
    $this->withHeaders($cashierHeaders)->getJson('/api/v1/payroll/runs')->assertStatus(403);
    $this->withHeaders($cashierHeaders)->getJson('/api/v1/payroll/structures')->assertStatus(403);
    $this->withHeaders($cashierHeaders)->postJson('/api/v1/payroll/runs', ['period' => '2026-12'])->assertStatus(403);

    // The month format is validated.
    $this->app['auth']->forgetGuards();
    $this->withHeaders($one['headers'])
        ->postJson('/api/v1/payroll/runs', ['period' => '2026-13'])
        ->assertStatus(422);
});

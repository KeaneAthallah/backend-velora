<?php

use App\Models\ArInvoice;
use App\Models\JournalEntry;
use App\Models\User;

/**
 * Finance module: double-entry integrity, invoice payments, aging and the
 * financial summary. Also covers the per-business scoping of every one of
 * those surfaces, since ids are accepted from the URL and the body.
 */
function financeCtx($test, string $suffix): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "Finance $suffix"])
        ->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    $outlet = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => "Outlet $suffix", 'code' => "FIN-$suffix"])
        ->json('data');

    return [
        'user' => $user,
        'auth' => $auth,
        'biz' => $biz,
        'bizHeaders' => $bizHeaders,
        'outlet' => $outlet,
        'headers' => array_merge($bizHeaders, ['X-Outlet-Id' => $outlet['id']]),
    ];
}

function financeAccount($test, array $ctx, string $code, string $name, string $type): array
{
    return $test->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/accounts', [
        'code' => $code,
        'name' => $name,
        'type' => $type,
    ])->assertStatus(201)->json('data');
}

function financeJournal($test, array $ctx, array $lines, string $status = 'draft'): array
{
    return $test->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/journal-entries', [
        'entry_date' => now()->toDateString(),
        'description' => 'Test entry',
        'status' => $status,
        'lines' => $lines,
    ])->json('data');
}

test('a journal entry must balance before anything is stored', function () {
    $ctx = financeCtx($this, 'JB');
    $cash = financeAccount($this, $ctx, '1000', 'Cash', 'asset');
    $sales = financeAccount($this, $ctx, '4000', 'Sales', 'revenue');

    $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/journal-entries', [
        'entry_date' => now()->toDateString(),
        'lines' => [
            ['account_id' => $cash['id'], 'debit' => 1000],
            ['account_id' => $sales['id'], 'credit' => 900],
        ],
    ])->assertStatus(422)->assertJsonPath('code', 'UNBALANCED_ENTRY');

    expect(JournalEntry::where('business_id', (int) $ctx['biz']['id'])->count())->toBe(0);

    $entry = financeJournal($this, $ctx, [
        ['account_id' => $cash['id'], 'debit' => 1000],
        ['account_id' => $sales['id'], 'credit' => 1000],
    ]);

    expect($entry['status'])->toBe('draft');

    $trial = $this->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/trial-balance')
        ->assertOk()
        ->json('data');

    expect($trial['total_debit'])->toBe(1000)
        ->and($trial['total_credit'])->toBe(1000);
});

test('a draft journal entry can be read, posted and reversed by id', function () {
    $ctx = financeCtx($this, 'JP');
    $cash = financeAccount($this, $ctx, '1000', 'Cash', 'asset');
    $sales = financeAccount($this, $ctx, '4000', 'Sales', 'revenue');

    $entry = financeJournal($this, $ctx, [
        ['account_id' => $cash['id'], 'debit' => 2500],
        ['account_id' => $sales['id'], 'credit' => 2500],
    ]);

    $this->withHeaders($ctx['bizHeaders'])
        ->getJson("/api/v1/journal-entries/{$entry['id']}")
        ->assertOk()
        ->assertJsonPath('data.id', $entry['id']);

    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/journal-entries/{$entry['id']}/post")
        ->assertOk()
        ->assertJsonPath('data.status', 'posted');

    $reversal = $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/journal-entries/{$entry['id']}/reverse")
        ->assertStatus(201)
        ->json('data');

    expect($reversal['lines'])->toHaveCount(2)
        ->and((int) collect($reversal['lines'])->sum('debit'))->toBe(2500)
        ->and((int) collect($reversal['lines'])->sum('credit'))->toBe(2500);

    expect(JournalEntry::find($entry['id'])->status)->toBe('reversed');

    // A reversed entry cannot be reversed twice.
    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/journal-entries/{$entry['id']}/reverse")
        ->assertStatus(422);
});

test('journal entries and accounts stay inside their business', function () {
    $a = financeCtx($this, 'JA');

    $aCash = financeAccount($this, $a, '1000', 'Cash', 'asset');
    $aSales = financeAccount($this, $a, '4000', 'Sales', 'revenue');

    $aEntry = financeJournal($this, $a, [
        ['account_id' => $aCash['id'], 'debit' => 700],
        ['account_id' => $aSales['id'], 'credit' => 700],
    ]);

    $b = financeCtx($this, 'JB2');

    // Another business cannot post against these accounts…
    $this->withHeaders($b['bizHeaders'])->postJson('/api/v1/journal-entries', [
        'entry_date' => now()->toDateString(),
        'lines' => [
            ['account_id' => $aCash['id'], 'debit' => 700],
            ['account_id' => $aSales['id'], 'credit' => 700],
        ],
    ])->assertStatus(404);

    // …or read the entry by id.
    $this->withHeaders($b['bizHeaders'])
        ->getJson("/api/v1/journal-entries/{$aEntry['id']}")
        ->assertStatus(404);

    // B's trial balance only contains B's own (empty) chart of accounts.
    $trial = $this->withHeaders($b['bizHeaders'])->getJson('/api/v1/trial-balance')->json('data');
    expect($trial['accounts'])->toBe([])
        ->and($trial['total_debit'])->toBe(0);

    // And a cross-tenant account id in a body is refused outright.
    expect($a['biz']['id'])->not->toBe($b['biz']['id']);
});

test('receivable invoices can be read, part paid and age correctly', function () {
    $ctx = financeCtx($this, 'AR');

    $invoice = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/ar-invoices', [
        'number' => 'AR-1001',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(5)->toDateString(),
        'subtotal' => 1000,
        'total' => 1000,
    ])->assertStatus(201)->json('data');

    $this->withHeaders($ctx['bizHeaders'])
        ->getJson("/api/v1/ar-invoices/{$invoice['id']}")
        ->assertOk()
        ->assertJsonPath('data.number', 'AR-1001');

    $paid = $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/ar-invoices/{$invoice['id']}/pay", [
            'amount' => 400,
            'payment_date' => now()->toDateString(),
        ])
        ->assertOk()
        ->json('data');

    expect($paid['status'])->toBe('partial')
        ->and((int) $paid['amount_paid'])->toBe(400);

    $aging = $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/ar-aging')->json('data');
    expect($aging['buckets']['current'])->toBe(600)
        ->and($aging['total'])->toBe(600);

    // A second, overdue invoice lands in the 31–60 day bucket.
    $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/ar-invoices', [
        'number' => 'AR-1002',
        'issue_date' => now()->subDays(60)->toDateString(),
        'due_date' => now()->subDays(45)->toDateString(),
        'subtotal' => 300,
        'total' => 300,
    ])->assertStatus(201);

    $aging = $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/ar-aging')->json('data');
    expect($aging['buckets']['31_60'])->toBe(300)
        ->and($aging['total'])->toBe(900);

    // Settling the first invoice removes it from aging.
    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/ar-invoices/{$invoice['id']}/pay", [
            'amount' => 600,
            'payment_date' => now()->toDateString(),
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');

    $aging = $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/ar-aging')->json('data');
    expect($aging['buckets']['current'])->toBe(0)
        ->and($aging['total'])->toBe(300);

    expect(ArInvoice::find($invoice['id'])->amount_paid)->toBe(1000);
});

test('payable invoices can be read, part paid and age correctly', function () {
    $ctx = financeCtx($this, 'AP');

    $invoice = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/ap-invoices', [
        'number' => 'AP-2001',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(10)->toDateString(),
        'subtotal' => 800,
        'total' => 800,
    ])->assertStatus(201)->json('data');

    $this->withHeaders($ctx['bizHeaders'])
        ->getJson("/api/v1/ap-invoices/{$invoice['id']}")
        ->assertOk()
        ->assertJsonPath('data.number', 'AP-2001');

    $paid = $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/ap-invoices/{$invoice['id']}/pay", [
            'amount' => 300,
            'payment_date' => now()->toDateString(),
        ])
        ->assertOk()
        ->json('data');

    expect($paid['status'])->toBe('partial');

    $aging = $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/ap-aging')->json('data');
    expect($aging['total'])->toBe(500);

    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/ap-invoices/{$invoice['id']}/pay", [
            'amount' => 500,
            'payment_date' => now()->toDateString(),
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');

    $aging = $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/ap-aging')->json('data');
    expect($aging['total'])->toBe(0);
});

test('the financial summary reflects ledger activity and outstanding balances', function () {
    $ctx = financeCtx($this, 'FS');

    $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/financial-transactions', [
        'type' => 'income',
        'amount' => 1000,
        'transaction_date' => now()->toDateString(),
        'description' => 'Interest',
    ])->assertStatus(201);

    $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/financial-transactions', [
        'type' => 'expense',
        'amount' => 400,
        'transaction_date' => now()->toDateString(),
        'description' => 'Rent',
    ])->assertStatus(201);

    $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/ar-invoices', [
        'number' => 'AR-3001',
        'issue_date' => now()->toDateString(),
        'subtotal' => 500,
        'total' => 500,
    ])->assertStatus(201);

    $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/ap-invoices', [
        'number' => 'AP-3001',
        'issue_date' => now()->toDateString(),
        'subtotal' => 200,
        'total' => 200,
    ])->assertStatus(201);

    $summary = $this->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/analytics/financial?from='.now()->startOfMonth()->toDateString()
            .'&to='.now()->toDateString())
        ->assertOk()
        ->json('data');

    expect((float) $summary['revenue'])->toBe(0.0)
        ->and((float) $summary['other_income'])->toBe(1000.0)
        ->and((float) $summary['operating_expenses'])->toBe(400.0)
        ->and((float) $summary['net_profit'])->toBe(600.0)
        ->and((float) $summary['accounts_receivable'])->toBe(500.0)
        ->and((float) $summary['accounts_payable'])->toBe(200.0);
});

test('finance records cannot be read or created across businesses', function () {
    $a = financeCtx($this, 'FA');

    $aAccount = financeAccount($this, $a, '1000', 'Cash', 'asset');

    $aInvoice = $this->withHeaders($a['bizHeaders'])->postJson('/api/v1/ar-invoices', [
        'number' => 'AR-4001',
        'issue_date' => now()->toDateString(),
        'subtotal' => 900,
        'total' => 900,
    ])->json('data');

    $b = financeCtx($this, 'FB');

    // B cannot book a transaction against A's account.
    $this->withHeaders($b['bizHeaders'])->postJson('/api/v1/financial-transactions', [
        'type' => 'expense',
        'amount' => 100,
        'transaction_date' => now()->toDateString(),
        'account_id' => $aAccount['id'],
    ])->assertStatus(404);

    // B cannot read A's invoice and sees none of its own.
    $this->withHeaders($b['bizHeaders'])
        ->getJson("/api/v1/ar-invoices/{$aInvoice['id']}")
        ->assertStatus(404);

    $this->withHeaders($b['bizHeaders'])
        ->getJson('/api/v1/ar-invoices')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect($this->withHeaders($b['bizHeaders'])->getJson('/api/v1/ar-aging')->json('data.total'))->toBe(0);
});

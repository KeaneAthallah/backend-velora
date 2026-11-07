<?php

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\User;

/**
 * HR module: attendance clock in/out, leave requests and decisions, employee
 * profiles, and the cross-business rules for all three.
 */
function hrCtx($test, string $suffix): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "HR $suffix"])
        ->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    $outlet = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => "Outlet $suffix", 'code' => "HR-$suffix"])
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

test('attendance check-in and check-out keep a single open record', function () {
    $ctx = hrCtx($this, 'AT');

    $record = $this->withHeaders($ctx['headers'])
        ->postJson('/api/v1/attendances/check-in', ['notes' => 'Morning shift'])
        ->assertStatus(201)
        ->json('data');

    expect((int) $record['user_id'])->toBe($ctx['user']->id)
        ->and($record['status'])->toBe('present')
        ->and($record['check_out_at'])->toBeNull();

    $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/attendances')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/attendances/{$record['id']}/check-out")
        ->assertOk()
        ->assertJsonPath('data.id', $record['id']);

    expect(Attendance::find($record['id'])->check_out_at)->not->toBeNull();

    // Checking out twice is refused rather than shifting the end time.
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/attendances/{$record['id']}/check-out")
        ->assertStatus(422);
});

test('leave requests can be submitted and decided by a manager', function () {
    $ctx = hrCtx($this, 'LV');

    $category = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/leave-categories', [
        'name' => 'Annual',
        'default_days' => 12,
    ])->assertStatus(201)->json('data');

    $request = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/leave-requests', [
        'leave_category_id' => $category['id'],
        'start_date' => '2026-12-01',
        'end_date' => '2026-12-03',
        'reason' => 'Family visit',
    ])->assertStatus(201)->json('data');

    expect($request['status'])->toBe('pending')
        ->and((int) $request['days'])->toBe(3);

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/leave-requests/{$request['id']}/approve", ['status' => 'approved'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    expect((int) LeaveRequest::find($request['id'])->approved_by)->toBe($ctx['user']->id);

    // A second request can be rejected.
    $second = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/leave-requests', [
        'start_date' => '2026-12-10',
        'end_date' => '2026-12-10',
    ])->assertStatus(201)->json('data');

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/leave-requests/{$second['id']}/approve", [
            'status' => 'rejected',
            'approval_notes' => 'Peak season',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    $this->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/leave-requests?status=rejected')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('employee profiles can be created and updated by id', function () {
    $ctx = hrCtx($this, 'EP');
    $employee = User::factory()->create();

    $profile = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/employee-profiles', [
        'user_id' => $employee->id,
        'employee_number' => 'EMP-001',
        'employment_status' => 'active',
    ])->assertStatus(201)->json('data');

    // Regression: the profile route parameter must bind, so the update reaches
    // the row instead of an empty model (which returned 404).
    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/employee-profiles/{$profile['id']}", [
        'employee_number' => 'EMP-002',
        'phone' => '555-0100',
    ])->assertOk()->assertJsonPath('data.employee_number', 'EMP-002');

    expect($profile['employee_number'])->toBe('EMP-001');
});

test('attendance, leave and profiles are tenant scoped', function () {
    $a = hrCtx($this, 'HA');

    $record = $this->withHeaders($a['headers'])
        ->postJson('/api/v1/attendances/check-in')
        ->assertStatus(201)
        ->json('data');

    $request = $this->withHeaders($a['bizHeaders'])->postJson('/api/v1/leave-requests', [
        'start_date' => '2026-12-20',
        'end_date' => '2026-12-21',
    ])->json('data');

    $profile = $this->withHeaders($a['bizHeaders'])->postJson('/api/v1/employee-profiles', [
        'user_id' => $a['user']->id,
        'employee_number' => 'A-001',
    ])->json('data');

    $b = hrCtx($this, 'HB');

    // B sees none of A's rows.
    $this->withHeaders($b['headers'])->getJson('/api/v1/attendances')->assertOk()->assertJsonCount(0, 'data');
    $this->withHeaders($b['bizHeaders'])->getJson('/api/v1/leave-requests')->assertOk()->assertJsonCount(0, 'data');
    $this->withHeaders($b['bizHeaders'])->getJson('/api/v1/employee-profiles')->assertOk()->assertJsonCount(0, 'data');

    // B cannot decide A's leave or edit A's profile.
    $this->withHeaders($b['headers'])
        ->postJson("/api/v1/leave-requests/{$request['id']}/approve")
        ->assertStatus(404);

    $this->withHeaders($b['bizHeaders'])
        ->putJson("/api/v1/employee-profiles/{$profile['id']}", ['employee_number' => 'HACK'])
        ->assertStatus(404);

    // A can still read its own attendance record.
    app('auth')->forgetGuards();
    $this->withHeaders($a['headers'])
        ->getJson('/api/v1/attendances')
        ->assertOk()
        ->assertJsonPath('data.0.id', $record['id']);
});

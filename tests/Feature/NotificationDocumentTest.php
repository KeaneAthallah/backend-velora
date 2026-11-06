<?php

use App\Models\BusinessMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function inboxSetup($test, string $suffix = 'IN'): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $owner = User::factory()->create();
    $token = $owner->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "Inbox Biz $suffix"])
        ->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    $outlet = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => "Inbox Outlet $suffix", 'code' => "IN-$suffix"])
        ->json('data');
    $headers = array_merge($bizHeaders, ['X-Outlet-Id' => $outlet['id']]);

    return compact('owner', 'token', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers');
}

test('notifications can be listed, counted, read and cleared', function () {
    $ctx = inboxSetup($this, 'NOT');

    $first = UserNotification::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $ctx['owner']->id,
        'type' => 'sale.completed',
        'title' => 'Sale completed',
        'body' => 'A sale was completed.',
        'data' => ['sale_id' => '1'],
    ]);
    UserNotification::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $ctx['owner']->id,
        'type' => 'inventory.low_stock',
        'title' => 'Low stock',
    ]);

    $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(2, 'data.notifications.data')
        ->assertJsonPath('data.unread_count', 2);

    $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 2);

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/notifications/{$first->id}/read")
        ->assertOk()
        ->assertJsonPath('data.notification.read_at', fn ($value) => $value !== null);

    $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/notifications?unread_only=1')
        ->assertOk()
        ->assertJsonCount(1, 'data.notifications.data');

    $this->withHeaders($ctx['headers'])
        ->postJson('/api/v1/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.updated', 1);

    $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);
});

test('another user cannot read or delete your notification', function () {
    $ctx = inboxSetup($this, 'OWN');

    $notification = UserNotification::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $ctx['owner']->id,
        'type' => 'system',
        'title' => 'Private',
    ]);

    app('auth')->forgetGuards();
    $this->flushHeaders();
    $intruder = User::factory()->create();
    BusinessMembership::create([
        'user_id' => $intruder->id,
        'business_id' => $ctx['biz']['id'],
        'role_id' => Role::where('business_id', $ctx['biz']['id'])->where('slug', 'owner')->value('id'),
        'status' => 'active',
        'joined_at' => now(),
    ]);
    $intruderToken = $intruder->createToken('t')->plainTextToken;
    $intruderHeaders = [
        'Authorization' => "Bearer $intruderToken",
        'X-Business-Id' => $ctx['biz']['id'],
    ];

    $this->withHeaders($intruderHeaders)
        ->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertStatus(404);

    $this->withHeaders($intruderHeaders)
        ->deleteJson("/api/v1/notifications/{$notification->id}")
        ->assertStatus(404);
});

test('documents can be uploaded, listed, downloaded and deleted', function () {
    Storage::fake('local');
    $ctx = inboxSetup($this, 'DOC');

    $file = UploadedFile::fake()->create('invoice.pdf', 24, 'application/pdf');

    $document = $this->withHeaders($ctx['headers'])
        ->post('/api/v1/documents', [
            'file' => $file,
            'name' => 'March invoice',
            'type' => 'invoice',
            'description' => 'Supplier invoice for March',
        ])
        ->assertStatus(201)
        ->json('data.document');

    expect($document['name'])->toBe('March invoice');
    expect($document['type'])->toBe('invoice');
    expect($document['size'])->toBeGreaterThan(0);

    Storage::disk('local')->assertExists('documents/'.$ctx['biz']['id'].'/'.$file->hashName());

    $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/documents?type=invoice')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $document['id']);

    $this->withHeaders($ctx['headers'])
        ->get("/api/v1/documents/{$document['id']}/download")
        ->assertOk();

    $this->withHeaders($ctx['headers'])
        ->deleteJson("/api/v1/documents/{$document['id']}")
        ->assertOk();

    Storage::disk('local')->assertMissing('documents/'.$ctx['biz']['id'].'/'.$file->hashName());
});

test('documents are tenant scoped', function () {
    Storage::fake('local');
    $ctx = inboxSetup($this, 'ISO');

    $file = UploadedFile::fake()->create('contract.pdf', 8, 'application/pdf');
    $document = $this->withHeaders($ctx['headers'])
        ->post('/api/v1/documents', ['file' => $file, 'type' => 'contract'])
        ->assertStatus(201)
        ->json('data.document');

    app('auth')->forgetGuards();
    $this->flushHeaders();
    $other = User::factory()->create();
    $otherToken = $other->createToken('t')->plainTextToken;
    $otherAuth = ['Authorization' => "Bearer $otherToken"];
    $otherBiz = $this->withHeaders($otherAuth)
        ->postJson('/api/v1/businesses', ['name' => 'Inbox Other ISO'])
        ->json('data');
    $otherHeaders = array_merge($otherAuth, ['X-Business-Id' => $otherBiz['id']]);

    $this->withHeaders($otherHeaders)
        ->getJson('/api/v1/documents')
        ->assertOk()
        ->assertJsonPath('data.total', 0);

    $this->withHeaders($otherHeaders)
        ->getJson("/api/v1/documents/{$document['id']}/download")
        ->assertStatus(404);
});

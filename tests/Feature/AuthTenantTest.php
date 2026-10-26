<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('registration and login', function () {
    $res = $this->postJson('/api/v1/auth/register', [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);
    $res->assertStatus(201)->assertJsonPath('success', true);
    $token = $res->json('data.token');

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'alice@example.com',
        'password' => 'password123',
    ]);
    $login->assertOk()->assertJsonPath('success', true);
});

test('unauthenticated access rejected', function () {
    $this->getJson('/api/v1/me')->assertStatus(401);
});

test('business A cannot access business B', function () {
    $u1 = User::factory()->create();
    Sanctum::actingAs($u1);
    $bA = $this->postJson('/api/v1/businesses', ['name' => 'Biz A'])->json('data');
    $this->app['auth']->forgetGuards();

    $u2 = User::factory()->create();
    Sanctum::actingAs($u2);
    $bB = $this->postJson('/api/v1/businesses', ['name' => 'Biz B'])->json('data');
    $this->app['auth']->forgetGuards();

    // user1 tries to access business B
    Sanctum::actingAs($u1);
    $res = $this->withHeaders(['X-Business-Id' => $bB['id']])->getJson('/api/v1/outlets');
    $res->assertStatus(403);
});

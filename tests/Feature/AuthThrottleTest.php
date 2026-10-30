<?php

test('login is throttled after repeated failed attempts', function () {
    $payload = ['email' => 'nobody@example.com', 'password' => 'wrong-password'];

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.10.10'])
            ->postJson('/api/v1/auth/login', $payload)
            ->assertStatus(401);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.10.10.10'])
        ->postJson('/api/v1/auth/login', $payload)
        ->assertStatus(429)
        ->assertJsonPath('success', false);
});

test('registration is throttled', function () {
    $payload = [
        'name' => 'Throttled Owner',
        'email' => 'throttled@example.com',
        'password' => 'password-123',
        'password_confirmation' => 'password-123',
    ];

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        // The first attempt creates the account; the rest fail the unique check.
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.10.12'])
            ->postJson('/api/v1/auth/register', $payload)
            ->assertStatus($attempt === 1 ? 201 : 422);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.10.10.12'])
        ->postJson('/api/v1/auth/register', $payload)
        ->assertStatus(429)
        ->assertJsonPath('success', false);
});

test('forgot password is throttled', function () {
    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.10.11'])
            ->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.10.10.11'])
        ->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
        ->assertStatus(429);
});

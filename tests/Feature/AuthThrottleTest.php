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

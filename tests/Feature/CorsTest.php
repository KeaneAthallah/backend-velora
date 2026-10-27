<?php

test('only configured frontend origins receive CORS permission', function () {
    config()->set('cors.allowed_origins', [
        'http://localhost:3000',
        'http://localhost:3001',
    ]);

    $allowed = $this->withHeaders([
        'Origin' => 'http://localhost:3000',
        'Access-Control-Request-Method' => 'POST',
    ])->options('/api/v1/auth/login');

    $allowed->assertNoContent();
    expect($allowed->headers->get('Access-Control-Allow-Origin'))
        ->toBe('http://localhost:3000');

    $blocked = $this->withHeaders([
        'Origin' => 'https://example.invalid',
        'Access-Control-Request-Method' => 'POST',
    ])->options('/api/v1/auth/login');

    expect($blocked->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

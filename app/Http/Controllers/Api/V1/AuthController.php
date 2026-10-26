<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
            'phone' => 'nullable|string|max:32',
        ]);
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
        ]);
        $token = $user->createToken('auth')->plainTextToken;
        return $this->success(['user' => $user, 'token' => $token], 'Registered', 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->error('Invalid credentials', 401, [], 'INVALID_CREDENTIALS');
        }
        if ($user->status !== 'active') {
            return $this->error('Account inactive', 403, [], 'ACCOUNT_INACTIVE');
        }
        $token = $user->createToken('auth')->plainTextToken;
        return $this->success(['user' => $user, 'token' => $token]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return $this->success(null, 'Logged out');
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            return $this->error('Current password incorrect', 422, [], 'INVALID_PASSWORD');
        }
        $user->update(['password' => Hash::make($data['password'])]);
        return $this->success(null, 'Password changed');
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $status = Password::sendResetLink($request->only('email'));
        return $this->success(null, __($status));
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function ($user, $password) {
            $user->forceFill(['password' => Hash::make($password)])->save();
        });
        return $this->success(null, __($status));
    }
}

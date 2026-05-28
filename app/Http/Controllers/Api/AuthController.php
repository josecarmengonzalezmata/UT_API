<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (!$user || !$user->is_active || !Hash::check($credentials['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales invalidas.'],
            ]);
        }

        $roles = $user->roles()->pluck('code')->values();
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'area' => $user->area,
                'avatar_url' => $user->avatar_url,
                'is_active' => $user->is_active,
                'roles' => $roles,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'area' => $user->area,
                'avatar_url' => $user->avatar_url,
                'is_active' => $user->is_active,
                'roles' => $user->roles()->pluck('code')->values(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Sesion cerrada']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (!$user) {
            return response()->json(['message' => 'Si el correo existe, se enviara un enlace de recuperacion.']);
        }

        $plainToken = Str::random(64);
        PasswordResetToken::query()->updateOrCreate(
            ['email' => $user->email],
            [
                'token_hash' => Hash::make($plainToken),
                'expires_at' => now()->addMinutes(30),
            ]
        );

        return response()->json([
            'message' => 'Token de recuperacion generado.',
            'reset_token' => $plainToken,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $record = PasswordResetToken::query()->where('email', $data['email'])->first();

        if (!$record || !$record->expires_at || $record->expires_at->isPast() || !Hash::check($data['token'], $record->token_hash)) {
            throw ValidationException::withMessages([
                'token' => ['El token es invalido o expiro.'],
            ]);
        }

        $user = User::query()->where('email', $data['email'])->firstOrFail();
        $user->forceFill([
            'password_hash' => Hash::make($data['password']),
        ])->save();

        $record->delete();

        return response()->json(['message' => 'Contrasena actualizada correctamente.']);
    }
}

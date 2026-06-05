<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => User::query()->with('roles')->orderBy('full_name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'digits:10'],
            'area' => ['nullable', 'string', 'max:120'],
            'avatar_url' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['in:administrador,docente,tutor'],
        ]);

        $user = User::query()->create([
            'full_name' => $data['full_name'],
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'area' => $data['area'] ?? null,
            'avatar_url' => $data['avatar_url'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $roleIds = Role::query()->whereIn('code', $data['roles'])->pluck('id')->all();
        $user->roles()->sync($roleIds);

        return response()->json(['data' => $user->load('roles')], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => $user->load('roles')]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:150'],
            'password' => ['nullable', 'string', 'min:8'],
            'phone' => ['nullable', 'digits:10'],
            'area' => ['nullable', 'string', 'max:120'],
            'avatar_url' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['in:administrador,docente,tutor'],
        ]);

        if (!empty($data['password'])) {
            $data['password_hash'] = Hash::make($data['password']);
            unset($data['password']);
        }

        $user->fill($data)->save();

        if (array_key_exists('roles', $data)) {
            $roleIds = Role::query()->whereIn('code', $data['roles'])->pluck('id')->all();
            $user->roles()->sync($roleIds);
        }

        return response()->json(['data' => $user->fresh()->load('roles')]);
    }

    public function destroy(User $user): JsonResponse
    {
        $user->update(['is_active' => false]);

        return response()->json(['message' => 'Usuario desactivado']);
    }
}

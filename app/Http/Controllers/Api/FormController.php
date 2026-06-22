<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FormController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Form::query()->with('accessRule.roles')->orderBy('section')->orderBy('title')->get(),
        ]);
    }

    public function show(Form $form): JsonResponse
    {
        $form->load('accessRule.roles');

        return response()->json(['data' => $form]);
    }

    public function update(Request $request, Form $form): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'section' => ['sometimes', 'in:docentes,tutorias,estadias'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'access_roles' => ['sometimes', 'array'],
            'access_roles.*' => ['in:docente,tutor'],
            'due_at' => ['nullable', 'date'],
        ]);

        $form->fill($request->only(['title', 'section', 'description', 'is_active']))->save();

        if ($request->has('access_roles') || $request->has('due_at')) {
            $accessRule = $form->accessRule()->firstOrCreate([
                'form_id' => $form->id,
            ], [
                'updated_by' => $request->user()->id,
            ]);

            if (array_key_exists('due_at', $data)) {
                $accessRule->due_at = $data['due_at'] ? Carbon::parse($data['due_at']) : null;
            }

            if (array_key_exists('access_roles', $data)) {
                $roleIds = Role::query()
                    ->whereIn('code', $data['access_roles'])
                    ->pluck('id')
                    ->all();

                $accessRule->roles()->sync($roleIds);
            }

            $accessRule->updated_by = $request->user()->id;
            $accessRule->save();
            $accessRule->load('roles');
        }

        $form->load('accessRule.roles');

        return response()->json(['data' => $form]);
    }

    /**
     * Obtener formularios activos para el usuario actual
     */
    public function active(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Obtener roles del usuario actual
        $userRoles = $user->roles()->pluck('code')->toArray();

        // Si el usuario no tiene roles, devolver array vacío
        if (empty($userRoles)) {
            return response()->json(['data' => []]);
        }

        // Obtener formularios activos que tengan reglas de acceso y estén dentro de los roles del usuario
        $forms = Form::with(['accessRule' => function ($query) {
            $query->with('roles');
        }])
        ->where('is_active', true)
        ->whereHas('accessRule.roles', function ($query) use ($userRoles) {
            $query->whereIn('code', $userRoles);
        })
        ->orderBy('due_at', 'asc')
        ->get()
        ->map(function ($form) {
            return [
                'id' => $form->id,
                'title' => $form->title,
                'section' => $form->section,
                'description' => $form->description,
                'is_active' => $form->is_active,
                'due_at' => $form->accessRule?->due_at,
                'access_roles' => $form->accessRule?->roles->pluck('code') ?? [],
            ];
        });

        return response()->json(['data' => $forms]);
    }
}
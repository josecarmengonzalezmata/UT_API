<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FormController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Form::query()->orderBy('section')->orderBy('title')->get(),
        ]);
    }

    public function show(Form $form): JsonResponse
    {
        return response()->json(['data' => $form]);
    }

    public function update(Request $request, Form $form): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'section' => ['sometimes', 'in:docentes,tutorias,estadias'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $form->fill($data)->save();

        return response()->json(['data' => $form->fresh()]);
    }
}

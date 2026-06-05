<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicCycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CycleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => AcademicCycle::query()->orderByDesc('created_at')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_name' => ['required', 'string', 'max:80'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['required', 'in:activo,cerrado'],
        ]);

        return response()->json([
            'data' => DB::transaction(function () use ($data) {
                if ($data['status'] === 'activo') {
                    AcademicCycle::query()->where('status', 'activo')->update(['status' => 'cerrado']);
                }

                return AcademicCycle::query()->create($data);
            }),
        ], 201);
    }

    public function show(AcademicCycle $cycle): JsonResponse
    {
        return response()->json(['data' => $cycle]);
    }

    public function update(Request $request, AcademicCycle $cycle): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'period_name' => ['sometimes', 'string', 'max:80'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'in:activo,cerrado'],
        ]);

        if (($data['status'] ?? null) === 'activo') {
            AcademicCycle::query()->where('id', '!=', $cycle->id)->where('status', 'activo')->update(['status' => 'cerrado']);
        }

        $cycle->fill($data)->save();

        return response()->json(['data' => $cycle->fresh()]);
    }

    public function destroy(AcademicCycle $cycle): JsonResponse
    {
        $cycle->delete();

        return response()->json(['message' => 'Ciclo eliminado']);
    }
}

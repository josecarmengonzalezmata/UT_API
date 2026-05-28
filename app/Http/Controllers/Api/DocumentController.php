<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Document::query();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('cycle_id')) {
            $query->where('cycle_id', $request->integer('cycle_id'));
        }

        if ($request->filled('form_id')) {
            $query->where('form_id', $request->integer('form_id'));
        }

        return response()->json([
            'data' => $query->orderByDesc('submitted_at')->paginate($request->integer('per_page', 20)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'form_id' => ['required', 'integer', 'exists:forms,id'],
            'cycle_id' => ['nullable', 'integer', 'exists:academic_cycles,id'],
            'title' => ['required', 'string', 'max:180'],
            'apartado_label' => ['nullable', 'string', 'max:120'],
            'plan' => ['nullable', 'in:nuevo_modelo,plan_normal'],
            'carrera_label' => ['nullable', 'string', 'max:180'],
            'materia' => ['nullable', 'string', 'max:140'],
            'parcial' => ['nullable', 'string', 'max:40'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        $path = $request->file('file')->store('documents', 'public');

        $document = Document::query()->create([
            'form_id' => $data['form_id'],
            'cycle_id' => $data['cycle_id'] ?? null,
            'uploaded_by' => $request->user()->id,
            'title' => $data['title'],
            'apartado_label' => $data['apartado_label'] ?? null,
            'plan' => $data['plan'] ?? null,
            'carrera_label' => $data['carrera_label'] ?? null,
            'materia' => $data['materia'] ?? null,
            'parcial' => $data['parcial'] ?? null,
            'group_id' => $data['group_id'] ?? null,
            'file_path' => $path,
            'mime_type' => $request->file('file')->getMimeType(),
            'file_size_bytes' => $request->file('file')->getSize(),
            'status' => 'pendiente',
            'submitted_at' => now(),
        ]);

        return response()->json(['data' => $document], 201);
    }

    public function show(Document $document): JsonResponse
    {
        return response()->json(['data' => $document]);
    }

    public function update(Request $request, Document $document): JsonResponse
    {
        $document->fill($request->only([
            'title', 'apartado_label', 'plan', 'carrera_label', 'materia', 'parcial', 'group_id', 'cycle_id'
        ]))->save();

        return response()->json(['data' => $document->fresh()]);
    }

    public function destroy(Document $document): JsonResponse
    {
        if ($document->file_path) {
            Storage::disk('public')->delete($document->file_path);
        }

        $document->delete();

        return response()->json(['message' => 'Documento eliminado']);
    }

    public function history(Document $document): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function review(Request $request, Document $document): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:revisado,devuelto'],
            'notes' => ['nullable', 'string'],
        ]);

        $document->status = $data['status'];
        $document->reviewed_at = now();
        $document->save();

        return response()->json(['data' => $document->fresh()]);
    }

    public function returnDocument(Request $request, Document $document): JsonResponse
    {
        $document->status = 'devuelto';
        $document->returned_at = now();
        $document->save();

        return response()->json(['data' => $document->fresh()]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    private function isAdmin(Request $request): bool
    {
        return $request->user()?->roles()->where('code', 'administrador')->exists() ?? false;
    }

    private function canAccessDocument(Request $request, Document $document): bool
    {
        return $this->isAdmin($request) || $document->uploaded_by === $request->user()?->id;
    }

    private function formatDocument(Document $document): array
    {
        $submittedAt = $document->submitted_at ?? $document->created_at;
        $groupCode = $document->group?->group_code ?? ($document->group_id ? (string) $document->group_id : null);

        return [
            'id' => $document->id,
            'nombre' => $document->title,
            'tipo' => $document->form?->form_code ?? ($document->apartado_label ? strtolower(str_replace(' ', '-', $document->apartado_label)) : 'documento'),
            'tipoLabel' => $document->form?->title ?? $document->apartado_label ?? 'Documento',
            'materia' => $document->materia ?? 'Sin materia',
            'parcial' => $document->parcial ?? '-',
            'grupo' => $groupCode ?? '-',
            'fecha' => $submittedAt?->toDateString(),
            'hora' => $submittedAt?->format('H:i'),
            'status' => $document->status,
            'observaciones' => null,
            'fileUrl' => $document->file_path ? Storage::disk('public')->url($document->file_path) : null,
            'downloadUrl' => $document->file_path ? route('documents.file', ['document' => $document->id, 'download' => 1]) : null,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = Document::query()->with(['form', 'group']);

        if (!$this->isAdmin($request)) {
            $query->where('uploaded_by', $request->user()->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('cycle_id')) {
            $query->where('cycle_id', $request->integer('cycle_id'));
        }

        if ($request->filled('form_id')) {
            $query->where('form_id', $request->integer('form_id'));
        }

        $documents = $query->orderByDesc('submitted_at')->paginate($request->integer('per_page', 20));
        $documents->setCollection($documents->getCollection()->map(fn (Document $document) => $this->formatDocument($document)));

        return response()->json([
            'data' => $documents,
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

    public function show(Request $request, Document $document): JsonResponse
    {
        if (!$this->canAccessDocument($request, $document)) {
            abort(403);
        }

        $document->loadMissing(['form', 'group']);

        return response()->json(['data' => $this->formatDocument($document)]);
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

    public function file(Request $request, Document $document): Response
    {
        if (!$this->canAccessDocument($request, $document)) {
            abort(403);
        }

        if (!$document->file_path || !Storage::disk('public')->exists($document->file_path)) {
            abort(404);
        }

        if ($request->boolean('download')) {
            return Storage::disk('public')->download($document->file_path, $document->title . '.pdf');
        }

        return response()->file(Storage::disk('public')->path($document->file_path), [
            'Content-Type' => $document->mime_type ?? 'application/pdf',
        ]);
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicCycle;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        return response()->json([
            'users_total' => User::query()->count(),
            'documents_total' => Document::query()->count(),
            'documents_pending' => Document::query()->where('status', 'pendiente')->count(),
            'documents_reviewed' => Document::query()->where('status', 'revisado')->count(),
            'messages_total' => Message::query()->count(),
            'active_cycle' => AcademicCycle::query()->where('status', 'activo')->first(),
        ]);
    }
}

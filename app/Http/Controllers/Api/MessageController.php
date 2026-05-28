<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Conversation::query()->orderByDesc('updated_at')->paginate(20)]);
    }

    public function storeConversation(Request $request): JsonResponse
    {
        $conversation = Conversation::query()->create();

        return response()->json(['data' => $conversation], 201);
    }

    public function messages(Conversation $conversation): JsonResponse
    {
        return response()->json([
            'data' => Message::query()->where('conversation_id', $conversation->id)->orderBy('created_at')->get(),
        ]);
    }

    public function storeMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string'],
            'reply_to_message_id' => ['nullable', 'integer', 'exists:messages,id'],
        ]);

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $request->user()->id,
            'body' => $data['body'],
            'reply_to_message_id' => $data['reply_to_message_id'] ?? null,
        ]);

        return response()->json(['data' => $message], 201);
    }

    public function markAsRead(Conversation $conversation): JsonResponse
    {
        return response()->json(['message' => 'Marcado como leido']);
    }
}

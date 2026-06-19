<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        $conversations = Conversation::query()
            ->with(['participants.roles', 'latestMessage.sender'])
            ->whereHas('participants', function ($query) use ($currentUser) {
                $query->where('users.id', $currentUser->id);
            })
            ->orderByDesc('updated_at')
            ->get()
            ->filter(function (Conversation $conversation) use ($currentUser) {
                return $this->isConversationAllowed($conversation, $currentUser);
            })
            ->map(function (Conversation $conversation) use ($currentUser) {
                return $this->formatConversation($conversation, (int) $currentUser->id);
            })
            ->values();

        // If the user is a docente and has no conversations, create a direct chat with an administrador (if any)
        if ($conversations->isEmpty()) {
            $currentRoleCode = $currentUser->roles->first()?->code ?? 'docente';
            if ($currentRoleCode === 'docente') {
                $admin = User::query()->whereHas('roles', function ($q) {
                    $q->where('code', 'administrador');
                })->first();

                if ($admin) {
                    // ensure there's no existing conversation between the two
                    $exists = Conversation::query()
                        ->whereHas('participants', function ($q) use ($currentUser) {
                            $q->where('users.id', $currentUser->id);
                        })
                        ->whereHas('participants', function ($q) use ($admin) {
                            $q->where('users.id', $admin->id);
                        })
                        ->exists();

                    if (! $exists) {
                        $conversation = DB::transaction(function () use ($currentUser, $admin) {
                            $conversation = Conversation::query()->create();
                            DB::table('conversation_participants')->insert([
                                ['conversation_id' => $conversation->id, 'user_id' => $currentUser->id, 'unread_count' => 0, 'last_read_at' => null],
                                ['conversation_id' => $conversation->id, 'user_id' => $admin->id, 'unread_count' => 0, 'last_read_at' => null],
                            ]);
                            return $conversation;
                        });

                        $conversations = $conversations->push($this->formatConversation($conversation->fresh(['participants.roles', 'latestMessage.sender']), (int) $currentUser->id));
                    }
                }
            }
        }

        return response()->json(['data' => $conversations]);
    }

    public function storeConversation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'participant_user_ids' => ['sometimes', 'array'],
            'participant_user_ids.*' => ['integer', 'exists:users,id'],
            'recipient_user_id' => ['sometimes', 'integer', 'exists:users,id'],
        ]);

        $participantIds = collect($data['participant_user_ids'] ?? [])
            ->when(isset($data['recipient_user_id']), function ($collection) use ($data) {
                return $collection->push((int) $data['recipient_user_id']);
            })
            ->push((int) $request->user()->id)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        // Enforce only two-party conversations between administrador <-> docente
        if ($participantIds->count() > 2) {
            return response()->json(['message' => 'Solo se permiten conversaciones entre dos participantes'], 422);
        }

        // If a recipient was provided, validate role pairing
        if (isset($data['recipient_user_id'])) {
            $recipient = User::query()->with('roles')->find((int) $data['recipient_user_id']);
            $currentUser = $request->user();
            $currentUser->loadMissing('roles');
            if (! $recipient || ! $this->isAllowedConversationPair($currentUser, $recipient)) {
                return response()->json(['message' => 'Las conversaciones solo están permitidas entre Administrador y Docente/Tutor'], 422);
            }
        }

        // If two participant ids provided, validate role pairing
        if ($participantIds->count() === 2) {
            $users = User::query()->with('roles')->whereIn('id', $participantIds->all())->get();
            if ($users->count() === 2) {
                if (! $this->isAllowedConversationPair($users[0], $users[1])) {
                    return response()->json(['message' => 'Las conversaciones solo están permitidas entre Administrador y Docente/Tutor'], 422);
                }
            }
        }

        $conversation = DB::transaction(function () use ($participantIds) {
            $conversation = Conversation::query()->create();

            foreach ($participantIds as $participantId) {
                DB::table('conversation_participants')->insert([
                    'conversation_id' => $conversation->id,
                    'user_id' => $participantId,
                    'unread_count' => 0,
                    'last_read_at' => null,
                ]);
            }

            return $conversation;
        });

        return response()->json(['data' => $this->formatConversation($conversation->fresh(['participants.roles', 'latestMessage.sender']), (int) $request->user()->id)], 201);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $currentUser = $request->user();

        // Ensure the current user is participant and conversation is admin<->docente
        if (! $this->isConversationAllowed($conversation, $currentUser)) {
            return response()->json(['message' => 'Acceso denegado a esta conversación'], 403);
        }

        $currentUserId = (int) $currentUser->id;

        return response()->json([
            'data' => Message::query()
                ->with(['sender', 'replyTo.sender', 'attachments'])
                ->where('conversation_id', $conversation->id)
                ->orderBy('created_at')
                ->get()
                ->map(function (Message $message) use ($currentUserId) {
                    return [
                        'id' => $message->id,
                        'sender' => $message->sender?->full_name ?? 'Usuario',
                        'content' => $message->body,
                        'timestamp' => $message->created_at?->format('Y-m-d H:i:s') ?? '',
                        'isOwn' => (int) $message->sender_user_id === $currentUserId,
                        'avatar_url' => $this->getUserAvatar($message->sender),
                        'avatar' => $this->getUserAvatar($message->sender),
                        'attachments' => $message->attachments->map(function (MessageAttachment $attachment) {
                            return [
                                'name' => $attachment->file_name,
                                'typeLabel' => $attachment->file_type_label ?? 'Archivo',
                                'sizeLabel' => $this->formatFileSize($attachment->file_size_bytes),
                            ];
                        })->toArray(),
                        'replyTo' => $message->replyTo ? [
                            'id' => $message->replyTo->id,
                            'sender' => $message->replyTo->sender?->full_name ?? 'Usuario',
                            'content' => $message->replyTo->body,
                        ] : null,
                    ];
                })
                ->values(),
        ]);
    }

    public function storeMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string'],
            'reply_to_message_id' => ['nullable', 'integer', 'exists:messages,id'],
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => ['file'],
        ]);

        // Ensure conversation is allowed and the user is participant
        if (! $this->isConversationAllowed($conversation, $request->user())) {
            return response()->json(['message' => 'No permitido enviar mensajes a esta conversación'], 403);
        }

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $request->user()->id,
            'body' => $data['body'],
            'reply_to_message_id' => $data['reply_to_message_id'] ?? null,
        ]);

        $attachments = [];
        foreach ($request->file('attachments', []) as $uploadedFile) {
            if (! $uploadedFile || ! $uploadedFile->isValid()) {
                continue;
            }

            $storedPath = $uploadedFile->store('message_attachments', 'public');
            $attachment = MessageAttachment::query()->create([
                'message_id' => $message->id,
                'file_name' => $uploadedFile->getClientOriginalName(),
                'file_path' => $storedPath,
                'file_size_bytes' => $uploadedFile->getSize(),
                'file_type_label' => $uploadedFile->getClientMimeType(),
            ]);

            $attachments[] = $attachment;
        }

        DB::table('conversations')->where('id', $conversation->id)->update(['updated_at' => now()]);
        DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $request->user()->id)
            ->increment('unread_count');

        return response()->json([
            'data' => [
                'id' => $message->id,
                'sender' => $request->user()->full_name,
                'content' => $message->body,
                'timestamp' => $message->created_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
                'isOwn' => true,
                'avatar_url' => $this->getUserAvatar($request->user()),
                'avatar' => $this->getUserAvatar($request->user()),
                'attachments' => collect($attachments)->map(function (MessageAttachment $attachment) {
                    return [
                        'name' => $attachment->file_name,
                        'typeLabel' => $attachment->file_type_label ?? 'Archivo',
                        'sizeLabel' => $this->formatFileSize($attachment->file_size_bytes),
                        'url' => Storage::url($attachment->file_path),
                    ];
                })->values()->toArray(),
                'replyTo' => null,
            ],
        ], 201);
    }

    private function formatFileSize(?int $sizeBytes): string
    {
        if ($sizeBytes === null) {
            return '0 B';
        }

        if ($sizeBytes < 1024) {
            return $sizeBytes . ' B';
        }

        if ($sizeBytes < 1024 * 1024) {
            return number_format($sizeBytes / 1024, 1) . ' KB';
        }

        return number_format($sizeBytes / 1024 / 1024, 1) . ' MB';
    }

    public function markAsRead(Request $request, Conversation $conversation): JsonResponse
    {
        if (! $this->isConversationAllowed($conversation, $request->user())) {
            return response()->json(['message' => 'Acceso denegado a esta conversación'], 403);
        }

        DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $request->user()->id)
            ->update(['unread_count' => 0, 'last_read_at' => now()]);

        return response()->json(['message' => 'Marcado como leido']);
    }

    public function destroy(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        // Ensure conversation is allowed and the user is participant
        if (! $this->isConversationAllowed($conversation, $request->user())) {
            return response()->json(['message' => 'No permitido eliminar mensajes de esta conversación'], 403);
        }

        // Ensure the message belongs to the conversation
        if ($message->conversation_id !== $conversation->id) {
            return response()->json(['message' => 'Mensaje no encontrado en esta conversación'], 404);
        }

        // Only the original sender may delete their message
        if ((int) $message->sender_user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'No autorizado para eliminar este mensaje'], 403);
        }

        $message->delete();

        // Touch conversation updated_at
        DB::table('conversations')->where('id', $conversation->id)->update(['updated_at' => now()]);

        return response()->json(['message' => 'Mensaje eliminado correctamente']);
    }

    /**
     * Obtiene la representación del avatar de un usuario
     * - Si tiene avatar_url, devuelve la ruta URL
     * - Si no, devuelve sus iniciales (máximo 2 letras)
     */
    private function getUserAvatar(?User $user): string
    {
        if (!$user) {
            return 'U';
        }

        // Si el usuario tiene una URL de avatar guardada
        if (!empty($user->avatar_url)) {
            // Si ya es una URL completa (http/https) o es una ruta de API
            if (str_starts_with($user->avatar_url, 'http') || 
                str_starts_with($user->avatar_url, '/api/users/') ||
                str_starts_with($user->avatar_url, '/storage/')) {
                return $user->avatar_url;
            }
            // Si es solo el nombre del archivo, construir la ruta de storage
            return '/storage/' . ltrim($user->avatar_url, '/');
        }

        // Si no tiene avatar, devolver iniciales
        $name = $user->full_name ?? '';
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        
        if (empty($parts)) {
            return 'U';
        }
        
        // Tomar primera letra del primer nombre
        $firstInitial = mb_strtoupper(mb_substr($parts[0], 0, 1));
        
        // Si hay más de una palabra, tomar la primera letra de la última palabra (apellido)
        if (count($parts) > 1) {
            $lastInitial = mb_strtoupper(mb_substr(end($parts), 0, 1));
            return $firstInitial . $lastInitial;
        }
        
        return $firstInitial;
    }

   private function formatConversation(Conversation $conversation, int $currentUserId): array
{
    $conversation->loadMissing(['participants.roles', 'latestMessage.sender']);
    
    // IMPORTANTE: Seleccionar al otro participante (el que NO es el usuario actual)
    $otherParticipants = $conversation->participants->where('id', '!=', $currentUserId)->values();
    $otherParticipant = $otherParticipants->first();
    
    // Si no hay otro participante (no debería pasar), usar el primero
    if (!$otherParticipant && $conversation->participants->isNotEmpty()) {
        $otherParticipant = $conversation->participants->first();
    }
    
    $displayName = $otherParticipant?->full_name ?? 'Conversación';
    $roleCode = $otherParticipant?->roles?->first()?->code ?? 'docente';
    $roleLabel = match ($roleCode) {
        'administrador' => 'Administrador',
        'tutor' => 'Tutor',
        default => 'Docente',
        };
        $pivot = $conversation->participants->firstWhere('id', $currentUserId)?->pivot;
        $latestMessage = $conversation->latestMessage;

        return [
            'id' => $conversation->id,
            'name' => $displayName,
            'role' => $roleLabel,
            'lastMessage' => $latestMessage?->body ?? 'Nuevo chat',
            'timestamp' => $latestMessage?->created_at?->format('Y-m-d H:i:s') ?? $conversation->updated_at?->format('Y-m-d H:i:s') ?? '',
            'unread' => (int) ($pivot->unread_count ?? 0),
            'avatar_url' => $this->getUserAvatar($otherParticipant),
            'avatar' => $this->getUserAvatar($otherParticipant),
            'status' => 'offline',
            'participants' => $conversation->participants->map(function (User $participant) {
                return [
                    'id' => $participant->id,
                    'name' => $participant->full_name,
                    'role' => $participant->roles->first()?->code ?? 'docente',
                    'avatar_url' => $this->getUserAvatar($participant),
                ];
            })->values(),
            'lastMessageAt' => $latestMessage?->created_at?->format('Y-m-d H:i:s') ?? $conversation->updated_at?->format('Y-m-d H:i:s') ?? '',
        ];
    }

    private function userHasRole(User $user, string $roleCode): bool
    {
        $user->loadMissing('roles');
        return $user->roles->contains(function ($role) use ($roleCode) {
            return (string) $role->code === $roleCode;
        });
    }

    private function isTeacherRole(User $user): bool
    {
        return $this->userHasRole($user, 'docente') || $this->userHasRole($user, 'tutor');
    }

    private function isAllowedConversationPair(User $userA, User $userB): bool
    {
        $userAIsAdmin = $this->userHasRole($userA, 'administrador');
        $userBIsAdmin = $this->userHasRole($userB, 'administrador');
        $userAIsTeacher = $this->isTeacherRole($userA);
        $userBIsTeacher = $this->isTeacherRole($userB);

        return ($userAIsAdmin && $userBIsTeacher) || ($userBIsAdmin && $userAIsTeacher);
    }

    private function isConversationAllowed(Conversation $conversation, User $currentUser): bool
    {
        $conversation->loadMissing(['participants.roles']);

        // current user must be a participant
        if (! $conversation->participants->contains('id', $currentUser->id)) {
            return false;
        }

        // only two-party conversations supported
        if ($conversation->participants->count() !== 2) {
            return false;
        }

        $other = $conversation->participants->firstWhere('id', '!=', $currentUser->id);
        if (! $other) return false;

        return $this->isAllowedConversationPair($currentUser, $other);
    }
}
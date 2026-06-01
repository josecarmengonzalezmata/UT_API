<?php

declare(strict_types=1);

namespace App;

use DateInterval;
use DateTimeImmutable;
use PDO;
use PDOException;

final class Api
{
    public function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            Response::noContent();
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        try {
            $this->route($method, $path);
        } catch (ApiException $exception) {
            Response::error($exception->getMessage(), $exception->getStatusCode(), $exception->getDetails());
        } catch (PDOException $exception) {
            Response::error('Database error: ' . $exception->getMessage(), 500);
        } catch (\Throwable $exception) {
            Response::error('Server error: ' . $exception->getMessage(), 500);
        }
    }

    private function route(string $method, string $path): void
    {
        if ($path === '/' || $path === '/index.php') {
            Response::json([
                'name' => 'UT_API',
                'status' => 'running',
                'message' => 'Plain PHP API backend for docente management',
            ]);
        }

        if (!str_starts_with($path, '/api')) {
            Response::error('Not found', 404);
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        array_shift($segments);

        $first = $segments[0] ?? '';

        if ($first === 'auth') {
            $this->handleAuth($method, $segments);
            return;
        }

        if ($first === 'calendar') {
            if ($method === 'GET' && ($segments[1] ?? '') === 'file') {
                $this->streamCurrentCalendarFile();
            }

            if ($method === 'GET') {
                Response::json(['data' => $this->getCurrentCalendarMeta()]);
            }

            $user = $this->requireUser();
            $this->handleCalendar($method, $segments, $user);
            return;
        }

        if ($first === 'groups') {
            // public listing
            if ($method === 'GET' && count($segments) === 1) {
                Response::json(['data' => $this->loadGroups()]);
                return;
            }

            // allow fetching a single group publicly
            if ($method === 'GET' && isset($segments[1]) && is_numeric($segments[1])) {
                $id = (int) $segments[1];
                $groups = $this->loadGroups();
                foreach ($groups as $g) {
                    if ((int)$g['id'] === $id) {
                        Response::json(['data' => $g]);
                        return;
                    }
                }
                Response::error('Not found', 404);
            }

            // mutations require an authenticated user with admin role
            $user = $this->requireUser();
            $this->requireAnyRole($user, ['administrador']);

            // create
            if ($method === 'POST' && count($segments) === 1) {
                $data = $this->body();
                $this->validateRequired($data, ['careerCode', 'plan', 'cuatrimestre', 'groupNumber']);
                $groups = $this->loadGroups();
                $id = (int) round(microtime(true) * 1000);
                $g = [
                    'id' => $id,
                    'careerCode' => strtoupper((string)$data['careerCode']),
                    'plan' => (string)$data['plan'],
                    'cuatrimestre' => (int)$data['cuatrimestre'],
                    'groupNumber' => (int)$data['groupNumber'],
                    'name' => strtoupper((string)$data['careerCode']) . $data['cuatrimestre'] . '-' . $data['groupNumber'],
                ];
                array_unshift($groups, $g);
                $this->saveGroups($groups);
                Response::json(['data' => $g], 201);
            }

            $id = isset($segments[1]) ? (int)$segments[1] : 0;
            if ($id <= 0) {
                Response::error('Invalid group id', 422);
            }

            // update
            if ($method === 'PATCH' || $method === 'PUT') {
                $data = $this->body();
                $groups = $this->loadGroups();
                $found = false;
                foreach ($groups as &$g) {
                    if ((int)$g['id'] === $id) {
                        $found = true;
                        if (isset($data['careerCode'])) $g['careerCode'] = strtoupper((string)$data['careerCode']);
                        if (isset($data['plan'])) $g['plan'] = (string)$data['plan'];
                        if (isset($data['cuatrimestre'])) $g['cuatrimestre'] = (int)$data['cuatrimestre'];
                        if (isset($data['groupNumber'])) $g['groupNumber'] = (int)$data['groupNumber'];
                        $g['name'] = $g['careerCode'] . $g['cuatrimestre'] . '-' . $g['groupNumber'];
                        break;
                    }
                }
                unset($g);
                if (!$found) Response::error('Not found', 404);
                $this->saveGroups($groups);
                foreach ($groups as $g) if ((int)$g['id'] === $id) Response::json(['data' => $g]);
            }

            // delete
            if ($method === 'DELETE') {
                $groups = $this->loadGroups();
                $next = array_values(array_filter($groups, static fn($g) => (int)$g['id'] !== $id));
                $this->saveGroups($next);
                Response::json(['data' => null]);
            }

            Response::error('Method not allowed', 405);
        }

        $user = $this->requireUser();

        if ($first === 'dashboard' && ($segments[1] ?? '') === 'stats' && $method === 'GET') {
            $this->dashboardStats();
            return;
        }

        if ($first === 'forms') {
            $this->handleForms($method, $segments, $user);
            return;
        }

        if ($first === 'cycles') {
            $this->handleCycles($method, $segments, $user);
            return;
        }

        if ($first === 'users') {
            $this->handleUsers($method, $segments, $user);
            return;
        }

        if ($first === 'documents') {
            $this->handleDocuments($method, $segments, $user);
            return;
        }

        if ($first === 'conversations') {
            $this->handleConversations($method, $segments, $user);
            return;
        }

        Response::error('Not found', 404);
    }

    private function handleAuth(string $method, array $segments): void
    {
        $action = $segments[1] ?? '';

        if ($method === 'POST' && $action === 'login') {
            $this->login();
            return;
        }

        if ($method === 'GET' && $action === 'me') {
            Response::json(['user' => $this->requireUser()]);
            return;
        }

        if ($method === 'POST' && $action === 'logout') {
            $this->logout();
            return;
        }

        if ($method === 'POST' && $action === 'forgot-password') {
            $this->forgotPassword();
            return;
        }

        if ($method === 'POST' && $action === 'reset-password') {
            $this->resetPassword();
            return;
        }

        Response::error('Method not allowed', 405);
    }

    private function handleCycles(string $method, array $segments, array $user): void
    {
        $this->requireAnyRole($user, ['administrador']);
        $pdo = Database::pdo();

        if ($method === 'GET' && count($segments) === 1) {
            Response::json(['data' => $this->fetchAll('SELECT * FROM academic_cycles ORDER BY created_at DESC')]);
        }

        if ($method === 'POST' && count($segments) === 1) {
            $data = $this->body();
            $this->validateRequired($data, ['name', 'year', 'period_name', 'start_date', 'end_date', 'status']);

            $pdo->beginTransaction();
            try {
                if (($data['status'] ?? 'cerrado') === 'activo') {
                    $pdo->exec("UPDATE academic_cycles SET status = 'cerrado' WHERE status = 'activo'");
                }

                $statement = $pdo->prepare('INSERT INTO academic_cycles (name, year, period_name, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?)');
                $statement->execute([
                    $data['name'],
                    (int) $data['year'],
                    $data['period_name'],
                    $data['start_date'],
                    $data['end_date'],
                    $data['status'],
                ]);

                $pdo->commit();
            } catch (\Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }

            Response::json(['data' => $this->fetchOne('SELECT * FROM academic_cycles WHERE id = LAST_INSERT_ID()')], 201);
        }

        $id = isset($segments[1]) ? (int) $segments[1] : 0;
        if ($id <= 0) {
            Response::error('Invalid cycle id', 422);
        }

        if ($method === 'GET') {
            $cycle = $this->fetchOne('SELECT * FROM academic_cycles WHERE id = ?', [$id]);
            if (!$cycle) {
                Response::error('Cycle not found', 404);
            }
            Response::json(['data' => $cycle]);
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            $data = $this->body();
            if (($data['status'] ?? null) === 'activo') {
                $pdo->prepare("UPDATE academic_cycles SET status = 'cerrado' WHERE id <> ? AND status = 'activo'")->execute([$id]);
            }

            $current = $this->fetchOne('SELECT * FROM academic_cycles WHERE id = ?', [$id]);
            if (!$current) {
                Response::error('Cycle not found', 404);
            }

            $statement = $pdo->prepare('UPDATE academic_cycles SET name = ?, year = ?, period_name = ?, start_date = ?, end_date = ?, status = ? WHERE id = ?');
            $statement->execute([
                $data['name'] ?? $current['name'],
                isset($data['year']) ? (int) $data['year'] : $current['year'],
                $data['period_name'] ?? $current['period_name'],
                $data['start_date'] ?? $current['start_date'],
                $data['end_date'] ?? $current['end_date'],
                $data['status'] ?? $current['status'],
                $id,
            ]);

            Response::json(['data' => $this->fetchOne('SELECT * FROM academic_cycles WHERE id = ?', [$id])]);
        }

        if ($method === 'DELETE') {
            $pdo->prepare('DELETE FROM academic_cycles WHERE id = ?')->execute([$id]);
            Response::json(['message' => 'Cycle deleted']);
        }

        Response::error('Method not allowed', 405);
    }

    private function handleUsers(string $method, array $segments, array $user): void
    {
        $this->requireAnyRole($user, ['administrador']);
        $pdo = Database::pdo();

        if ($method === 'GET' && count($segments) === 1) {
            Response::json(['data' => $this->getUsersWithRoles()]);
        }

        if ($method === 'POST' && count($segments) === 1) {
            $data = $this->body();
            $this->validateRequired($data, ['full_name', 'email', 'password', 'roles']);

            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, phone, area, avatar_url, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $statement->execute([
                    $data['full_name'],
                    $data['email'],
                    password_hash((string) $data['password'], PASSWORD_DEFAULT),
                    $data['phone'] ?? null,
                    $data['area'] ?? null,
                    $data['avatar_url'] ?? null,
                    array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : 1,
                ]);

                $userId = (int) $pdo->lastInsertId();
                $this->syncUserRoles($userId, (array) $data['roles']);
                $pdo->commit();
            } catch (\Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }

            Response::json(['data' => $this->getUserById((int) $pdo->lastInsertId())], 201);
        }

        $id = isset($segments[1]) ? (int) $segments[1] : 0;
        if ($id <= 0) {
            Response::error('Invalid user id', 422);
        }

        if ($method === 'GET') {
            $item = $this->getUserById($id);
            if (!$item) {
                Response::error('User not found', 404);
            }
            Response::json(['data' => $item]);
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            $data = $this->body();
            $current = $this->getUserById($id);
            if (!$current) {
                Response::error('User not found', 404);
            }

            $roles = null;
            if (array_key_exists('roles', $data)) {
                $roles = array_values(array_filter(array_map(
                    static fn (mixed $role): string => trim((string) $role),
                    (array) ($data['roles'] ?? [])
                )));

                if ($roles === []) {
                    Response::error('At least one role is required', 422);
                }
            }

            if ($id === (int) $user['id']) {
                if (array_key_exists('is_active', $data) && !(int) $data['is_active']) {
                    Response::json(['error' => 'No puedes desactivar tu propia cuenta'], 403);
                }
                if (array_key_exists('roles', $data)) {
                    Response::json(['error' => 'No puedes cambiar tus propios roles desde aquí'], 403);
                }
            }

            $pdo->beginTransaction();
            try {
                $passwordHash = null;
                if (array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '') {
                    $passwordHash = password_hash((string) $data['password'], PASSWORD_DEFAULT);
                }

                $statement = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, area = ?, avatar_url = ?, is_active = ?, password_hash = COALESCE(?, password_hash) WHERE id = ?');
                $statement->execute([
                    $data['full_name'] ?? $current['full_name'],
                    $data['email'] ?? $current['email'],
                    array_key_exists('phone', $data) ? $data['phone'] : $current['phone'],
                    array_key_exists('area', $data) ? $data['area'] : $current['area'],
                    array_key_exists('avatar_url', $data) ? $data['avatar_url'] : $current['avatar_url'],
                    array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : (int) $current['is_active'],
                    $passwordHash,
                    $id,
                ]);

                if ($roles !== null) {
                    $this->syncUserRoles($id, $roles);
                }

                $pdo->commit();
            } catch (\Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }

            Response::json(['data' => $this->getUserById($id)]);
        }

        if ($method === 'DELETE') {
            if ($id === (int) $user['id']) {
                Response::json(['error' => 'No puedes desactivar tu propia cuenta'], 403);
            }

            $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
            Response::json(['message' => 'User deactivated']);
        }

        Response::error('Method not allowed', 405);
    }

    private function handleDocuments(string $method, array $segments, array $user): void
    {
        $pdo = Database::pdo();

        if ($method === 'GET' && count($segments) === 1) {
            $sql = 'SELECT d.*, f.form_code, f.title AS form_title, ac.name AS cycle_name, u.full_name AS uploaded_by_name
                    FROM documents d
                    JOIN forms f ON f.id = d.form_id
                    LEFT JOIN academic_cycles ac ON ac.id = d.cycle_id
                    JOIN users u ON u.id = d.uploaded_by';
            $params = [];
            $filters = [];

            if (!empty($_GET['status'])) {
                $filters[] = 'd.status = ?';
                $params[] = $_GET['status'];
            }
            if (!empty($_GET['cycle_id'])) {
                $filters[] = 'd.cycle_id = ?';
                $params[] = (int) $_GET['cycle_id'];
            }
            if (!empty($_GET['form_id'])) {
                $filters[] = 'd.form_id = ?';
                $params[] = (int) $_GET['form_id'];
            }
            if (!empty($_GET['uploaded_by'])) {
                $filters[] = 'd.uploaded_by = ?';
                $params[] = (int) $_GET['uploaded_by'];
            }

            if ($filters) {
                $sql .= ' WHERE ' . implode(' AND ', $filters);
            }

            $sql .= ' ORDER BY d.submitted_at DESC';
            Response::json(['data' => $this->fetchAll($sql, $params)]);
        }

        if ($method === 'POST' && count($segments) === 1) {
            $data = $this->body();
            $this->validateRequired($data, ['form_id', 'title']);

            if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                Response::error('PDF file is required', 422);
            }

            $formId = (int) $data['form_id'];
            $uploadDir = __DIR__ . '/../storage/uploads/documents/' . date('Y/m');
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                Response::error('Unable to create upload directory', 500);
            }

            $originalName = basename((string) $_FILES['file']['name']);
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
            $storedName = uniqid('doc_', true) . '_' . $safeName;
            $storedPath = $uploadDir . '/' . $storedName;

            if (!move_uploaded_file($_FILES['file']['tmp_name'], $storedPath)) {
                Response::error('Unable to save uploaded file', 500);
            }

            $relativePath = 'storage/uploads/documents/' . date('Y/m') . '/' . $storedName;
            $statement = $pdo->prepare('INSERT INTO documents (form_id, cycle_id, uploaded_by, assigned_reviewer_id, title, apartado_label, plan, carrera_label, materia, parcial, group_id, file_path, mime_type, file_size_bytes, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $statement->execute([
                $formId,
                $data['cycle_id'] ?? null,
                $user['id'],
                $data['assigned_reviewer_id'] ?? null,
                $data['title'],
                $data['apartado_label'] ?? null,
                $data['plan'] ?? null,
                $data['carrera_label'] ?? null,
                $data['materia'] ?? null,
                $data['parcial'] ?? null,
                $data['group_id'] ?? null,
                $relativePath,
                $_FILES['file']['type'] ?? null,
                (int) ($_FILES['file']['size'] ?? 0),
                'pendiente',
            ]);

            $documentId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO document_status_history (document_id, action, action_by, notes) VALUES (?, ?, ?, ?)')
                ->execute([$documentId, 'enviado', $user['id'], 'Documento enviado desde la API']);

            Response::json(['data' => $this->fetchOne('SELECT * FROM documents WHERE id = ?', [$documentId])], 201);
        }

        $id = isset($segments[1]) ? (int) $segments[1] : 0;
        if ($id <= 0) {
            Response::error('Invalid document id', 422);
        }

        if (($segments[2] ?? '') === 'history' && $method === 'GET') {
            Response::json(['data' => $this->fetchAll('SELECT * FROM document_status_history WHERE document_id = ? ORDER BY created_at DESC', [$id])]);
        }

        if (($segments[2] ?? '') === 'review' && ($method === 'PATCH' || $method === 'PUT')) {
            $this->requireAnyRole($user, ['administrador']);
            $data = $this->body();
            $status = $data['status'] ?? 'revisado';
            if (!in_array($status, ['revisado', 'devuelto'], true)) {
                Response::error('Invalid review status', 422);
            }

            $pdo->prepare('UPDATE documents SET status = ?, reviewed_at = NOW(), returned_at = CASE WHEN ? = "devuelto" THEN NOW() ELSE returned_at END WHERE id = ?')
                ->execute([$status, $status, $id]);

            $pdo->prepare('INSERT INTO document_status_history (document_id, action, action_by, notes) VALUES (?, ?, ?, ?)')
                ->execute([$id, $status, $user['id'], $data['notes'] ?? null]);

            Response::json(['data' => $this->fetchOne('SELECT * FROM documents WHERE id = ?', [$id])]);
        }

        if (($segments[2] ?? '') === 'return' && ($method === 'PATCH' || $method === 'PUT')) {
            $this->requireAnyRole($user, ['administrador']);
            $pdo->prepare('UPDATE documents SET status = "devuelto", returned_at = NOW() WHERE id = ?')->execute([$id]);
            $pdo->prepare('INSERT INTO document_status_history (document_id, action, action_by, notes) VALUES (?, ?, ?, ?)')
                ->execute([$id, 'devuelto', $user['id'], 'Documento devuelto']);
            Response::json(['data' => $this->fetchOne('SELECT * FROM documents WHERE id = ?', [$id])]);
        }

        if ($method === 'GET') {
            $doc = $this->fetchOne('SELECT * FROM documents WHERE id = ?', [$id]);
            if (!$doc) {
                Response::error('Document not found', 404);
            }
            Response::json(['data' => $doc]);
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            $data = $this->body();
            $current = $this->fetchOne('SELECT * FROM documents WHERE id = ?', [$id]);
            if (!$current) {
                Response::error('Document not found', 404);
            }

            $statement = $pdo->prepare('UPDATE documents SET title = ?, apartado_label = ?, plan = ?, carrera_label = ?, materia = ?, parcial = ?, group_id = ?, cycle_id = ? WHERE id = ?');
            $statement->execute([
                $data['title'] ?? $current['title'],
                array_key_exists('apartado_label', $data) ? $data['apartado_label'] : $current['apartado_label'],
                array_key_exists('plan', $data) ? $data['plan'] : $current['plan'],
                array_key_exists('carrera_label', $data) ? $data['carrera_label'] : $current['carrera_label'],
                array_key_exists('materia', $data) ? $data['materia'] : $current['materia'],
                array_key_exists('parcial', $data) ? $data['parcial'] : $current['parcial'],
                array_key_exists('group_id', $data) ? $data['group_id'] : $current['group_id'],
                array_key_exists('cycle_id', $data) ? $data['cycle_id'] : $current['cycle_id'],
                $id,
            ]);

            Response::json(['data' => $this->fetchOne('SELECT * FROM documents WHERE id = ?', [$id])]);
        }

        if ($method === 'DELETE') {
            $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
            Response::json(['message' => 'Document deleted']);
        }

        Response::error('Method not allowed', 405);
    }

    private function handleConversations(string $method, array $segments, array $user): void
    {
        $pdo = Database::pdo();
        $currentUserId = (int) $user['id'];

        if ($method === 'GET' && count($segments) === 1) {
            $rows = $this->loadUserConversations($currentUserId);
            Response::json(['data' => $rows]);
        }

        if ($method === 'POST' && count($segments) === 1) {
            $data = $this->body();
            $participants = array_filter(array_map('intval', (array) ($data['participant_user_ids'] ?? [])));

            if (isset($data['recipient_user_id'])) {
                $participants[] = (int) $data['recipient_user_id'];
            }

            $participants[] = $currentUserId;
            $participants = array_values(array_unique(array_filter($participants)));

            if (count($participants) !== 2) {
                Response::error('Solo se permiten conversaciones entre dos participantes', 422);
            }

            $users = $this->fetchAll(
                'SELECT u.id, u.full_name, r.code AS role_code FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id WHERE u.id IN (?, ?)',
                [$participants[0], $participants[1]]
            );

            if (count($users) !== 2) {
                Response::error('Usuarios inválidos para la conversación', 422);
            }

            $roleA = $users[0]['role_code'] ?? 'docente';
            $roleB = $users[1]['role_code'] ?? 'docente';
            if ($roleA === $roleB) {
                Response::error('Las conversaciones solo están permitidas entre Administrador y Docente', 422);
            }

            $pair = [$roleA, $roleB];
            sort($pair);
            if ($pair !== ['administrador', 'docente']) {
                Response::error('Pareja de roles no permitida para conversaciones', 422);
            }

            $existingId = $this->findDirectConversationId($participants[0], $participants[1]);
            if ($existingId !== null) {
                Response::json(['data' => $this->formatConversation($existingId, $currentUserId)]);
            }

            $conversationId = $this->createDirectConversation($participants[0], $participants[1]);
            Response::json(['data' => $this->formatConversation($conversationId, $currentUserId)], 201);
        }

        $conversationId = isset($segments[1]) ? (int) $segments[1] : 0;
        if ($conversationId <= 0) {
            Response::error('Invalid conversation id', 422);
        }

        if (($segments[2] ?? '') === 'messages' && $method === 'GET') {
            $this->requireConversationAccess($conversationId, $currentUserId);
            Response::json(['data' => $this->fetchConversationMessages($conversationId, $currentUserId)]);
        }

        if (($segments[2] ?? '') === 'messages' && $method === 'POST') {
            $data = $this->body();
            $this->validateRequired($data, ['body']);
            $this->requireConversationAccess($conversationId, $currentUserId);
            $statement = $pdo->prepare('INSERT INTO messages (conversation_id, sender_user_id, body, reply_to_message_id) VALUES (?, ?, ?, ?)');
            $statement->execute([$conversationId, $currentUserId, $data['body'], $data['reply_to_message_id'] ?? null]);

            $pdo->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = ?')->execute([$conversationId]);
            $pdo->prepare('UPDATE conversation_participants SET unread_count = unread_count + 1 WHERE conversation_id = ? AND user_id <> ?')->execute([$conversationId, $currentUserId]);

            Response::json(['data' => $this->fetchConversationMessageById((int) $pdo->lastInsertId(), $currentUserId)], 201);
        }

        if (($segments[2] ?? '') === 'messages' && isset($segments[3]) && is_numeric($segments[3])) {
            $messageId = (int) $segments[3];
            $this->requireConversationAccess($conversationId, $currentUserId);

            if ($method === 'DELETE') {
                $message = $this->fetchMessageById($messageId, $conversationId);
                if (!$message) {
                    Response::error('Message not found', 404);
                }

                $this->ensureMessageDeletable($message, $currentUserId);
                $pdo->prepare('DELETE FROM messages WHERE id = ? AND conversation_id = ?')->execute([$messageId, $conversationId]);
                Response::json(['message' => 'Message deleted']);
            }

            if ($method === 'PATCH' || $method === 'PUT') {
                $data = $this->body();
                $this->validateRequired($data, ['body']);

                $message = $this->fetchMessageById($messageId, $conversationId);
                if (!$message) {
                    Response::error('Message not found', 404);
                }

                $this->ensureMessageEditable($message, $currentUserId);
                $pdo->prepare('UPDATE messages SET body = ? WHERE id = ? AND conversation_id = ?')->execute([(string) $data['body'], $messageId, $conversationId]);

                Response::json(['data' => $this->fetchConversationMessageById($messageId, $currentUserId)]);
            }
        }

        if (($segments[2] ?? '') === 'read' && $method === 'PATCH') {
            $this->requireConversationAccess($conversationId, $currentUserId);
            $pdo->prepare('UPDATE conversation_participants SET unread_count = 0, last_read_at = NOW() WHERE conversation_id = ? AND user_id = ?')->execute([$conversationId, $currentUserId]);
            Response::json(['message' => 'Conversation marked as read']);
        }

        Response::error('Method not allowed', 405);
    }

    private function handleCalendar(string $method, array $segments, array $user): void
    {
        $pdo = Database::pdo();

        if ($method === 'POST' && count($segments) === 1) {
            $this->requireAnyRole($user, ['administrador']);

            if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                Response::error('PDF file is required', 422);
            }

            $originalName = basename((string) $_FILES['file']['name']);
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName) ?: 'calendario.pdf';
            if (strtolower(pathinfo($safeName, PATHINFO_EXTENSION)) !== 'pdf') {
                $safeName .= '.pdf';
            }

            $uploadDir = __DIR__ . '/../storage/uploads/calendar/' . date('Y/m');
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                Response::error('Unable to create upload directory', 500);
            }

            $storedName = uniqid('calendar_', true) . '_' . $safeName;
            $storedPath = $uploadDir . '/' . $storedName;

            if (!move_uploaded_file($_FILES['file']['tmp_name'], $storedPath)) {
                Response::error('Unable to save uploaded file', 500);
            }

            $relativePath = 'storage/uploads/calendar/' . date('Y/m') . '/' . $storedName;

            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE calendar_files SET is_active = 0 WHERE is_active = 1')->execute();

                $statement = $pdo->prepare('INSERT INTO calendar_files (cycle_id, file_name, file_path, uploaded_by, uploaded_at, is_active) VALUES (?, ?, ?, ?, NOW(), 1)');
                $statement->execute([
                    $this->scalar("SELECT id FROM academic_cycles WHERE status = 'activo' ORDER BY id DESC LIMIT 1") ?: null,
                    $originalName,
                    $relativePath,
                    $user['id'],
                ]);

                $pdo->commit();
            } catch (\Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }

            Response::json(['data' => $this->getCurrentCalendarMeta()], 201);
        }

        if ($method === 'DELETE' && count($segments) === 1) {
            $this->requireAnyRole($user, ['administrador']);
            $pdo->prepare('UPDATE calendar_files SET is_active = 0 WHERE is_active = 1')->execute();
            Response::json(['message' => 'Calendario restaurado al archivo base']);
        }

        Response::error('Method not allowed', 405);
    }

    private function getCurrentCalendarMeta(): array
    {
        $current = $this->fetchOne('SELECT * FROM calendar_files WHERE is_active = 1 ORDER BY uploaded_at DESC, id DESC LIMIT 1');

        if (!$current) {
            return [
                'id' => null,
                'file_name' => 'Calendario25-26.pdf',
                'file_url' => '/api/calendar/file',
                'uploaded_at' => null,
                'is_active' => false,
            ];
        }

        return [
            'id' => (int) $current['id'],
            'file_name' => $current['file_name'],
            'file_url' => '/api/calendar/file',
            'uploaded_at' => $current['uploaded_at'],
            'is_active' => (bool) $current['is_active'],
        ];
    }

    private function streamCurrentCalendarFile(): never
    {
        $current = $this->fetchOne('SELECT * FROM calendar_files WHERE is_active = 1 ORDER BY uploaded_at DESC, id DESC LIMIT 1');
        $absolutePath = $this->resolveCalendarAbsolutePath($current['file_path'] ?? null);
        $fileName = $current['file_name'] ?? 'Calendario25-26.pdf';
        $isDownload = isset($_GET['download']) && (string) $_GET['download'] === '1';

        if (!is_file($absolutePath)) {
            Response::error('Calendar file not found', 404);
        }

        http_response_code(200);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($isDownload ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', (string) $fileName) . '"');
        header('Content-Length: ' . (string) filesize($absolutePath));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Access-Control-Allow-Origin: ' . Config::allowedFrontendOrigin());
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        readfile($absolutePath);
        exit;
    }

    private function resolveCalendarAbsolutePath(?string $relativePath): string
    {
        if ($relativePath !== null && $relativePath !== '') {
            $storagePath = __DIR__ . '/../' . ltrim($relativePath, '/\\');
            if (is_file($storagePath)) {
                return $storagePath;
            }
        }

        return __DIR__ . '/../../UT/src/assets/Calendario25-26.pdf';
    }

    private function dashboardStats(): void
    {
        Response::json([
            'users_total' => (int) $this->scalar('SELECT COUNT(*) FROM users'),
            'documents_total' => (int) $this->scalar('SELECT COUNT(*) FROM documents'),
            'documents_pending' => (int) $this->scalar("SELECT COUNT(*) FROM documents WHERE status = 'pendiente'"),
            'documents_reviewed' => (int) $this->scalar("SELECT COUNT(*) FROM documents WHERE status = 'revisado'"),
            'messages_total' => (int) $this->scalar('SELECT COUNT(*) FROM messages'),
            'active_cycle' => $this->fetchOne("SELECT * FROM academic_cycles WHERE status = 'activo' ORDER BY id DESC LIMIT 1"),
        ]);
    }

    private function login(): void
    {
        $data = $this->body();
        $this->validateRequired($data, ['email', 'password']);

        $user = $this->fetchOne('SELECT * FROM users WHERE email = ? LIMIT 1', [$data['email']]);
        if (!$user || !(bool) $user['is_active'] || !password_verify((string) $data['password'], (string) $user['password_hash'])) {
            Response::error('Credenciales invalidas', 422);
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = password_hash($token, PASSWORD_DEFAULT);
        $expiresAt = (new DateTimeImmutable())->add(new DateInterval('PT12H'))->format('Y-m-d H:i:s');

        $pdo = Database::pdo();
        $statement = $pdo->prepare('INSERT INTO api_tokens (user_id, token_hash, name, expires_at, created_at, last_used_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
        $statement->execute([(int) $user['id'], $tokenHash, 'web', $expiresAt]);

        Response::json([
            'token' => $token,
            'user' => $this->getUserById((int) $user['id']),
        ]);
    }

    private function logout(): void
    {
        $token = $this->bearerToken();
        if ($token !== null) {
            $pdo = Database::pdo();
            $tokens = $pdo->query('SELECT id, token_hash FROM api_tokens')->fetchAll();
            foreach ($tokens as $stored) {
                if (password_verify($token, (string) $stored['token_hash'])) {
                    $pdo->prepare('DELETE FROM api_tokens WHERE id = ?')->execute([(int) $stored['id']]);
                    break;
                }
            }
        }

        Response::json(['message' => 'Sesion cerrada']);
    }

    private function forgotPassword(): void
    {
        $data = $this->body();
        $this->validateRequired($data, ['email']);

        $user = $this->fetchOne('SELECT id, email FROM users WHERE email = ? LIMIT 1', [$data['email']]);
        if (!$user) {
            Response::json(['message' => 'Si el correo existe, se genero un token de recuperacion.']);
        }

        $token = bin2hex(random_bytes(32));
        $hash = password_hash($token, PASSWORD_DEFAULT);
        $expiresAt = (new DateTimeImmutable())->add(new DateInterval('PT30M'))->format('Y-m-d H:i:s');

        $pdo = Database::pdo();
        $statement = $pdo->prepare('INSERT INTO password_reset_tokens (email, token_hash, expires_at) VALUES (?, ?, ?)');
        $statement->execute([$user['email'], $hash, $expiresAt]);

        Response::json([
            'message' => 'Token de recuperacion generado.',
            'reset_token' => $token,
        ]);
    }

    private function resetPassword(): void
    {
        $data = $this->body();
        $this->validateRequired($data, ['email', 'token', 'password']);

        if (($data['password_confirmation'] ?? null) !== null && (string) $data['password_confirmation'] !== (string) $data['password']) {
            Response::error('La confirmacion de contrasena no coincide', 422);
        }

        $token = $this->fetchOne('SELECT * FROM password_reset_tokens WHERE email = ? LIMIT 1', [$data['email']]);
        if (!$token || strtotime((string) $token['expires_at']) < time() || !password_verify((string) $data['token'], (string) $token['token_hash'])) {
            Response::error('El token es invalido o expiro', 422);
        }

        $pdo = Database::pdo();
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?')->execute([
            password_hash((string) $data['password'], PASSWORD_DEFAULT),
            $data['email'],
        ]);
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE email = ?')->execute([$data['email']]);

        Response::json(['message' => 'Contrasena actualizada correctamente']);
    }

    private function requireUser(): array
    {
        $token = $this->bearerToken();
        if ($token === null) {
            Response::error('No autenticado', 401);
        }

        $pdo = Database::pdo();
        $tokens = $pdo->query('SELECT * FROM api_tokens ORDER BY id DESC')->fetchAll();

        foreach ($tokens as $stored) {
            if (!empty($stored['expires_at']) && strtotime((string) $stored['expires_at']) < time()) {
                continue;
            }

            if (password_verify($token, (string) $stored['token_hash'])) {
                $pdo->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([(int) $stored['id']]);
                return $this->getUserById((int) $stored['user_id']);
            }
        }

        Response::error('No autenticado', 401);
    }

    private function requireAnyRole(array $user, array $roles): void
    {
        $userRoles = array_map(static fn (array $role): string => (string) $role['code'], $this->getRolesByUserId((int) $user['id']));
        foreach ($roles as $role) {
            if (in_array($role, $userRoles, true)) {
                return;
            }
        }

        Response::error('No autorizado', 403);
    }

    private function getPrimaryRoleCode(int $userId): string
    {
        $role = $this->fetchOne(
            'SELECT r.code AS role_code FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ? ORDER BY r.id ASC LIMIT 1',
            [$userId]
        );

        return (string) ($role['role_code'] ?? 'docente');
    }

    private function loadUserConversations(int $userId): array
    {
        $conversationIds = $this->fetchAll(
            'SELECT c.id FROM conversations c JOIN conversation_participants cp ON cp.conversation_id = c.id WHERE cp.user_id = ? ORDER BY c.updated_at DESC, c.id DESC',
            [$userId]
        );

        $rows = [];
        foreach ($conversationIds as $row) {
            $formatted = $this->formatConversation((int) $row['id'], $userId);
            if ($formatted !== null) {
                $rows[] = $formatted;
            }
        }

        return $rows;
    }

    private function findDirectConversationId(int $userAId, int $userBId): ?int
    {
        $conversation = $this->fetchOne(
            'SELECT cp.conversation_id AS id FROM conversation_participants cp WHERE cp.user_id IN (?, ?) GROUP BY cp.conversation_id HAVING COUNT(DISTINCT cp.user_id) = 2 LIMIT 1',
            [$userAId, $userBId]
        );

        return $conversation ? (int) $conversation['id'] : null;
    }

    private function createDirectConversation(int $userAId, int $userBId): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $pdo->exec('INSERT INTO conversations () VALUES ()');
            $conversationId = (int) $pdo->lastInsertId();

            $insert = $pdo->prepare('INSERT INTO conversation_participants (conversation_id, user_id, unread_count, last_read_at) VALUES (?, ?, 0, NULL)');
            $insert->execute([$conversationId, $userAId]);
            $insert->execute([$conversationId, $userBId]);

            $pdo->commit();

            return $conversationId;
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    private function requireConversationAccess(int $conversationId, int $currentUserId): void
    {
        $participants = $this->fetchAll(
            'SELECT u.id, r.code AS role_code FROM conversation_participants cp JOIN users u ON u.id = cp.user_id LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id WHERE cp.conversation_id = ? ORDER BY u.id ASC',
            [$conversationId]
        );

        if (count($participants) !== 2) {
            Response::error('Acceso denegado a esta conversación', 403);
        }

        $participantIds = array_map(static fn (array $participant): int => (int) $participant['id'], $participants);
        if (!in_array($currentUserId, $participantIds, true)) {
            Response::error('Acceso denegado a esta conversación', 403);
        }

        $roles = array_map(static fn (array $participant): string => (string) ($participant['role_code'] ?? 'docente'), $participants);
        sort($roles);
        if ($roles !== ['administrador', 'docente']) {
            Response::error('Acceso denegado a esta conversación', 403);
        }
    }

    private function formatConversation(int $conversationId, int $currentUserId): ?array
    {
        $conversation = $this->fetchOne('SELECT * FROM conversations WHERE id = ? LIMIT 1', [$conversationId]);
        if (!$conversation) {
            return null;
        }

        $participants = $this->fetchAll(
            'SELECT u.id, u.full_name, u.avatar_url, r.code AS role_code, cp.unread_count FROM conversation_participants cp JOIN users u ON u.id = cp.user_id LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id WHERE cp.conversation_id = ? ORDER BY u.id ASC',
            [$conversationId]
        );

        if (count($participants) !== 2) {
            return null;
        }

        $currentParticipant = null;
        $otherParticipant = null;
        foreach ($participants as $participant) {
            if ((int) $participant['id'] === $currentUserId) {
                $currentParticipant = $participant;
            } else {
                $otherParticipant = $participant;
            }
        }

        if ($currentParticipant === null || $otherParticipant === null) {
            return null;
        }

        $latestMessage = $this->fetchOne(
            'SELECT m.body, m.created_at, u.full_name AS sender_name FROM messages m JOIN users u ON u.id = m.sender_user_id WHERE m.conversation_id = ? ORDER BY m.created_at DESC, m.id DESC LIMIT 1',
            [$conversationId]
        );

        $roleLabel = match ((string) ($otherParticipant['role_code'] ?? 'docente')) {
            'administrador' => 'Administrador',
            'tutor' => 'Tutor',
            default => 'Docente',
        };

        return [
            'id' => (int) $conversation['id'],
            'name' => (string) $otherParticipant['full_name'],
            'role' => $roleLabel,
            'lastMessage' => (string) ($latestMessage['body'] ?? 'Nuevo chat'),
            'timestamp' => (string) ($latestMessage['created_at'] ?? $conversation['updated_at'] ?? ''),
            'unread' => (int) ($currentParticipant['unread_count'] ?? 0),
            'avatar' => $this->buildAvatar((string) $otherParticipant['full_name']),
            'status' => 'offline',
            'participants' => array_map(static function (array $participant): array {
                return [
                    'id' => (int) $participant['id'],
                    'name' => (string) $participant['full_name'],
                    'role' => (string) ($participant['role_code'] ?? 'docente'),
                ];
            }, $participants),
            'lastMessageAt' => (string) ($latestMessage['created_at'] ?? $conversation['updated_at'] ?? ''),
        ];
    }

    private function fetchConversationMessages(int $conversationId, int $currentUserId): array
    {
        $messages = $this->fetchAll(
            'SELECT m.id, m.body, m.reply_to_message_id, m.sender_user_id, m.created_at, sender.full_name AS sender_name, reply_sender.full_name AS reply_sender_name, reply_message.body AS reply_body FROM messages m JOIN users sender ON sender.id = m.sender_user_id LEFT JOIN messages reply_message ON reply_message.id = m.reply_to_message_id LEFT JOIN users reply_sender ON reply_sender.id = reply_message.sender_user_id WHERE m.conversation_id = ? ORDER BY m.created_at ASC, m.id ASC',
            [$conversationId]
        );

        return array_map(static function (array $message) use ($currentUserId): array {
            return [
                'id' => (int) $message['id'],
                'sender' => (string) ($message['sender_name'] ?? 'Usuario'),
                'content' => (string) ($message['body'] ?? ''),
                'timestamp' => (string) ($message['created_at'] ?? ''),
                'isOwn' => (int) ($message['sender_user_id'] ?? 0) === $currentUserId,
                'attachments' => [],
                'replyTo' => !empty($message['reply_to_message_id']) ? [
                    'id' => (int) $message['reply_to_message_id'],
                    'sender' => (string) ($message['reply_sender_name'] ?? 'Usuario'),
                    'content' => (string) ($message['reply_body'] ?? ''),
                ] : null,
            ];
        }, $messages);
    }

    private function fetchConversationMessageById(int $messageId, int $currentUserId): ?array
    {
        $message = $this->fetchOne(
            'SELECT m.id, m.body, m.reply_to_message_id, m.created_at, m.sender_user_id, sender.full_name AS sender_name, reply_sender.full_name AS reply_sender_name, reply_message.body AS reply_body FROM messages m JOIN users sender ON sender.id = m.sender_user_id LEFT JOIN messages reply_message ON reply_message.id = m.reply_to_message_id LEFT JOIN users reply_sender ON reply_sender.id = reply_message.sender_user_id WHERE m.id = ? LIMIT 1',
            [$messageId]
        );

        if (!$message) {
            return null;
        }

        return [
            'id' => (int) $message['id'],
            'sender' => (string) ($message['sender_name'] ?? 'Usuario'),
            'content' => (string) ($message['body'] ?? ''),
            'timestamp' => (string) ($message['created_at'] ?? ''),
            'isOwn' => (int) ($message['sender_user_id'] ?? 0) === $currentUserId,
            'attachments' => [],
            'replyTo' => !empty($message['reply_to_message_id']) ? [
                'id' => (int) $message['reply_to_message_id'],
                'sender' => (string) ($message['reply_sender_name'] ?? 'Usuario'),
                'content' => (string) ($message['reply_body'] ?? ''),
            ] : null,
        ];
    }

    private function fetchMessageById(int $messageId, int $conversationId): ?array
    {
        return $this->fetchOne(
            'SELECT m.id, m.body, m.reply_to_message_id, m.sender_user_id, m.created_at FROM messages m WHERE m.id = ? AND m.conversation_id = ? LIMIT 1',
            [$messageId, $conversationId]
        );
    }

    private function ensureMessageDeletable(array $message, int $currentUserId): void
    {
        if ((int) ($message['sender_user_id'] ?? 0) !== $currentUserId) {
            Response::error('No autorizado para eliminar este mensaje', 403);
        }
    }

    private function ensureMessageEditable(array $message, int $currentUserId): void
    {
        $this->ensureMessageDeletable($message, $currentUserId);

        $ageSeconds = (int) $this->scalar(
            'SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM messages WHERE id = ? LIMIT 1',
            [(int) ($message['id'] ?? 0)]
        );

        if ($ageSeconds < 0 || $ageSeconds > 300) {
            Response::error('No puedes editar este mensaje', 403);
        }
    }

    private function buildAvatar(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';

        foreach (array_slice(array_values(array_filter($parts)), 0, 2) as $part) {
            $initials .= strtoupper(substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : 'CH';
    }

    private function getUsersWithRoles(): array
    {
        $users = $this->fetchAll('SELECT id, full_name, email, phone, area, avatar_url, is_active, created_at, updated_at, (SELECT COUNT(*) FROM documents d WHERE d.uploaded_by = users.id) AS documents_count FROM users ORDER BY full_name');
        foreach ($users as &$user) {
            $user['roles'] = array_map(static fn (array $role): array => ['id' => (int) $role['id'], 'code' => $role['code'], 'name' => $role['name']], $this->getRolesByUserId((int) $user['id']));
        }

        return $users;
    }

    private function getUserById(int $userId): ?array
    {
        $user = $this->fetchOne('SELECT id, full_name, email, phone, area, avatar_url, is_active, created_at, updated_at, (SELECT COUNT(*) FROM documents d WHERE d.uploaded_by = users.id) AS documents_count FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return null;
        }

        $user['roles'] = array_map(static fn (array $role): array => ['id' => (int) $role['id'], 'code' => $role['code'], 'name' => $role['name']], $this->getRolesByUserId($userId));

        return $user;
    }

    private function getRolesByUserId(int $userId): array
    {
        return $this->fetchAll('SELECT r.id, r.code, r.name FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ? ORDER BY r.code', [$userId]);
    }

    private function syncUserRoles(int $userId, array $roles): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$userId]);
        $statement = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = ? LIMIT 1');
        foreach ($roles as $role) {
            $statement->execute([$userId, (string) $role]);
        }
    }

    private function body(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            return is_array($decoded) ? $decoded : [];
        }

        if (!empty($_POST)) {
            return $_POST;
        }

        parse_str((string) file_get_contents('php://input'), $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    private function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $header, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private function validateRequired(array $data, array $fields): void
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }

        if ($missing) {
            Response::error('Validation failed', 422, ['missing' => $missing]);
        }
    }

    private function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    private function fetchAll(string $sql, array $params = []): array
    {
        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    private function storageGroupsPath(): string
    {
        return __DIR__ . '/../storage/groups.json';
    }

    private function loadGroups(): array
    {
        $path = $this->storageGroupsPath();
        if (!is_file($path)) return [];
        $raw = file_get_contents($path);
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }

    private function saveGroups(array $groups): void
    {
        $path = $this->storageGroupsPath();
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode(array_values($groups), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }
}

final class ApiException extends \RuntimeException
{
    private int $statusCode;
    private array $details;

    public function __construct(string $message, int $statusCode = 400, array $details = [])
    {
        $this->statusCode = $statusCode;
        $this->details = $details;
        parent::__construct($message, $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}

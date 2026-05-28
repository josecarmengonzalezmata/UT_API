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

        Response::error('Not found', 404);
    }

    private function handleForms(string $method, array $segments, array $user): void
    {
        $this->requireAnyRole($user, ['administrador']);

        $pdo = Database::pdo();

        if ($method === 'GET' && count($segments) === 1) {
            $statement = $pdo->query('SELECT * FROM forms ORDER BY section, title');
            Response::json(['data' => $statement->fetchAll()]);
        }

        $id = isset($segments[1]) ? (int) $segments[1] : 0;
        if ($id <= 0) {
            Response::error('Invalid form id', 422);
        }

        if ($method === 'GET') {
            $statement = $pdo->prepare('SELECT * FROM forms WHERE id = ? LIMIT 1');
            $statement->execute([$id]);
            $form = $statement->fetch();
            if (!$form) {
                Response::error('Form not found', 404);
            }
            Response::json(['data' => $form]);
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            $data = $this->body();
            $statement = $pdo->prepare('UPDATE forms SET title = COALESCE(:title, title), section = COALESCE(:section, section), description = COALESCE(:description, description), is_active = COALESCE(:is_active, is_active) WHERE id = :id');
            $statement->execute([
                'id' => $id,
                'title' => $data['title'] ?? null,
                'section' => $data['section'] ?? null,
                'description' => $data['description'] ?? null,
                'is_active' => array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : null,
            ]);
            Response::json(['data' => $this->fetchOne('SELECT * FROM forms WHERE id = ?', [$id])]);
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

            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, area = ?, avatar_url = ?, is_active = ?, password_hash = COALESCE(?, password_hash) WHERE id = ?');
                $statement->execute([
                    $data['full_name'] ?? $current['full_name'],
                    $data['email'] ?? $current['email'],
                    array_key_exists('phone', $data) ? $data['phone'] : $current['phone'],
                    array_key_exists('area', $data) ? $data['area'] : $current['area'],
                    array_key_exists('avatar_url', $data) ? $data['avatar_url'] : $current['avatar_url'],
                    array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : (int) $current['is_active'],
                    !empty($data['password']) ? password_hash((string) $data['password'], PASSWORD_DEFAULT) : null,
                    $id,
                ]);

                if (array_key_exists('roles', $data)) {
                    $this->syncUserRoles($id, (array) $data['roles']);
                }

                $pdo->commit();
            } catch (\Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }

            Response::json(['data' => $this->getUserById($id)]);
        }

        if ($method === 'DELETE') {
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

        if ($method === 'GET' && count($segments) === 1) {
            $rows = $this->fetchAll('SELECT c.*, COUNT(cp.user_id) AS participants FROM conversations c LEFT JOIN conversation_participants cp ON cp.conversation_id = c.id GROUP BY c.id ORDER BY c.updated_at DESC');
            Response::json(['data' => $rows]);
        }

        if ($method === 'POST' && count($segments) === 1) {
            $data = $this->body();
            $pdo->beginTransaction();
            try {
                $pdo->exec('INSERT INTO conversations () VALUES ()');
                $conversationId = (int) $pdo->lastInsertId();

                $participants = array_unique(array_filter(array_map('intval', (array) ($data['participants'] ?? []))));
                $participants[] = (int) $user['id'];
                $participants = array_values(array_unique($participants));

                $insert = $pdo->prepare('INSERT INTO conversation_participants (conversation_id, user_id, unread_count, last_read_at) VALUES (?, ?, 0, NULL)');
                foreach ($participants as $participantId) {
                    $insert->execute([$conversationId, $participantId]);
                }

                $pdo->commit();
            } catch (\Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }

            Response::json(['data' => $this->fetchOne('SELECT * FROM conversations WHERE id = LAST_INSERT_ID()')], 201);
        }

        $conversationId = isset($segments[1]) ? (int) $segments[1] : 0;
        if ($conversationId <= 0) {
            Response::error('Invalid conversation id', 422);
        }

        if (($segments[2] ?? '') === 'messages' && $method === 'GET') {
            Response::json(['data' => $this->fetchAll('SELECT * FROM messages WHERE conversation_id = ? ORDER BY created_at ASC', [$conversationId])]);
        }

        if (($segments[2] ?? '') === 'messages' && $method === 'POST') {
            $data = $this->body();
            $this->validateRequired($data, ['body']);
            $statement = $pdo->prepare('INSERT INTO messages (conversation_id, sender_user_id, body, reply_to_message_id) VALUES (?, ?, ?, ?)');
            $statement->execute([$conversationId, $user['id'], $data['body'], $data['reply_to_message_id'] ?? null]);

            $pdo->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = ?')->execute([$conversationId]);

            Response::json(['data' => $this->fetchOne('SELECT * FROM messages WHERE id = LAST_INSERT_ID()')], 201);
        }

        if (($segments[2] ?? '') === 'read' && $method === 'PATCH') {
            $pdo->prepare('UPDATE conversation_participants SET unread_count = 0, last_read_at = NOW() WHERE conversation_id = ? AND user_id = ?')->execute([$conversationId, $user['id']]);
            Response::json(['message' => 'Conversation marked as read']);
        }

        Response::error('Method not allowed', 405);
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
        $statement = $pdo->prepare('INSERT INTO password_reset_tokens (email, token_hash, expires_at, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expires_at = VALUES(expires_at), created_at = NOW()');
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

        if (($data['password_confirmation'] ?? null) !== null && $data['password_confirmation'] !== $data['password']) {
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

    private function getUsersWithRoles(): array
    {
        $users = $this->fetchAll('SELECT id, full_name, email, phone, area, avatar_url, is_active, created_at, updated_at FROM users ORDER BY full_name');
        foreach ($users as &$user) {
            $user['roles'] = array_map(static fn (array $role): array => ['id' => (int) $role['id'], 'code' => $role['code'], 'name' => $role['name']], $this->getRolesByUserId((int) $user['id']));
        }

        return $users;
    }

    private function getUserById(int $userId): ?array
    {
        $user = $this->fetchOne('SELECT id, full_name, email, phone, area, avatar_url, is_active, created_at, updated_at FROM users WHERE id = ?', [$userId]);
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

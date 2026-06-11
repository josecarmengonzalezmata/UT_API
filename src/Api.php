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

        if ($first === 'users' && $method === 'GET' && isset($segments[1]) && is_numeric($segments[1]) && ($segments[2] ?? '') === 'avatar') {
            $this->streamUserAvatar((int) $segments[1]);
            return;
        }

        if ($first === 'default-avatar' && $method === 'GET') {
            $this->streamDefaultAvatar();
            return;
        }

        if ($first === 'groups') {
            // public listing: if query contains career_code/cuatrimestre/cycle_id, read from DB
            if ($method === 'GET' && count($segments) === 1) {
                $careerCode = $_GET['career_code'] ?? null;
                $careerId = isset($_GET['career_id']) ? (int) $_GET['career_id'] : null;
                $cuatrimestre = isset($_GET['cuatrimestre']) ? (int) $_GET['cuatrimestre'] : null;
                $cycleId = isset($_GET['cycle_id']) ? (int) $_GET['cycle_id'] : null;

                if ($careerCode || $careerId || $cuatrimestre) {
                    $pdo = Database::pdo();
                    $sql = 'SELECT g.id, g.career_id, g.cycle_id, g.cuatrimestre, g.group_number, g.group_code FROM groups g JOIN careers c ON c.id = g.career_id WHERE 1=1';
                    $params = [];
                    if ($careerCode) {
                        $sql .= ' AND c.code = ?';
                        $params[] = strtoupper((string) $careerCode);
                    }
                    if ($careerId) {
                        $sql .= ' AND g.career_id = ?';
                        $params[] = $careerId;
                    }
                    if ($cuatrimestre) {
                        $sql .= ' AND g.cuatrimestre = ?';
                        $params[] = $cuatrimestre;
                    }
                    if ($cycleId) {
                        $sql .= ' AND g.cycle_id = ?';
                        $params[] = $cycleId;
                    }
                    $sql .= ' ORDER BY g.group_number';
                    $rows = $this->fetchAll($sql, $params);
                    foreach ($rows as &$row) {
                        if (isset($row['group_code'])) {
                            $row['group_code'] = str_replace('_', '-', (string) $row['group_code']);
                        }
                    }
                    unset($row);

                    $storedGroups = array_values(array_filter(
                        $this->loadGroups(),
                        static function (array $group) use ($careerCode, $cuatrimestre): bool {
                            if ($careerCode && strtoupper((string) ($group['careerCode'] ?? '')) !== strtoupper((string) $careerCode)) {
                                return false;
                            }

                            if ($cuatrimestre && (int) ($group['cuatrimestre'] ?? 0) !== $cuatrimestre) {
                                return false;
                            }

                            return true;
                        }
                    ));

                    $this->syncStoredGroupsToDatabase($storedGroups);

                    foreach ($storedGroups as &$group) {
                        $group['group_code'] = str_replace('_', '-', (string) ($group['group_code'] ?? $group['name'] ?? ''));
                        $group['group_number'] = (int) ($group['groupNumber'] ?? $group['group_number'] ?? 0);
                    }
                    unset($group);

                    $merged = [];
                    foreach (array_merge($rows, $storedGroups) as $group) {
                        $groupCode = strtoupper(str_replace('_', '-', (string) ($group['group_code'] ?? $group['name'] ?? '')));
                        if ($groupCode === '') {
                            $groupCode = 'GROUP-' . (string) ($group['id'] ?? count($merged) + 1);
                        }

                        if (!isset($merged[$groupCode])) {
                            $merged[$groupCode] = $group;
                        }
                    }

                    Response::json(['data' => array_values($merged)]);
                    return;
                }

                $storedGroups = $this->loadGroups();
                $this->syncStoredGroupsToDatabase($storedGroups);

                $dbGroups = $this->fetchAll('SELECT g.id, g.career_id, g.cycle_id, g.cuatrimestre, g.group_number, g.group_code, c.code AS career_code FROM groups g JOIN careers c ON c.id = g.career_id ORDER BY g.group_code');
                foreach ($dbGroups as &$row) {
                    $row['careerCode'] = strtoupper((string) ($row['career_code'] ?? ''));
                    $row['plan'] = $this->planFromCareerId((int) ($row['career_id'] ?? 0));
                    $row['groupNumber'] = (int) ($row['group_number'] ?? 0);
                    $row['name'] = str_replace('_', '-', (string) ($row['group_code'] ?? ''));
                    $row['group_code'] = str_replace('_', '-', (string) ($row['group_code'] ?? ''));
                }
                unset($row);

                $merged = [];
                foreach (array_merge($dbGroups, $storedGroups) as $group) {
                    $groupCode = strtoupper(str_replace('_', '-', (string) ($group['group_code'] ?? $group['name'] ?? '')));
                    if ($groupCode === '') {
                        $groupCode = 'GROUP-' . (string) ($group['id'] ?? count($merged) + 1);
                    }

                    if (!isset($merged[$groupCode])) {
                        $merged[$groupCode] = $group;
                    }
                }

                Response::json(['data' => array_values($merged)]);
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
                
                $maxId = 0;
                foreach ($groups as $g) {
                    if ((int) $g['id'] > $maxId) $maxId = (int) $g['id'];
                }
                $id = $maxId + 1;
                
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
                $this->syncDatabaseGroup($g);
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
                $updatedGroup = null;
                $previousGroup = null;
                foreach ($groups as &$g) {
                    if ((int)$g['id'] === $id) {
                        $found = true;
                        $previousGroup = $g;
                        if (isset($data['careerCode'])) $g['careerCode'] = strtoupper((string)$data['careerCode']);
                        if (isset($data['plan'])) $g['plan'] = (string)$data['plan'];
                        if (isset($data['cuatrimestre'])) $g['cuatrimestre'] = (int)$data['cuatrimestre'];
                        if (isset($data['groupNumber'])) $g['groupNumber'] = (int)$data['groupNumber'];
                        $g['name'] = $g['careerCode'] . $g['cuatrimestre'] . '-' . $g['groupNumber'];
                        $updatedGroup = $g;
                        break;
                    }
                }
                unset($g);
                if (!$found) Response::error('Not found', 404);
                $this->saveGroups($groups);
                if (is_array($updatedGroup) && is_array($previousGroup)) {
                    $this->syncDatabaseGroup($updatedGroup, $previousGroup);
                }
                foreach ($groups as $group) if ((int)$group['id'] === $id) Response::json(['data' => $group]);
            }

            // delete
            if ($method === 'DELETE') {
                $groups = $this->loadGroups();
                $deletedGroup = null;
                foreach ($groups as $group) {
                    if ((int) $group['id'] === $id) {
                        $deletedGroup = $group;
                        break;
                    }
                }

                $next = array_values(array_filter($groups, static fn($g) => (int)$g['id'] !== $id));
                $this->saveGroups($next);
                if (is_array($deletedGroup)) {
                    $this->deleteDatabaseGroup($deletedGroup);
                }
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
if (($method === 'POST' || $method === 'PATCH' || $method === 'PUT') && $action === 'profile') {
    $this->updateProfile();
    return;
}

        if (($method === 'POST' || $method === 'PATCH' || $method === 'PUT') && $action === 'password') {
            $this->updatePassword();
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

        if ($method === 'GET' && $action === 'profile' && ($segments[2] ?? '') === 'stats') {
            $this->profileStats();
            return;
        }

        Response::error('Method not allowed', 405);
    }

    private function handleForms(string $method, array $segments, array $user): void
    {
        $pdo = Database::pdo();

        if ($method === 'GET' && count($segments) === 1) {
            $rows = $this->fetchAll(
                'SELECT f.id, f.form_code, f.title, f.section, f.description, f.is_active, far.due_at, r.code AS role_code
                 FROM forms f
                 LEFT JOIN form_access_rules far ON far.form_id = f.id
                 LEFT JOIN form_access_roles faro ON faro.form_access_rule_id = far.id
                 LEFT JOIN roles r ON r.id = faro.role_id
                 ORDER BY f.id, r.code'
            );

            $forms = [];
            foreach ($rows as $row) {
                $formId = (int) $row['id'];
                if (!isset($forms[$formId])) {
                    $forms[$formId] = [
                        'id' => $formId,
                        'form_code' => $row['form_code'],
                        'title' => $row['title'],
                        'section' => $row['section'],
                        'description' => $row['description'],
                        'is_active' => (bool) $row['is_active'],
                        'due_at' => $row['due_at'] ?? null,
                        'access_roles' => [],
                    ];
                }

                if (!empty($row['role_code']) && !in_array($row['role_code'], $forms[$formId]['access_roles'], true)) {
                    $forms[$formId]['access_roles'][] = $row['role_code'];
                }
            }

            Response::json(['data' => array_values($forms)]);
            return;
        }

        if ($method === 'GET' && count($segments) === 2 && is_numeric($segments[1])) {
            $formId = (int) $segments[1];
            $rows = $this->fetchAll(
                'SELECT f.id, f.form_code, f.title, f.section, f.description, f.is_active, far.due_at, r.code AS role_code
                 FROM forms f
                 LEFT JOIN form_access_rules far ON far.form_id = f.id
                 LEFT JOIN form_access_roles faro ON faro.form_access_rule_id = far.id
                 LEFT JOIN roles r ON r.id = faro.role_id
                 WHERE f.id = ?
                 ORDER BY r.code',
                [$formId]
            );

            if (count($rows) === 0) {
                Response::error('Form not found', 404);
            }

            $form = [
                'id' => $formId,
                'form_code' => $rows[0]['form_code'],
                'title' => $rows[0]['title'],
                'section' => $rows[0]['section'],
                'description' => $rows[0]['description'],
                'is_active' => (bool) $rows[0]['is_active'],
                'due_at' => $rows[0]['due_at'] ?? null,
                'access_roles' => [],
            ];

            foreach ($rows as $row) {
                if (!empty($row['role_code']) && !in_array($row['role_code'], $form['access_roles'], true)) {
                    $form['access_roles'][] = $row['role_code'];
                }
            }

            Response::json(['data' => $form]);
            return;
        }

        if (($method === 'PATCH' || $method === 'PUT') && count($segments) === 2 && is_numeric($segments[1])) {
            $this->requireAnyRole($user, ['administrador']);

            $formId = (int) $segments[1];
            $data = $this->body();

            $currentForm = $this->fetchOne('SELECT * FROM forms WHERE id = ?', [$formId]);
            if (!$currentForm) {
                Response::error('Form not found', 404);
            }

            $currentRule = $this->fetchOne('SELECT * FROM form_access_rules WHERE form_id = ? LIMIT 1', [$formId]);

            $allowedRoles = ['docente', 'tutor'];
            if (array_key_exists('access_roles', $data) && !is_array($data['access_roles'])) {
                Response::error('access_roles must be an array', 422);
            }

            $updateFormFields = [];
            $updateParams = [];
            foreach (['title', 'section', 'description', 'is_active'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updateFormFields[] = $field . ' = ?';
                    $updateParams[] = $field === 'is_active'
                        ? (int) (bool) $data[$field]
                        : $data[$field];
                }
            }

            if ($updateFormFields) {
                $updateParams[] = $formId;
                $statement = $pdo->prepare('UPDATE forms SET ' . implode(', ', $updateFormFields) . ' WHERE id = ?');
                $statement->execute($updateParams);
            }

            if (array_key_exists('due_at', $data) || array_key_exists('access_roles', $data)) {
                if (!$currentRule) {
                    $pdo->prepare('INSERT INTO form_access_rules (form_id, due_at, updated_by) VALUES (?, ?, ?)')
                        ->execute([
                            $formId,
                            null,
                            $user['id'],
                        ]);
                    $currentRule = $this->fetchOne('SELECT * FROM form_access_rules WHERE form_id = ? LIMIT 1', [$formId]);
                }

                if (array_key_exists('due_at', $data)) {
                    $dueAt = $data['due_at'];
                    if ($dueAt === '' || $dueAt === null) {
                        $dueAt = null;
                    }

                    $pdo->prepare('UPDATE form_access_rules SET due_at = ?, updated_by = ? WHERE form_id = ?')
                        ->execute([
                            $dueAt,
                            $user['id'],
                            $formId,
                        ]);
                } else {
                    $pdo->prepare('UPDATE form_access_rules SET updated_by = ? WHERE form_id = ?')
                        ->execute([$user['id'], $formId]);
                }

                if (array_key_exists('access_roles', $data)) {
                    $roleCodes = array_values(array_intersect($allowedRoles, array_map(static fn($role) => (string) $role, $data['access_roles'])));
                    $roleIds = [];
                    if ($roleCodes) {
                        $placeholders = implode(', ', array_fill(0, count($roleCodes), '?'));
                        $roleRows = $this->fetchAll('SELECT id, code FROM roles WHERE code IN (' . $placeholders . ') ORDER BY code', $roleCodes);
                        foreach ($roleRows as $row) {
                            $roleIds[] = (int) $row['id'];
                        }
                    }

                    $ruleId = (int) ($currentRule['id'] ?? $this->scalar('SELECT id FROM form_access_rules WHERE form_id = ? LIMIT 1', [$formId]));
                    $pdo->prepare('DELETE FROM form_access_roles WHERE form_access_rule_id = ?')->execute([$ruleId]);

                    if ($roleIds) {
                        $insertRole = $pdo->prepare('INSERT INTO form_access_roles (form_access_rule_id, role_id) VALUES (?, ?)');
                        foreach ($roleIds as $roleId) {
                            $insertRole->execute([$ruleId, $roleId]);
                        }
                    }
                }
            }

            $updatedRows = $this->fetchAll(
                'SELECT f.id, f.form_code, f.title, f.section, f.description, f.is_active, far.due_at, r.code AS role_code
                 FROM forms f
                 LEFT JOIN form_access_rules far ON far.form_id = f.id
                 LEFT JOIN form_access_roles faro ON faro.form_access_rule_id = far.id
                 LEFT JOIN roles r ON r.id = faro.role_id
                 WHERE f.id = ?
                 ORDER BY r.code',
                [$formId]
            );

            $updatedForm = [
                'id' => $formId,
                'form_code' => $updatedRows[0]['form_code'] ?? $currentForm['form_code'],
                'title' => $updatedRows[0]['title'] ?? $currentForm['title'],
                'section' => $updatedRows[0]['section'] ?? $currentForm['section'],
                'description' => $updatedRows[0]['description'] ?? $currentForm['description'],
                'is_active' => (bool) ($updatedRows[0]['is_active'] ?? $currentForm['is_active']),
                'due_at' => $updatedRows[0]['due_at'] ?? null,
                'access_roles' => [],
            ];

            foreach ($updatedRows as $row) {
                if (!empty($row['role_code']) && !in_array($row['role_code'], $updatedForm['access_roles'], true)) {
                    $updatedForm['access_roles'][] = $row['role_code'];
                }
            }

            Response::json(['data' => $updatedForm]);
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
            @file_put_contents(__DIR__ . '/../storage/logs/upload_debug.log', "[" . date('c') . "] POST /documents body keys: " . print_r(array_keys((array)$data), true) . "\n", FILE_APPEND);
            @file_put_contents(__DIR__ . '/../storage/logs/upload_debug.log', "[" . date('c') . "] POST /documents body keys: " . print_r(array_keys((array)$data), true) . "\n", FILE_APPEND);
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
                    $sql = 'SELECT d.*, f.form_code, f.title AS form_title, ac.name AS cycle_name, u.full_name AS uploaded_by_name,
                        g.group_code AS group_code, g.group_number AS group_number, g.group_code AS group_name, ar.full_name AS assigned_reviewer_name
                    FROM documents d
                    JOIN forms f ON f.id = d.form_id
                    LEFT JOIN academic_cycles ac ON ac.id = d.cycle_id
                    JOIN users u ON u.id = d.uploaded_by
                    LEFT JOIN groups g ON g.id = d.group_id
                    LEFT JOIN users ar ON ar.id = d.assigned_reviewer_id';
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
            if (!empty($_GET['group_id'])) {
                $filters[] = 'd.group_id = ?';
                $params[] = (int) $_GET['group_id'];
            }
            if (!empty($_GET['plan'])) {
                $filters[] = 'd.plan = ?';
                $params[] = $_GET['plan'];
            }
            if (!empty($_GET['carrera_label'])) {
                $filters[] = 'd.carrera_label = ?';
                $params[] = $_GET['carrera_label'];
            }
            if (!empty($_GET['materia'])) {
                $filters[] = 'd.materia = ?';
                $params[] = $_GET['materia'];
            }
            if (!empty($_GET['apartado_label'])) {
                $filters[] = 'd.apartado_label = ?';
                $params[] = $_GET['apartado_label'];
            }
            if (!empty($_GET['uploaded_by'])) {
                $filters[] = 'd.uploaded_by = ?';
                $params[] = (int) $_GET['uploaded_by'];
            }
            if (!empty($_GET['uploaded_by_name'])) {
                $filters[] = 'u.full_name LIKE ?';
                $params[] = '%' . str_replace('%', '\\%', $_GET['uploaded_by_name']) . '%';
            }
            if (!empty($_GET['uploader_role'])) {
                $filters[] = 'EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.code = ?)';
                $params[] = $_GET['uploader_role'];
            }
            if (!empty($_GET['search'])) {
                $filters[] = '(d.title LIKE ? OR d.carrera_label LIKE ? OR d.materia LIKE ? OR d.apartado_label LIKE ?)';
                $search = '%' . str_replace('%', '\\%', $_GET['search']) . '%';
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
            }

            if ($filters) {
                $sql .= ' WHERE ' . implode(' AND ', $filters);
            }

            // Pagination support (optional)
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = max(1, (int) ($_GET['per_page'] ?? 20));
            // compute total matching rows
            $countSql = 'SELECT COUNT(1) AS total FROM (' . $sql . ') AS tmp_count';
            $totalRow = $this->fetchOne($countSql, $params);
            $total = $totalRow['total'] ?? 0;

            $sql .= ' ORDER BY d.submitted_at DESC';
            $offset = ($page - 1) * $perPage;
            $sql .= ' LIMIT ? OFFSET ?';
            $params[] = $perPage;
            $params[] = $offset;

            $rows = $this->fetchAll($sql, $params);
            // normalize group_code in documents to use hyphen instead of underscore
            foreach ($rows as &$row) {
                if (isset($row['group_code'])) {
                    $row['group_code'] = str_replace('_', '-', (string)$row['group_code']);
                }
            }
            unset($row);
            Response::json(['data' => $rows, 'meta' => ['total' => (int) $total, 'page' => $page, 'per_page' => $perPage]]);
        }

        if ($method === 'POST' && count($segments) === 1) {
            $data = $this->body();
            $this->validateRequired($data, ['form_id', 'title']);
            $originalDocumentId = isset($data['original_document_id']) ? (int) $data['original_document_id'] : 0;

            $existingDocument = null;
            if ($originalDocumentId > 0) {
                $existingDocument = $this->fetchOne('SELECT * FROM documents WHERE id = ?', [$originalDocumentId]);
                if (!$existingDocument) {
                    Response::error('Original document not found', 404);
                }
                if ((int) $existingDocument['uploaded_by'] !== (int) $user['id']) {
                    Response::error('No permission to update this document', 403);
                }
            }

            // Check for PHP upload errors (size exceeded, etc)
            if (isset($_FILES['file']['error']) && $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $errorCode = $_FILES['file']['error'];
                $errorMsg = 'Unknown upload error';
                if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
                    $errorMsg = 'File size exceeds limit. upload_max_filesize=' . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size');
                } elseif ($errorCode === UPLOAD_ERR_PARTIAL) {
                    $errorMsg = 'File upload incomplete';
                } elseif ($errorCode === UPLOAD_ERR_NO_TMP_DIR) {
                    $errorMsg = 'No temporary directory available';
                } elseif ($errorCode === UPLOAD_ERR_CANT_WRITE) {
                    $errorMsg = 'Cannot write to upload directory';
                }
                Response::error($errorMsg, 422);
            }

            $hasFile = (isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) || (!empty($data['file_base64'] ?? ''));
            if (!$hasFile && !$existingDocument) {
                Response::error('PDF file is required', 422);
            }

            $formId = (int) $data['form_id'];
            $relativePath = $existingDocument['file_path'] ?? null;
            $mimeType = $existingDocument['mime_type'] ?? null;
            $fileSizeBytes = $existingDocument['file_size_bytes'] ?? null;

            if ($hasFile) {
                $uploadDir = __DIR__ . '/../storage/uploads/documents/' . date('Y/m');
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                    Response::error('Unable to create upload directory', 500);
                }

                if (!is_writable($uploadDir)) {
                    @chmod($uploadDir, 0777);
                }

                $tmpName = $_FILES['file']['tmp_name'] ?? '';
                $originalName = basename((string) ($_FILES['file']['name'] ?? ($data['file_name'] ?? 'upload.pdf')));
                $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
                $storedName = uniqid('doc_', true) . '_' . $safeName;
                $storedPath = $uploadDir . '/' . $storedName;

                if (!empty($data['file_base64'])) {
                    $decoded = base64_decode($data['file_base64']);
                    if ($decoded === false) {
                        Response::error('Invalid base64 file data', 400);
                    }
                    if (strlen($decoded) > 5 * 1024 * 1024) {
                        Response::error('File exceeds 5MB limit', 422);
                    }
                    if (@file_put_contents($storedPath, $decoded) === false) {
                        Response::error('Unable to save decoded file', 500);
                    }
                    $mimeType = $data['file_type'] ?? (function_exists('mime_content_type') ? mime_content_type($storedPath) : 'application/octet-stream');
                    $fileSizeBytes = strlen($decoded);
                } else {
                    if (!is_uploaded_file($tmpName)) {
                        @file_put_contents(__DIR__ . '/../storage/logs/upload_debug.log', "[" . date('c') . "] is_uploaded_file failed for tmp=" . var_export($tmpName, true) . "\n" . print_r(
                            [
                                '_FILES' => $_FILES,
                                'post_max_size' => ini_get('post_max_size'),
                                'upload_max_filesize' => ini_get('upload_max_filesize'),
                                'REQUEST_CONTENT_LENGTH' => $_SERVER['CONTENT_LENGTH'] ?? 'not set',
                            ],
                            true
                        ) . "\n", FILE_APPEND);

                        if (empty($tmpName)) {
                            Response::error('File upload failed: no temporary file. Check file size vs post_max_size (' . ini_get('post_max_size') . ') and upload_max_filesize (' . ini_get('upload_max_filesize') . ')', 500);
                        }

                        Response::error('Uploaded file missing or invalid (is_uploaded_file failed)', 500);
                    }

                    $moved = @move_uploaded_file($tmpName, $storedPath);
                    if (!$moved) {
                        if (@copy($tmpName, $storedPath)) {
                            @unlink($tmpName);
                        } else {
                            $errCode = $_FILES['file']['error'] ?? 'unknown';
                            Response::error('Unable to save uploaded file (move_failed). tmp=' . $tmpName . ' err=' . $errCode, 500);
                        }
                    }

                    $mimeType = $_FILES['file']['type'] ?? null;
                    $fileSizeBytes = (int) ($_FILES['file']['size'] ?? 0);
                }

                if ($existingDocument && !empty($existingDocument['file_path'])) {
                    $previousPath = __DIR__ . '/../' . $existingDocument['file_path'];
                    if (is_file($previousPath)) {
                        @unlink($previousPath);
                    }
                }

                $relativePath = 'storage/uploads/documents/' . date('Y/m') . '/' . $storedName;
            }

            $resolvedGroupId = $this->resolveDocumentGroupId($data, $existingDocument['group_id'] ?? null);
            if (($data['group_id'] ?? null) !== null || ($data['group_code'] ?? null) !== null) {
                if ($resolvedGroupId === null) {
                    Response::error('Invalid group selected', 422);
                }
            }

            if ($existingDocument) {
                $statement = $pdo->prepare('UPDATE documents SET form_id = ?, cycle_id = ?, title = ?, apartado_label = ?, plan = ?, carrera_label = ?, materia = ?, parcial = ?, group_id = ?, file_path = ?, mime_type = ?, file_size_bytes = ?, status = ?, submitted_at = NOW() WHERE id = ?');
                $statement->execute([
                    $formId,
                    $data['cycle_id'] ?? $existingDocument['cycle_id'],
                    $data['title'],
                    $data['apartado_label'] ?? $existingDocument['apartado_label'],
                    $data['plan'] ?? $existingDocument['plan'],
                    $data['carrera_label'] ?? $existingDocument['carrera_label'],
                    $data['materia'] ?? $existingDocument['materia'],
                    $data['parcial'] ?? $existingDocument['parcial'],
                    $resolvedGroupId,
                    $relativePath,
                    $mimeType,
                    $fileSizeBytes,
                    'pendiente',
                    $originalDocumentId,
                ]);

                $pdo->prepare('INSERT INTO document_status_history (document_id, action, action_by, notes) VALUES (?, ?, ?, ?)')
                    ->execute([$originalDocumentId, 'actualizado', $user['id'], 'Documento actualizado desde la API']);

                Response::json(['data' => $this->fetchOne('SELECT * FROM documents WHERE id = ?', [$originalDocumentId])], 200);
            }

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
                $resolvedGroupId,
                $relativePath,
                $mimeType ?? null,
                (int) ($fileSizeBytes ?? 0),
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

        if (($segments[2] ?? '') === 'file' && $method === 'GET') {
            $doc = $this->fetchOne('SELECT file_path, mime_type, title FROM documents WHERE id = ?', [$id]);
            if (!$doc) {
                Response::error('Document not found', 404);
            }
            $fullPath = __DIR__ . '/../' . $doc['file_path'];
            if (!file_exists($fullPath)) {
                Response::error('File not found', 404);
            }
            $mime = $doc['mime_type'] ?? (function_exists('mime_content_type') ? mime_content_type($fullPath) : 'application/octet-stream');
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($fullPath));
            header('Content-Disposition: attachment; filename="' . basename($doc['file_path']) . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Access-Control-Allow-Origin: ' . Config::allowedFrontendOrigin());
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Expose-Headers: Content-Disposition, Content-Type');
            readfile($fullPath);
            exit;
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

    // ============================================
    // GET /conversations - Listar conversaciones
    // ============================================
    if ($method === 'GET' && count($segments) === 1) {
        $rows = $this->loadUserConversations($currentUserId);
        Response::json(['data' => $rows]);
    }

    // ============================================
    // POST /conversations - Crear conversación
    // ============================================
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
            'SELECT u.id, u.full_name, r.code AS role_code 
             FROM users u 
             LEFT JOIN user_roles ur ON ur.user_id = u.id 
             LEFT JOIN roles r ON r.id = ur.role_id 
             WHERE u.id IN (?, ?)',
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

        // CORRECCIÓN: Si no existe, crear nueva conversación
        $newConversationId = $this->createDirectConversation($participants[0], $participants[1]);
        Response::json(['data' => $this->formatConversation($newConversationId, $currentUserId)], 201);
    }

    // ============================================
    // Validar ID de conversación
    // ============================================
    $conversationId = isset($segments[1]) && is_numeric($segments[1]) ? (int) $segments[1] : 0;
    
    if ($conversationId <= 0) {
        Response::error('ID de conversación inválido', 422);
    }

    // Verificar acceso
    $this->requireConversationAccess($conversationId, $currentUserId);

    // ============================================
    // CORRECCIÓN: GET /conversations/{id}/messages - Obtener mensajes
    // ============================================
    if ($method === 'GET' && ($segments[2] ?? '') === 'messages' && !isset($segments[3])) {
        $messages = $this->fetchConversationMessages($conversationId, $currentUserId);
        Response::json(['data' => $messages]);
    }

    // ============================================
    // POST /conversations/{id}/messages - Enviar mensaje
    // ============================================
    if ($method === 'POST' && ($segments[2] ?? '') === 'messages' && !isset($segments[3])) {
        $data = $this->body();
        $body = trim((string) ($data['body'] ?? ''));
        $replyToMessageId = !empty($data['reply_to_message_id']) ? (int) $data['reply_to_message_id'] : null;

        if ($body === '') {
            Response::error('El cuerpo del mensaje no puede estar vacío', 422);
        }

        // Validar mensaje de respuesta si existe
        if ($replyToMessageId !== null) {
            $replyMessage = $this->fetchMessageById($replyToMessageId, $conversationId);
            if (!$replyMessage) {
                Response::error('El mensaje de respuesta no fue encontrado', 422);
            }
        }

        // Insertar el mensaje
        $statement = $pdo->prepare(
            'INSERT INTO messages (conversation_id, sender_user_id, body, reply_to_message_id, created_at) 
             VALUES (?, ?, ?, ?, NOW())'
        );
        $statement->execute([
            $conversationId,
            $currentUserId,
            $body,
            $replyToMessageId,
        ]);

        $messageId = (int) $pdo->lastInsertId();

        // Actualizar timestamp de la conversación
        $pdo->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = ?')
            ->execute([$conversationId]);

        // Incrementar no leídos para el otro participante
        $pdo->prepare(
            'UPDATE conversation_participants 
             SET unread_count = unread_count + 1 
             WHERE conversation_id = ? AND user_id != ?'
        )->execute([$conversationId, $currentUserId]);

        // Devolver el mensaje creado
        $message = $this->fetchConversationMessageById($messageId, $currentUserId);
        Response::json(['data' => $message], 201);
    }

    // ============================================
    // Operaciones sobre mensajes individuales
    // ============================================
    if (($segments[2] ?? '') === 'messages' && isset($segments[3]) && is_numeric($segments[3])) {
        $messageId = (int) $segments[3];

        // DELETE /conversations/{id}/messages/{messageId} - Eliminar mensaje
        if ($method === 'DELETE') {
            $message = $this->fetchMessageById($messageId, $conversationId);
            if (!$message) {
                Response::error('Mensaje no encontrado', 404);
            }

            $this->ensureMessageDeletable($message, $currentUserId);
            $pdo->prepare('DELETE FROM messages WHERE id = ? AND conversation_id = ?')
                ->execute([$messageId, $conversationId]);
            
            Response::json(['message' => 'Mensaje eliminado correctamente']);
        }

        // PATCH /conversations/{id}/messages/{messageId} - Editar mensaje
        if ($method === 'PATCH' || $method === 'PUT') {
            $data = $this->body();
            $this->validateRequired($data, ['body']);

            $message = $this->fetchMessageById($messageId, $conversationId);
            if (!$message) {
                Response::error('Mensaje no encontrado', 404);
            }

            $this->ensureMessageEditable($message, $currentUserId);
            
            $pdo->prepare('UPDATE messages SET body = ? WHERE id = ? AND conversation_id = ?')
                ->execute([(string) $data['body'], $messageId, $conversationId]);

            $updatedMessage = $this->fetchConversationMessageById($messageId, $currentUserId);
            Response::json(['data' => $updatedMessage]);
        }
    }

    // ============================================
    // PATCH /conversations/{id}/read - Marcar como leída
    // ============================================
    if (($segments[2] ?? '') === 'read' && $method === 'PATCH') {
        $pdo->prepare(
            'UPDATE conversation_participants 
             SET unread_count = 0, last_read_at = NOW() 
             WHERE conversation_id = ? AND user_id = ?'
        )->execute([$conversationId, $currentUserId]);
        
        Response::json(['message' => 'Conversación marcada como leída']);
    }

    // Si ningún método coincide
    Response::error('Método no permitido', 405);
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
        'avatar_url' => $otherParticipant['avatar_url'] ?? $this->defaultAvatarUrl(),
        'avatar_fallback' => $this->buildAvatar((string) $otherParticipant['full_name']),
        'avatar' => $otherParticipant['avatar_url'] ?? $this->defaultAvatarUrl(),
        'status' => 'offline',
        'participants' => array_map(function (array $participant): array {
            $participantFallback = $this->buildAvatar((string) $participant['full_name']);
            $participantAvatar = $participant['avatar_url'] ?? $this->defaultAvatarUrl();

            return [
                'id' => (int) $participant['id'],
                'name' => (string) $participant['full_name'],
                'role' => (string) ($participant['role_code'] ?? 'docente'),
                'avatar_url' => $participant['avatar_url'] ?? $this->defaultAvatarUrl(),
                'avatar_fallback' => $participantFallback,
                'avatar' => $participantAvatar,
            ];
        }, $participants),
        'lastMessageAt' => (string) ($latestMessage['created_at'] ?? $conversation['updated_at'] ?? ''),
    ];
}
 private function fetchConversationMessages(int $conversationId, int $currentUserId): array
{
    $messages = $this->fetchAll(
        'SELECT m.id, m.body, m.reply_to_message_id, m.sender_user_id, m.created_at, 
         sender.full_name AS sender_name, sender.avatar_url AS sender_avatar,
         reply_sender.full_name AS reply_sender_name, 
         reply_message.body AS reply_body 
         FROM messages m 
         JOIN users sender ON sender.id = m.sender_user_id 
         LEFT JOIN messages reply_message ON reply_message.id = m.reply_to_message_id 
         LEFT JOIN users reply_sender ON reply_sender.id = reply_message.sender_user_id 
         WHERE m.conversation_id = ? 
         ORDER BY m.created_at ASC, m.id ASC',
        [$conversationId]
    );

    return array_map(function (array $message) use ($currentUserId): array {
        $senderFallback = $this->buildAvatar((string) ($message['sender_name'] ?? 'Usuario'));
        $senderAvatar = $message['sender_avatar'] ?? $this->defaultAvatarUrl();

        return [
            'id' => (int) $message['id'],
            'sender' => (string) ($message['sender_name'] ?? 'Usuario'),
            'content' => (string) ($message['body'] ?? ''),
            'timestamp' => (string) ($message['created_at'] ?? ''),
            'isOwn' => (int) ($message['sender_user_id'] ?? 0) === $currentUserId,
            'avatar_url' => $message['sender_avatar'] ?? $this->defaultAvatarUrl(),
            'avatar_fallback' => $senderFallback,
            'avatar' => $senderAvatar,
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
        'SELECT m.id, m.body, m.reply_to_message_id, m.created_at, m.sender_user_id, 
         sender.full_name AS sender_name, sender.avatar_url AS sender_avatar,
         reply_sender.full_name AS reply_sender_name, reply_sender.avatar_url AS reply_sender_avatar,
         reply_message.body AS reply_body 
         FROM messages m 
         JOIN users sender ON sender.id = m.sender_user_id 
         LEFT JOIN messages reply_message ON reply_message.id = m.reply_to_message_id 
         LEFT JOIN users reply_sender ON reply_sender.id = reply_message.sender_user_id 
         WHERE m.id = ? LIMIT 1',
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
        'avatar_url' => $message['sender_avatar'] ?? $this->defaultAvatarUrl(),
        'avatar_fallback' => $this->buildAvatar((string) ($message['sender_name'] ?? 'Usuario')),
        'avatar' => $message['sender_avatar'] ?? $this->defaultAvatarUrl(),
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

    private function defaultAvatarUrl(): string
    {
        return '/api/default-avatar';
    }

    private function publicAvatarUrl(array $user): ?string
    {
        if (!isset($user['id'])) {
            return $user['avatar_url'] ?? $this->defaultAvatarUrl();
        }

        $avatarFile = $this->resolveStoredAvatarFile((int) $user['id'], $user['avatar_url'] ?? null);
        if ($avatarFile === null) {
            return !empty($user['avatar_url']) ? '/api/users/' . (int) $user['id'] . '/avatar' : $this->defaultAvatarUrl();
        }

        $mimeType = $this->detectAvatarMimeType($avatarFile);

        return 'data:' . $mimeType . ';base64,' . base64_encode((string) file_get_contents($avatarFile));
    }

    private function resolveStoredAvatarFile(int $userId, ?string $avatarUrl): ?string
    {
        $avatarDir = __DIR__ . '/../public/uploads/avatars';

        if (!empty($avatarUrl) && str_starts_with($avatarUrl, '/uploads/avatars/')) {
            $avatarPath = __DIR__ . '/../public' . $avatarUrl;
            if (is_file($avatarPath)) {
                return $avatarPath;
            }
        }

        if (!empty($avatarUrl) && str_starts_with($avatarUrl, '/api/users/' . $userId . '/avatar')) {
            $matches = glob($avatarDir . '/avatar_' . $userId . '_*');
            if (is_array($matches) && $matches !== []) {
                usort($matches, static function (string $left, string $right): int {
                    return filemtime($right) <=> filemtime($left);
                });

                return $matches[0];
            }
        }

        $matches = glob($avatarDir . '/avatar_' . $userId . '_*');
        if (is_array($matches) && $matches !== []) {
            usort($matches, static function (string $left, string $right): int {
                return filemtime($right) <=> filemtime($left);
            });

            return $matches[0];
        }

        return null;
    }

    private function detectAvatarMimeType(string $avatarFile): string
    {
        $extension = strtolower(pathinfo($avatarFile, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => (function_exists('mime_content_type') ? mime_content_type($avatarFile) : 'application/octet-stream'),
        };
    }

  private function streamUserAvatar(int $userId): void
{
    $user = $this->fetchOne('SELECT id, full_name, avatar_url FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        Response::error('User not found', 404);
    }

    $avatarDir = __DIR__ . '/../public/uploads/avatars';
    $avatarFile = null;

    $matches = glob($avatarDir . '/avatar_' . $userId . '_*');
    if (is_array($matches) && $matches !== []) {
        usort($matches, static function (string $left, string $right): int {
            return filemtime($right) <=> filemtime($left);
        });
        $avatarFile = $matches[0];
    }

    if ($avatarFile === null && !empty($user['avatar_url']) && str_starts_with((string) $user['avatar_url'], '/uploads/avatars/')) {
        $fallbackPath = __DIR__ . '/../public' . $user['avatar_url'];
        if (is_file($fallbackPath)) {
            $avatarFile = $fallbackPath;
        }
    }

    if ($avatarFile === null || !is_file($avatarFile)) {
        Response::error('Avatar not found', 404);
    }

    $extension = strtolower(pathinfo($avatarFile, PATHINFO_EXTENSION));
    $mimeType = match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => (function_exists('mime_content_type') ? mime_content_type($avatarFile) : 'application/octet-stream'),
    };

    $filemtime = filemtime($avatarFile);
    
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($avatarFile));
    header('Cache-Control: no-cache, must-revalidate'); // Forzar no caché
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $filemtime) . ' GMT');
    header('Access-Control-Allow-Origin: ' . Config::allowedFrontendOrigin());
    header('Access-Control-Allow-Credentials: true');
    readfile($avatarFile);
    exit;
}

    private function streamDefaultAvatar(): void
    {
        $avatarFile = __DIR__ . '/../public/uploads/avatars/profile.webp';

        if (!is_file($avatarFile)) {
            Response::error('Avatar not found', 404);
        }

        $extension = strtolower(pathinfo($avatarFile, PATHINFO_EXTENSION));
        $mimeType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => (function_exists('mime_content_type') ? mime_content_type($avatarFile) : 'application/octet-stream'),
        };

        $filemtime = filemtime($avatarFile);

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($avatarFile));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $filemtime) . ' GMT');
        header('Access-Control-Allow-Origin: ' . Config::allowedFrontendOrigin());
        header('Access-Control-Allow-Credentials: true');
        readfile($avatarFile);
        exit;
    }
    private function getUsersWithRoles(): array
    {
        $users = $this->fetchAll(
            "SELECT id, full_name, email, phone, area, avatar_url, is_active, created_at, updated_at, (SELECT COUNT(*) FROM documents d WHERE d.uploaded_by = users.id) AS documents_count FROM users WHERE email NOT IN ('docente1@utslrc.edu.mx', 'tutor1@utslrc.edu.mx') ORDER BY full_name"
        );
        foreach ($users as &$user) {
            $user['avatar_url'] = $this->publicAvatarUrl($user);
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

        $user['avatar_url'] = $this->publicAvatarUrl($user);
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

    private function buildGroupCode(array $group): string
    {
        $careerCode = strtoupper(trim((string) ($group['careerCode'] ?? '')));
        $cuatrimestre = (int) ($group['cuatrimestre'] ?? 0);
        $groupNumber = (int) ($group['groupNumber'] ?? 0);

        return $careerCode !== '' && $cuatrimestre > 0 && $groupNumber > 0
            ? $careerCode . $cuatrimestre . '-' . $groupNumber
            : strtoupper(trim((string) ($group['group_code'] ?? $group['name'] ?? '')));
    }

    private function resolveCareerIdForGroup(array $group): ?int
    {
        $careerCode = strtoupper(trim((string) ($group['careerCode'] ?? '')));
        $plan = strtolower(trim((string) ($group['plan'] ?? '')));
        $cuatrimestre = (int) ($group['cuatrimestre'] ?? 0);

        if ($careerCode === '') {
            return null;
        }

        if ($plan === 'nuevo-modelo') {
            $plan = 'nuevo_modelo';
        } elseif ($plan === 'plan-normal') {
            $plan = 'plan_normal';
        }

        $level = null;
        if ($plan === 'nuevo_modelo') {
            $level = $cuatrimestre > 0 && $cuatrimestre <= 6 ? 'TSU' : 'Ingenieria';
        } elseif ($plan === 'plan_normal') {
            $level = 'Ingenieria';
        }

        $pdo = Database::pdo();

        if ($level !== null) {
            $statement = $pdo->prepare('SELECT id FROM careers WHERE code = ? AND plan = ? AND level = ? LIMIT 1');
            $statement->execute([$careerCode, $plan, $level]);
            $careerId = $statement->fetchColumn();
            if ($careerId !== false) {
                return (int) $careerId;
            }

            $statement = $pdo->prepare('INSERT INTO careers (code, name, plan, level) VALUES (?, ?, ?, ?)');
            $statement->execute([
                $careerCode,
                $careerCode,
                $plan,
                $level,
            ]);

            return (int) $pdo->lastInsertId();
        }

        $statement = $pdo->prepare('SELECT id FROM careers WHERE code = ? AND plan = ? ORDER BY id LIMIT 1');
        $statement->execute([$careerCode, $plan]);
        $careerId = $statement->fetchColumn();

        if ($careerId !== false) {
            return (int) $careerId;
        }

        $statement = $pdo->prepare('INSERT INTO careers (code, name, plan, level) VALUES (?, ?, ?, ?)');
        $statement->execute([
            $careerCode,
            $careerCode,
            $plan,
            'Ingenieria',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function planFromCareerId(int $careerId): string
    {
        if ($careerId <= 0) {
            return 'plan_normal';
        }

        $statement = Database::pdo()->prepare('SELECT plan FROM careers WHERE id = ? LIMIT 1');
        $statement->execute([$careerId]);
        $plan = (string) ($statement->fetchColumn() ?: 'plan_normal');

        return $plan === 'nuevo_modelo' ? 'nuevo-modelo' : 'plan-normal';
    }

    private function syncDatabaseGroup(array $group, ?array $previousGroup = null): void
    {
        $groupCode = $this->buildGroupCode($group);
        if ($groupCode === '') {
            return;
        }

        $pdo = Database::pdo();
        $careerId = $this->resolveCareerIdForGroup($group);
        $cycleId = (int) ($this->scalar("SELECT id FROM academic_cycles WHERE status = 'activo' ORDER BY id DESC LIMIT 1") ?? 0);
        $cycleId = $cycleId > 0 ? $cycleId : null;
        $cuatrimestre = (int) ($group['cuatrimestre'] ?? 0);
        $groupNumber = (int) ($group['groupNumber'] ?? 0);

        $existingId = null;
        if ($previousGroup !== null) {
            $previousCode = $this->buildGroupCode($previousGroup);
            if ($previousCode !== '') {
                $statement = $pdo->prepare('SELECT id FROM academic_groups WHERE group_code = ? LIMIT 1');
                $statement->execute([$previousCode]);
                $existingId = $statement->fetchColumn();
                if ($existingId === false) {
                    $existingId = null;
                }
            }
        }

        if ($existingId === null) {
            $statement = $pdo->prepare('SELECT id FROM groups WHERE group_code = ? LIMIT 1');
            $statement->execute([$groupCode]);
            $existingId = $statement->fetchColumn();
            if ($existingId === false) {
                $existingId = null;
            }
        }

        if ($existingId === null) {
            $statement = $pdo->prepare('SELECT id FROM groups WHERE career_id = ? AND cuatrimestre = ? AND group_number = ? LIMIT 1');
            $statement->execute([$careerId, $cuatrimestre, $groupNumber]);
            $existingId = $statement->fetchColumn();
            if ($existingId === false) {
                $existingId = null;
            }
        }

        if ($existingId !== null) {
            $statement = $pdo->prepare('UPDATE groups SET career_id = ?, cycle_id = ?, cuatrimestre = ?, group_number = ?, group_code = ? WHERE id = ?');
            $statement->execute([
                $careerId,
                $cycleId,
                $cuatrimestre,
                $groupNumber,
                $groupCode,
                (int) $existingId,
            ]);
            return;
        }

        $statement = $pdo->prepare('INSERT INTO groups (career_id, cycle_id, cuatrimestre, group_number, group_code) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([
            $careerId,
            $cycleId,
            $cuatrimestre,
            $groupNumber,
            $groupCode,
        ]);
    }

    private function syncStoredGroupsToDatabase(array $groups): void
    {
        foreach ($groups as $group) {
            if (is_array($group)) {
                $this->syncDatabaseGroup($group);
            }
        }
    }

    private function deleteDatabaseGroup(array $group): void
    {
        $groupCode = $this->buildGroupCode($group);
        if ($groupCode === '') {
            return;
        }

        $statement = Database::pdo()->prepare('DELETE FROM groups WHERE group_code = ?');
        $statement->execute([$groupCode]);
    }

    private function resolveDocumentGroupId(array $data, mixed $fallbackGroupId = null): ?int
    {
        $pdo = Database::pdo();

        $groupId = isset($data['group_id']) ? (int) $data['group_id'] : (int) ($fallbackGroupId ?? 0);
        if ($groupId > 0) {
            $statement = $pdo->prepare('SELECT id FROM groups WHERE id = ? LIMIT 1');
            $statement->execute([$groupId]);
            $resolvedId = $statement->fetchColumn();
            if ($resolvedId !== false) {
                return (int) $resolvedId;
            }
        }

        $groupCode = strtoupper(str_replace('_', '-', trim((string) ($data['group_code'] ?? ''))));
        if ($groupCode !== '') {
            $statement = $pdo->prepare('SELECT id FROM groups WHERE REPLACE(UPPER(group_code), "_", "-") = ? LIMIT 1');
            $statement->execute([$groupCode]);
            $resolvedId = $statement->fetchColumn();
            if ($resolvedId !== false) {
                return (int) $resolvedId;
            }
        }

        return null;
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = Database::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

   private function updateProfile(): void
{
    $user = $this->requireUser();
    $data = $this->body();

    $fullName = trim((string) ($data['full_name'] ?? ''));
    $phone = array_key_exists('phone', $data) ? trim((string) $data['phone']) : (string) ($user['phone'] ?? '');
    $area = array_key_exists('area', $data) ? trim((string) $data['area']) : (string) ($user['area'] ?? '');

    if ($fullName === '') {
        $fullName = (string) ($user['full_name'] ?? '');
    }

    if ($fullName === '') {
        Response::error('El nombre no puede quedar vacío', 422);
    }

    $avatarUrl = $user['avatar_url'] ?? null;
    if (isset($_FILES['avatar']) && ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $tmpName = (string) $_FILES['avatar']['tmp_name'];
        $originalName = basename((string) ($_FILES['avatar']['name'] ?? 'avatar'));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            Response::error('Selecciona una imagen válida', 422);
        }

        // Limpiar avatares antiguos del usuario
        $uploadDir = __DIR__ . '/../public/uploads/avatars';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
            Response::error('No se pudo crear la carpeta de avatares', 500);
        }

        // Eliminar avatares anteriores
        $oldAvatars = glob($uploadDir . '/avatar_' . $user['id'] . '_*');
        foreach ($oldAvatars as $oldAvatar) {
            if (is_file($oldAvatar)) {
                @unlink($oldAvatar);
            }
        }

        $storedName = 'avatar_' . $user['id'] . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
        $storedPath = $uploadDir . '/' . $storedName;

        if (!move_uploaded_file($tmpName, $storedPath)) {
            Response::error('No se pudo guardar el avatar', 500);
        }

        // Guardar la URL dinámica
        $avatarUrl = '/api/users/' . (int) $user['id'] . '/avatar';
    }

    $pdo = Database::pdo();
    $statement = $pdo->prepare('UPDATE users SET full_name = ?, phone = ?, area = ?, avatar_url = ? WHERE id = ?');
    $statement->execute([
        $fullName,
        $phone !== '' ? $phone : null,
        $area !== '' ? $area : null,
        $avatarUrl,
        $user['id'],
    ]);

    // Obtener el usuario actualizado
    $updatedUser = $this->getUserById((int) $user['id']);
    
    // No alterar data URLs; llegan ya listas para renderizar en el frontend.
    if ($updatedUser && !empty($updatedUser['avatar_url']) && !str_starts_with((string) $updatedUser['avatar_url'], 'data:')) {
        $timestamp = time();
        $separator = str_contains((string) $updatedUser['avatar_url'], '?') ? '&' : '?';
        $updatedUser['avatar_url'] = $updatedUser['avatar_url'] . $separator . 't=' . $timestamp;
    }
    
    Response::json(['user' => $updatedUser]);
}
    private function profileStats(): void
    {
        $user = $this->requireUser();
        $pdo = Database::pdo();

        $documentsSent = (int) $this->scalar('SELECT COUNT(*) FROM documents WHERE uploaded_by = ?', [(int) $user['id']]);
        $documentsReviewed = (int) $this->scalar('SELECT COUNT(*) FROM documents WHERE uploaded_by = ? AND status = ?', [(int) $user['id'], 'revisado']);
        $documentsPending = (int) $this->scalar('SELECT COUNT(*) FROM documents WHERE uploaded_by = ? AND status = ?', [(int) $user['id'], 'pendiente']);
        $documentsReturned = (int) $this->scalar('SELECT COUNT(*) FROM documents WHERE uploaded_by = ? AND status = ?', [(int) $user['id'], 'devuelto']);

        Response::json([
            'stats' => [
                'documents_sent' => $documentsSent,
                'documents_reviewed' => $documentsReviewed,
                'documents_pending' => $documentsPending,
                'documents_returned' => $documentsReturned,
                'member_since' => $user['created_at'] ?? null,
            ],
        ]);
    }

    private function updatePassword(): void
    {
        $user = $this->requireUser();
        $data = $this->body();

        $currentPassword = (string) ($data['current_password'] ?? '');
        $newPassword = (string) ($data['password'] ?? '');
        $confirmation = (string) ($data['password_confirmation'] ?? $data['password_confirmation'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmation === '') {
            Response::error('Completa todos los campos de contraseña', 422);
        }

        if (strlen($newPassword) < 8) {
            Response::error('La nueva contraseña debe tener al menos 8 caracteres', 422);
        }

        if ($newPassword !== $confirmation) {
            Response::error('La confirmación no coincide', 422);
        }

        $pdo = Database::pdo();
        $stored = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stored->execute([(int) $user['id']]);
        $currentHash = (string) ($stored->fetchColumn() ?: '');

        if ($currentHash === '' || !password_verify($currentPassword, $currentHash)) {
            Response::error('La contraseña actual no es correcta', 422);
        }

        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([
            password_hash($newPassword, PASSWORD_DEFAULT),
            $user['id'],
        ]);

        Response::json(['message' => 'Contraseña actualizada correctamente']);
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

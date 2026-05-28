# UT_API

API backend para el sistema escolar de docentes.

## Stack objetivo

- PHP 8.2+
- Laravel 11
- MySQL en AWS RDS
- Laravel Sanctum para autenticacion
- AWS SES para correo de recuperacion

## Modulos incluidos en este starter

- Autenticacion con roles
- Recuperacion de contrasena por token
- Usuarios docentes, tutores y administradores
- Ciclos escolares
- Formularios y reglas de acceso

# UT_API

API backend para el sistema escolar de docentes.

## Stack actual

- PHP 8.0 compatible con XAMPP
- API en PHP puro con PDO
- MySQL local para pruebas
- Misma estructura de base que luego se podrá mover a AWS

## Modulos incluidos

- Autenticacion con token
- Recuperacion de contrasena por token
- Usuarios docentes, tutores y administradores
- Ciclos escolares
- Formularios y reglas de acceso
- Documentos PDF
- Mensajes y conversaciones

## Arranque local

1. Importa `ut_api.sql` en MySQL Workbench usando tu XAMPP local.
2. Copia `.env.example` a `.env`.
3. Ajusta `DB_*` si tu MySQL local usa otro usuario o puerto.
4. Levanta el servidor con:
   `php -S 127.0.0.1:8000 -t public public/index.php`

## Credenciales de prueba

- Email: `esmeralda.rosas@utslrc.edu.mx`
- Password: `12345678`

## Endpoints base

- `POST /api/auth/login`
- `POST /api/auth/logout`
- `GET /api/auth/me`
- `POST /api/auth/forgot-password`
- `POST /api/auth/reset-password`
- `GET /api/dashboard/stats`
- `GET /api/forms`
- `GET /api/cycles`
- `GET /api/users`
- `GET /api/documents`
- `POST /api/documents`
- `GET /api/conversations`
- `POST /api/conversations/{id}/messages`

## Nota

Esta versión está pensada para pruebas locales primero. Cuando ya quieras moverla a AWS, solo habrá que cambiar la cadena de conexión y el correo.

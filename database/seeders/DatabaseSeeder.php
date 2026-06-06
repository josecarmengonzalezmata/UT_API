<?php

namespace Database\Seeders;

use App\Models\Form;
use App\Models\FormAccessRule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [];

        foreach ([
            ['code' => 'administrador', 'name' => 'Administrador'],
            ['code' => 'docente', 'name' => 'Docente'],
            ['code' => 'tutor', 'name' => 'Tutor'],
        ] as $role) {
            $roles[$role['code']] = Role::query()->updateOrCreate(['code' => $role['code']], ['name' => $role['name']]);
        }

        $adminEmail = 'esmeralda.rosas@utslrc.edu.mx';
        $adminUser = User::query()->updateOrCreate(
            ['email' => $adminEmail],
            [
                'full_name' => 'Esmeralda Rosas',
                'password_hash' => Hash::make('12345678'),
                'phone' => null,
                'area' => 'Administración',
                'avatar_url' => null,
                'is_active' => true,
            ]
        );

        $adminUser->roles()->sync([$roles['administrador']->id]);

        // Local placeholder users have been removed from seed data. Use real database users instead.

        User::query()
            ->where('id', '!=', $adminUser->id)
            ->whereHas('roles', static function ($query) {
                $query->where('code', 'administrador');
            })
            ->get()
            ->each(static function (User $user) use ($roles): void {
                $user->roles()->detach($roles['administrador']->id);
            });

        $defaultFormRoles = [
            'planeacion' => ['docente'],
            'instrumento-3040' => ['docente'],
            'instrumento-6070' => ['docente'],
            'instrumento-30-normal' => ['docente'],
            'instrumento-40-nuevo' => ['docente'],
            'instrumento-60-nuevo' => ['docente'],
            'instrumento-70-normal' => ['docente'],
            'lista-concentrada' => ['docente'],
            'remedial' => ['docente'],
            'asesoria' => ['docente'],
            'portafolio-digital' => ['docente'],
            'acta-final' => ['docente'],
            'estadias' => ['docente'],
            'tutorias' => ['docente'],
            'carga-academica' => ['docente', 'tutor'],
            'reporte-bajas' => ['docente', 'tutor'],
            'concentrado-asesorias' => ['docente', 'tutor'],
            'acta-asistencia-grupal' => ['docente', 'tutor'],
            'ficha-tecnica' => ['docente', 'tutor'],
            'carta-presentacion' => ['docente', 'tutor'],
            'carta-aceptacion' => ['docente', 'tutor'],
            'carta-terminacion' => ['docente', 'tutor'],
        ];

        foreach ([
            ['form_code' => 'planeacion', 'title' => 'Planeación', 'section' => 'docentes'],
            ['form_code' => 'instrumento-3040', 'title' => 'Instrumento 30/40%', 'section' => 'docentes'],
            ['form_code' => 'instrumento-6070', 'title' => 'Instrumento 60/70%', 'section' => 'docentes'],
            ['form_code' => 'instrumento-30-normal', 'title' => 'Instrumento 30 Normal', 'section' => 'docentes'],
            ['form_code' => 'instrumento-40-nuevo', 'title' => 'Instrumento 40 Nuevo', 'section' => 'docentes'],
            ['form_code' => 'instrumento-60-nuevo', 'title' => 'Instrumento 60 Nuevo', 'section' => 'docentes'],
            ['form_code' => 'instrumento-70-normal', 'title' => 'Instrumento 70 Normal', 'section' => 'docentes'],
            ['form_code' => 'lista-concentrada', 'title' => 'Lista Concentrada', 'section' => 'docentes'],
            ['form_code' => 'remedial', 'title' => 'Remedial', 'section' => 'docentes'],
            ['form_code' => 'asesoria', 'title' => 'Asesoría', 'section' => 'docentes'],
            ['form_code' => 'portafolio-digital', 'title' => 'Portafolio Digital Final', 'section' => 'docentes'],
            ['form_code' => 'acta-final', 'title' => 'Acta Final', 'section' => 'docentes'],
            ['form_code' => 'estadias', 'title' => 'Estadías', 'section' => 'estadias'],
            ['form_code' => 'tutorias', 'title' => 'Tutorías', 'section' => 'tutorias'],
            ['form_code' => 'carga-academica', 'title' => 'Carga Académica', 'section' => 'tutorias'],
            ['form_code' => 'reporte-bajas', 'title' => 'Reporte de Bajas', 'section' => 'tutorias'],
            ['form_code' => 'concentrado-asesorias', 'title' => 'Concentrado de Asesorías y Bajas', 'section' => 'tutorias'],
            ['form_code' => 'acta-asistencia-grupal', 'title' => 'Acta de Asistencia Grupal', 'section' => 'tutorias'],
            ['form_code' => 'ficha-tecnica', 'title' => 'Ficha Técnica', 'section' => 'tutorias'],
            ['form_code' => 'carta-presentacion', 'title' => 'Carta de Presentación', 'section' => 'estadias'],
            ['form_code' => 'carta-aceptacion', 'title' => 'Carta de Aceptación', 'section' => 'estadias'],
            ['form_code' => 'carta-terminacion', 'title' => 'Carta de Terminación', 'section' => 'estadias'],
        ] as $form) {
            $formModel = Form::query()->updateOrCreate(
                ['form_code' => $form['form_code']],
                [
                    'title' => $form['title'],
                    'section' => $form['section'],
                    'description' => $form['description'] ?? null,
                    'is_active' => true,
                ]
            );

            $accessRule = FormAccessRule::query()->updateOrCreate(
                ['form_id' => $formModel->id],
                [
                    'due_at' => null,
                    'updated_by' => $adminUser->id,
                ]
            );

            $accessRule->roles()->sync(
                Role::query()
                    ->whereIn('code', $defaultFormRoles[$form['form_code']])
                    ->pluck('id')
                    ->all()
            );
        }
    }
}

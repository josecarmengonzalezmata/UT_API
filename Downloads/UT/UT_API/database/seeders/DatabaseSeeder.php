<?php

namespace Database\Seeders;

use App\Models\Form;
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

        $sampleUsers = [
            [
                'email' => 'docente1@utslrc.edu.mx',
                'full_name' => 'Docente Uno',
                'password' => '12345678',
                'area' => 'Academia',
                'role' => 'docente',
            ],
            [
                'email' => 'tutor1@utslrc.edu.mx',
                'full_name' => 'Tutor Uno',
                'password' => '12345678',
                'area' => 'Tutorías',
                'role' => 'tutor',
            ],
        ];

        foreach ($sampleUsers as $sampleUser) {
            $user = User::query()->updateOrCreate(
                ['email' => $sampleUser['email']],
                [
                    'full_name' => $sampleUser['full_name'],
                    'password_hash' => Hash::make($sampleUser['password']),
                    'phone' => null,
                    'area' => $sampleUser['area'],
                    'avatar_url' => null,
                    'is_active' => true,
                ]
            );

            $user->roles()->sync([$roles[$sampleUser['role']]->id]);
        }

        User::query()
            ->where('id', '!=', $adminUser->id)
            ->whereHas('roles', static function ($query) {
                $query->where('code', 'administrador');
            })
            ->get()
            ->each(static function (User $user) use ($roles): void {
                $user->roles()->detach($roles['administrador']->id);
            });

        foreach ([
            ['form_code' => 'planeacion', 'title' => 'Planeación', 'section' => 'docentes'],
            ['form_code' => 'instrumento-3040', 'title' => 'Instrumento 30/40%', 'section' => 'docentes'],
            ['form_code' => 'instrumento-6070', 'title' => 'Instrumento 60/70%', 'section' => 'docentes'],
            ['form_code' => 'lista-concentrada', 'title' => 'Lista Concentrada', 'section' => 'docentes'],
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
            Form::query()->updateOrCreate(
                ['form_code' => $form['form_code']],
                [
                    'title' => $form['title'],
                    'section' => $form['section'],
                    'description' => $form['description'] ?? null,
                    'is_active' => true,
                ]
            );
        }
    }
}

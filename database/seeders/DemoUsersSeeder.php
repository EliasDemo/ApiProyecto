<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class DemoUsersSeeder extends Seeder
{
    private string $password = 'UPeU2025';

    public function run(): void
    {
        // ====== ÚNICO USUARIO ADMIN (sin expediente) ======
        $admin = $this->mkUser('admin', 'Admin', 'UPeU', 'admin@upeu.pe');
        $admin->assignRole('ADMINISTRADOR');
    }

    private function mkUser(string $nick, string $first, string $last, string $email): User
    {
        return User::firstOrCreate(
            ['username' => 'upeu.' . $nick],
            [
                'first_name' => $first,
                'last_name'  => $last,
                'email'      => $email,
                'password'   => Hash::make($this->password),
                'status'     => 'active',
            ]
        );
    }
}

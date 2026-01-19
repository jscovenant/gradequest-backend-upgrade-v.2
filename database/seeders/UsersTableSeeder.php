<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Using Eloquent model
        // User::factory()->count(1)->create();

        // Alternatively, using DB facade to insert data manually

        DB::table('users')->insert([
            // [
            //     'name' => 'student',
            //     'email' => 'student@example.com',
            //     'password' => Hash::make('password123'),
            //     'username' => 'student',
            //     'role' => 'student',
            //     'phone' => '123456789'
            // ],
            // [
            //     'firstname' => 'ezekiel',
            //     'surname' => 'hunsu',
            //     'email' => 'ezekiel@example.com',
            //     'password' => Hash::make('password123'),
            //     'username' => 'admin',
            //     'role' => 'Admin',
            //     'phone' => '123456789'
            // ],
            // [
            //     'firstname' => 'teacher',
            //     'surname' => 'umah',
            //     'email' => 'teacher@example.com',
            //     'password' => Hash::make('password123'),
            //     'username' => 'teacher',
            //     'role' => 'teacher',
            //     'phone' => '123456789'
            // ]
        ]);
    }
}

<?php

namespace Database\Seeders;

use App\Infrastructure\Persistence\Eloquent\Customer\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        Customer::create([
            'name'                  => 'Cliente Teste',
            'identification'        => 'CPF',
            'identification_number' => 39985941080,
            'email'                 => 'cliente.teste@mecanica.local',
            'status'                => true,
            'create_date'           => now(),
        ]);
    }
}

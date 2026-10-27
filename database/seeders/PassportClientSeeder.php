<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Passport\ClientRepository;
use RuntimeException;

class PassportClientSeeder extends Seeder
{
    /**
     * Cliente de "personal access" do Passport, usado para gerar o token no cadastro e no login.
     * Sem ele, um banco novo não consegue autenticar ninguém.
     */
    public function run(ClientRepository $clients): void
    {
        try {
            $clients->personalAccessClient('users');
        } catch (RuntimeException) {
            $clients->createPersonalAccessGrantClient('Refrata Ótica', 'users');
        }
    }
}

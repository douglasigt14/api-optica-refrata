<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Cria o administrador de uma ótica (o primeiro; os outros podem ser criados na tela Usuários). A ótica vem do Console
 * (`--company` = id daqui, `console_id` ou CNPJ; com uma ótica só, é ela).
 */
#[Signature('admin:create {--company=} {--name=} {--email=} {--password=}')]
#[Description('Cria um usuário Administrador de uma ótica')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $option = (string) $this->option('company');
        $company = $option !== ''
            ? Company::query()->where(fn ($q) => $q->where('console_id', $option)->orWhere('document', preg_replace('/\D/', '', $option) ?: '-')->orWhere('id', ctype_digit($option) ? (int) $option : 0))->first()
            : (Company::count() === 1 ? Company::first() : null);
        if (! $company) {
            $this->error($option !== '' ? 'Ótica não encontrada.' : 'Informe a ótica (--company=id, console_id ou CNPJ). As óticas vêm do Console.');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->option('name') ?: text('Nome', required: true),
            'email' => mb_strtolower(trim($this->option('email') ?: text('E-mail', required: true))),
            'password' => $this->option('password') ?: password('Senha (mín. 8 caracteres)', required: true),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create([...$data, 'group_id' => $company->group_id]);
        $user->companies()->attach($company->id, ['role' => UserRole::Admin]);

        $this->info("Administrador {$user->name} <{$user->email}> criado.");

        return self::SUCCESS;
    }
}

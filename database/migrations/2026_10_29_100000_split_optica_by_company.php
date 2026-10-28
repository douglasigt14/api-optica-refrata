<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Passo 2 do roteiro (fase 4 da plataforma, admin-refrata/PLANO-PLATAFORMA.md): a ótica separada por **empresa**, e a
 * empresa de um **grupo** (o cliente), espelhos do Console (`console_id`). Parceiros, indicações, clientes, atendimentos
 * e situações ganham `company_id` (contatos e histórico seguem a indicação). As cidades atendidas passam a ser de cada
 * ótica (`company_city`; sai `cities.active`). A equipe passa a ser de um grupo, com o papel em cada ótica
 * (`company_user.role`; sai `users.role`). A empresa ganha o código de convite dos parceiros (`invite_code`).
 *
 * Instalação que já tinha dados (a de hoje): tudo vai para a **Otica Exclusive**, do **Grupo Royal** (ligadas ao Console
 * na primeira sincronização, pelo CNPJ), com o convite `EXCLUSIVE`.
 */
return new class extends Migration
{
    /** Tabelas com `company_id`. */
    private const TABLES = ['prospectors', 'leads', 'clients', 'city_visits', 'lead_statuses'];

    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->ulid('console_id')->nullable()->unique();
            $table->string('name');
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained();
            $table->ulid('console_id')->nullable()->unique();
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('document', 14)->unique(); // CNPJ, só dígitos
            $table->string('invite_code', 30)->unique(); // código que o parceiro usa no cadastro (link de convite)
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        Schema::create('company_city', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained();
            $table->timestamps();
            $table->unique(['company_id', 'city_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('id')->constrained();
        });

        $exclusive = null;
        $hasData = DB::table('users')->exists() || collect(self::TABLES)->contains(fn ($name) => $name !== 'lead_statuses' && DB::table($name)->exists());
        if ($hasData) {
            $group = DB::table('groups')->insertGetId(['name' => 'Grupo Royal', 'created_at' => now(), 'updated_at' => now()]);
            $exclusive = DB::table('companies')->insertGetId([
                'group_id' => $group, 'name' => 'Otica Exclusive Ltda', 'trade_name' => 'Otica Exclusive',
                'document' => '34710587000144', 'invite_code' => 'EXCLUSIVE', 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (DB::table('users')->whereNotNull('role')->get(['id', 'role']) as $user) {
                DB::table('company_user')->insert([
                    'company_id' => $exclusive, 'user_id' => $user->id, 'role' => $user->role, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('users')->whereNotNull('role')->update(['group_id' => $group]);
            foreach (DB::table('cities')->where('active', true)->pluck('id') as $city) {
                DB::table('company_city')->insert(['company_id' => $exclusive, 'city_id' => $city, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
        Schema::table('cities', fn (Blueprint $table) => $table->dropColumn('active'));

        foreach (self::TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('company_id')->nullable()->after('id')->constrained());
            if ($exclusive) {
                DB::table($name)->update(['company_id' => $exclusive]);
            } elseif ($name === 'lead_statuses') {
                DB::table($name)->delete(); // banco novo: as situações padrão da migração antiga (cada ótica ganha as suas)
            }
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('company_id')->nullable(false)->change());
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
        }

        Schema::table('cities', fn (Blueprint $table) => $table->boolean('active')->default(false)->after('state'));
        DB::table('cities')->whereIn('id', DB::table('company_city')->select('city_id'))->update(['active' => true]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->nullable()->index()->after('email');
        });
        foreach (DB::table('company_user')->orderBy('id')->get() as $access) {
            DB::table('users')->where('id', $access->user_id)->whereNull('role')->update(['role' => $access->role]);
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('group_id'));
        Schema::dropIfExists('company_city');
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('groups');
    }
};

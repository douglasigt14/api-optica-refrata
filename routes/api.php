<?php

use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\CityVisitController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\PlatformPairingController;
use Illuminate\Support\Facades\Route;

Route::get('/cities', [CityController::class, 'index']);
Route::get('/companies/{code}', [CityController::class, 'company'])->middleware('throttle:30,1');

// Console da Refrata (plataforma): cria/atualiza as óticas (e o grupo delas).
// Pareamento com o Console (tela Módulos de lá): a chave fica no banco, nunca no .env.
Route::get('/platform/pair', [PlatformPairingController::class, 'status'])->middleware('throttle:10,1')->name('platform.pair.status');
Route::post('/platform/pair', [PlatformPairingController::class, 'pair'])->middleware('throttle:10,1')->name('platform.pair');

Route::prefix('/platform')->middleware(['platform', 'throttle:120,1'])->name('platform.')->group(function () {
    Route::get('/ping', [PlatformPairingController::class, 'ping'])->name('ping');
    Route::put('/token', [PlatformPairingController::class, 'rotate'])->name('token');
    Route::put('/companies/{consoleId}', [PlatformController::class, 'upsertCompany'])->name('companies.upsert');
});

Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// App dos parceiros: a ótica é a do cadastro do parceiro.
Route::middleware(['auth:api', 'use-company'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/cities/{city}/visits', [CityVisitController::class, 'index']);
    Route::get('/leads', [LeadController::class, 'index']);
    Route::post('/leads', [LeadController::class, 'store']);
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->whereNumber('lead');
});

/*
| Painel da equipe interna. Todas exigem usuário da equipe; parceiros recebem 403. Cada dado é de uma ótica: a escolhida no
| cabeçalho X-Company (ver UseCompany); quem tem uma ótica só não precisa mandar.
| admin: tudo · field_agent: atendimentos, parceiros (ver e marcar como confiável) e indicações · factory: só entra.
*/
Route::prefix('admin')->name('admin.')->group(function () {
    Route::post('/login', [Admin\AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');

    // Quem é e em que óticas entra: funciona sem ótica escolhida.
    Route::middleware(['auth:api', 'role', 'use-company:optional'])->group(function () {
        Route::get('/me', [Admin\AuthController::class, 'me'])->name('me');
        Route::post('/logout', [Admin\AuthController::class, 'logout'])->name('logout');
    });

    Route::middleware(['auth:api', 'use-company', 'role'])->group(function () {

        Route::middleware('role:admin,field_agent')->group(function () {
            Route::get('/dashboard', Admin\DashboardController::class)->name('dashboard');

            Route::get('/cities', [Admin\CityController::class, 'index'])->name('cities.index');
            Route::get('/prospectors', [Admin\ProspectorController::class, 'index'])->name('prospectors.index');
            Route::get('/prospectors/options', [Admin\ProspectorController::class, 'options'])->name('prospectors.options');
            Route::get('/prospectors/{prospector}', [Admin\ProspectorController::class, 'show'])->whereNumber('prospector')->name('prospectors.show');
            Route::post('/prospectors/{prospector}/trust', [Admin\ProspectorController::class, 'trust'])->name('prospectors.trust');
            Route::delete('/prospectors/{prospector}/trust', [Admin\ProspectorController::class, 'untrust'])->name('prospectors.untrust');

            Route::get('/city-visits', [Admin\CityVisitController::class, 'index'])->name('city-visits.index');
            Route::get('/city-visits/{cityVisit}', [Admin\CityVisitController::class, 'show'])->name('city-visits.show');

            Route::get('/lead-statuses', [Admin\LeadStatusController::class, 'index'])->name('lead-statuses.index');
            Route::get('/leads', [Admin\LeadController::class, 'index'])->name('leads.index');
            Route::get('/leads/{lead}', [Admin\LeadController::class, 'show'])->name('leads.show');
            Route::patch('/leads/{lead}/schedule', [Admin\LeadController::class, 'schedule'])->name('leads.schedule');
            Route::patch('/leads/{lead}/status', [Admin\LeadController::class, 'updateStatus'])->name('leads.status');
            Route::post('/leads/{lead}/contacts', [Admin\LeadController::class, 'storeContact'])->name('leads.contacts.store');
        });

        Route::middleware('role:admin')->group(function () {
            Route::post('/city-visits', [Admin\CityVisitController::class, 'store'])->name('city-visits.store');
            Route::patch('/city-visits/{cityVisit}', [Admin\CityVisitController::class, 'update'])->name('city-visits.update');

            Route::post('/prospectors', [Admin\ProspectorController::class, 'store'])->name('prospectors.store');
            Route::patch('/prospectors/{prospector}', [Admin\ProspectorController::class, 'update'])->name('prospectors.update');
            Route::post('/prospectors/{prospector}/block', [Admin\ProspectorController::class, 'block'])->name('prospectors.block');
            Route::delete('/prospectors/{prospector}/block', [Admin\ProspectorController::class, 'unblock'])->name('prospectors.unblock');
            Route::put('/prospectors/{prospector}/password', [Admin\ProspectorController::class, 'updatePassword'])->name('prospectors.password');

            Route::apiResource('users', Admin\TeamUserController::class)->except('show');

            // Cidades atendidas pela ótica (busca entre todos os municípios; marcar e desmarcar).
            Route::get('/cities/search', [Admin\CityController::class, 'search'])->name('cities.search');
            Route::put('/cities/{city}', [Admin\CityController::class, 'attach'])->whereNumber('city')->name('cities.attach');
            Route::delete('/cities/{city}', [Admin\CityController::class, 'detach'])->whereNumber('city')->name('cities.detach');
            Route::apiResource('lead-statuses', Admin\LeadStatusController::class)->only(['store', 'update', 'destroy'])->parameters(['lead-statuses' => 'leadStatus']);
        });
    });
});

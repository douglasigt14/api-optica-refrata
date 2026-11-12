<?php

use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\CityVisitController;
use App\Http\Controllers\Api\LabIntegrationController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\PlatformPairingController;
use App\Http\Controllers\Api\PlatformUserController;
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
    Route::put('/companies/{consoleId}/labs/{labConsoleId}', [PlatformController::class, 'labConnection'])->name('companies.lab');
    Route::put('/companies/{consoleId}/labs/{labConsoleId}/token', [PlatformController::class, 'labToken'])->name('companies.lab-token');
    // Ligação com o financeiro (vendas, 07/11/2026): endereço e chave gerada lá, gravados pelo Console.
    Route::put('/companies/{consoleId}/financial-connection', [PlatformController::class, 'financialConnection'])->name('companies.financial-connection');
    // Usuários da equipe vindos do Console (passo 7 do roteiro).
    Route::put('/users/{consoleId}', [PlatformUserController::class, 'upsert'])->name('users.upsert');
    // Login único pelo painel único (passo 4): o Console pede o token da pessoa depois de conferir a senha.
    Route::post('/users/{consoleId}/token', [PlatformUserController::class, 'token'])->name('users.token');
    Route::get('/groups/{groupConsoleId}/users', [PlatformUserController::class, 'index'])->name('groups.users');
});

// Aviso de situação dos laboratórios do grupo (passo 3 do roteiro). Chave gravada pelo Console.
Route::post('/integration/lab-orders/status', [LabIntegrationController::class, 'status'])->middleware(['lab-integration', 'throttle:120,1'])->name('integration.lab-orders.status');
// Atendimentos da ótica, para o laboratório ligar o lote a um deles (mesma chave do aviso).
Route::get('/integration/city-visits', [LabIntegrationController::class, 'cityVisits'])->middleware(['lab-integration', 'throttle:120,1'])->name('integration.city-visits');

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
// Logo das impressões da ótica por link assinado (`print_logo_url`), sem token.
Route::get('/companies/{company}/print-logo', [Admin\SettingsController::class, 'printLogo'])->middleware('signed')->name('companies.print-logo');

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
            // Configurações da ótica: o atendente lê (a logo sai no cupom que ele imprime).
            Route::get('/settings', [Admin\SettingsController::class, 'show'])->name('settings.show');

            Route::get('/cities', [Admin\CityController::class, 'index'])->name('cities.index');
            Route::get('/prospectors', [Admin\ProspectorController::class, 'index'])->name('prospectors.index');
            Route::get('/prospectors/options', [Admin\ProspectorController::class, 'options'])->name('prospectors.options');
            Route::get('/prospectors/{prospector}', [Admin\ProspectorController::class, 'show'])->whereNumber('prospector')->name('prospectors.show');
            Route::post('/prospectors/{prospector}/trust', [Admin\ProspectorController::class, 'trust'])->name('prospectors.trust');
            Route::delete('/prospectors/{prospector}/trust', [Admin\ProspectorController::class, 'untrust'])->name('prospectors.untrust');
            // Desde 12/11/2026 o atendente também cadastra, edita, bloqueia e troca a senha do parceiro.
            Route::post('/prospectors', [Admin\ProspectorController::class, 'store'])->name('prospectors.store');
            Route::patch('/prospectors/{prospector}', [Admin\ProspectorController::class, 'update'])->name('prospectors.update');
            Route::post('/prospectors/{prospector}/block', [Admin\ProspectorController::class, 'block'])->name('prospectors.block');
            Route::delete('/prospectors/{prospector}/block', [Admin\ProspectorController::class, 'unblock'])->name('prospectors.unblock');
            Route::put('/prospectors/{prospector}/password', [Admin\ProspectorController::class, 'updatePassword'])->name('prospectors.password');

            Route::get('/city-visits', [Admin\CityVisitController::class, 'index'])->name('city-visits.index');
            Route::get('/city-visits/{cityVisit}', [Admin\CityVisitController::class, 'show'])->name('city-visits.show');
            // Desde 12/11/2026 o atendente também cria e edita atendimentos.
            Route::post('/city-visits', [Admin\CityVisitController::class, 'store'])->name('city-visits.store');
            Route::patch('/city-visits/{cityVisit}', [Admin\CityVisitController::class, 'update'])->name('city-visits.update');

            Route::get('/lead-statuses', [Admin\LeadStatusController::class, 'index'])->name('lead-statuses.index');
            Route::get('/leads', [Admin\LeadController::class, 'index'])->name('leads.index');
            Route::post('/leads', [Admin\LeadController::class, 'store'])->name('leads.store');
            Route::get('/leads/{lead}', [Admin\LeadController::class, 'show'])->name('leads.show');
            Route::patch('/leads/{lead}/schedule', [Admin\LeadController::class, 'schedule'])->name('leads.schedule');
            Route::patch('/leads/{lead}/status', [Admin\LeadController::class, 'updateStatus'])->name('leads.status');
            Route::post('/leads/{lead}/contacts', [Admin\LeadController::class, 'storeContact'])->name('leads.contacts.store');

            // Clientes e pedidos aos laboratórios do grupo (passo 3 do roteiro).
            Route::apiResource('clients', Admin\ClientController::class)->except('destroy');
            Route::get('/labs', [Admin\LabOrderController::class, 'labs'])->name('labs.index');
            Route::get('/labs/{labConnection}/catalog', [Admin\LabOrderController::class, 'catalog'])->name('labs.catalog');
            Route::get('/lab-orders', [Admin\LabOrderController::class, 'index'])->name('lab-orders.index');
            // Vendas — a promissória (07/11/2026): rascunho, lançar (gera o a receber no Financeiro), pagamentos e entregar.
            Route::get('/sales', [Admin\SaleController::class, 'index'])->name('sales.index');
            Route::post('/sales', [Admin\SaleController::class, 'store'])->name('sales.store');
            Route::get('/sales/{sale}', [Admin\SaleController::class, 'show'])->name('sales.show');
            Route::patch('/sales/{sale}', [Admin\SaleController::class, 'update'])->name('sales.update');
            Route::post('/sales/{sale}/payments', [Admin\SaleController::class, 'addPayment'])->name('sales.payments.store');
            Route::delete('/sales/{sale}/payments/{payment}', [Admin\SaleController::class, 'removePayment'])->name('sales.payments.destroy');
            Route::post('/sales/{sale}/orders', [Admin\SaleController::class, 'attachOrder'])->name('sales.orders.store');
            Route::post('/sales/{sale}/launch', [Admin\SaleController::class, 'launch'])->name('sales.launch');
            Route::post('/sales/{sale}/deliver', [Admin\SaleController::class, 'deliver'])->name('sales.deliver');
            // Cancelar ficha: o atendente também (12/11/2026).
            Route::post('/sales/{sale}/cancel', [Admin\SaleController::class, 'cancel'])->name('sales.cancel');
            // Pedidos por atendimento (07/11/2026): os atendimentos que recebem pedidos, enviar todos juntos e encerrar.
            Route::get('/lab-order-visits', [Admin\LabOrderController::class, 'visits'])->name('lab-order-visits.index');
            Route::get('/lab-order-visits/{cityVisit}', [Admin\LabOrderController::class, 'visit'])->name('lab-order-visits.show');
            Route::post('/lab-order-visits/{cityVisit}/send', [Admin\LabOrderController::class, 'sendVisit'])->name('lab-order-visits.send');
            Route::post('/lab-order-visits/{cityVisit}/close', [Admin\LabOrderController::class, 'closeOrders'])->name('lab-order-visits.close');
            Route::delete('/lab-order-visits/{cityVisit}/close', [Admin\LabOrderController::class, 'reopenOrders'])->name('lab-order-visits.reopen');
            Route::put('/lab-order-visits/{cityVisit}/delivery', [Admin\SaleController::class, 'visitDelivery'])->name('lab-order-visits.delivery');
            // Relatório das fichas do atendimento (09/11/2026): pago, como e o que fica para a entrega.
            // Senhas da fila do atendimento (12/11/2026): emitir (N001…/P001…) e chamar.
            Route::get('/lab-order-visits/{cityVisit}/tickets', [Admin\QueueTicketController::class, 'index'])->name('lab-order-visits.tickets.index');
            Route::post('/lab-order-visits/{cityVisit}/tickets', [Admin\QueueTicketController::class, 'store'])->name('lab-order-visits.tickets.store');
            Route::post('/lab-order-visits/{cityVisit}/tickets/call-next', [Admin\QueueTicketController::class, 'callNext'])->name('lab-order-visits.tickets.call-next');
            Route::post('/lab-order-visits/{cityVisit}/tickets/{ticket}/call', [Admin\QueueTicketController::class, 'call'])->whereNumber('ticket')->name('lab-order-visits.tickets.call');
            Route::get('/lab-order-visits/{cityVisit}/sales-report', [Admin\SaleController::class, 'visitReport'])->name('lab-order-visits.sales-report');
            Route::post('/lab-orders', [Admin\LabOrderController::class, 'store'])->name('lab-orders.store');
            // Enviar os pendentes marcados (antes de {labOrder}, para "send" não virar id).
            Route::post('/lab-orders/send', [Admin\LabOrderController::class, 'sendMany'])->name('lab-orders.send-many');
            Route::get('/lab-orders/{labOrder}', [Admin\LabOrderController::class, 'show'])->name('lab-orders.show');
            // Editar o pendente: POST (multipart, por causa da receita).
            Route::post('/lab-orders/{labOrder}', [Admin\LabOrderController::class, 'update'])->name('lab-orders.update');
            Route::post('/lab-orders/{labOrder}/send', [Admin\LabOrderController::class, 'send'])->name('lab-orders.send');
            Route::post('/lab-orders/{labOrder}/refresh', [Admin\LabOrderController::class, 'refresh'])->name('lab-orders.refresh');
            Route::post('/lab-orders/{labOrder}/cancel', [Admin\LabOrderController::class, 'cancel'])->name('lab-orders.cancel');
        });

        Route::middleware('role:admin')->group(function () {
            Route::delete('/clients/{client}', [Admin\ClientController::class, 'destroy'])->name('clients.destroy');
            // Configurações das vendas: a conta do Financeiro de cada forma de pagamento.
            // Zerar a fila do atendimento (12/11/2026): apaga as senhas e a contagem volta para 001.
            Route::delete('/lab-order-visits/{cityVisit}/tickets', [Admin\QueueTicketController::class, 'reset'])->name('lab-order-visits.tickets.reset');
            Route::post('/settings/print-logo', [Admin\SettingsController::class, 'storePrintLogo'])->name('settings.print-logo.store');
            Route::delete('/settings/print-logo', [Admin\SettingsController::class, 'destroyPrintLogo'])->name('settings.print-logo.destroy');
            Route::get('/sale-settings', [Admin\SaleController::class, 'settings'])->name('sale-settings.show');
            Route::put('/sale-settings', [Admin\SaleController::class, 'saveSettings'])->name('sale-settings.update');

            Route::apiResource('users', Admin\TeamUserController::class)->except('show');

            // Cidades atendidas pela ótica (busca entre todos os municípios; marcar e desmarcar).
            Route::get('/cities/search', [Admin\CityController::class, 'search'])->name('cities.search');
            Route::put('/cities/{city}', [Admin\CityController::class, 'attach'])->whereNumber('city')->name('cities.attach');
            Route::delete('/cities/{city}', [Admin\CityController::class, 'detach'])->whereNumber('city')->name('cities.detach');
            Route::apiResource('lead-statuses', Admin\LeadStatusController::class)->only(['store', 'update', 'destroy'])->parameters(['lead-statuses' => 'leadStatus']);
        });
    });
});

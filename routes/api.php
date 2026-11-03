<?php

use App\Interfaces\Http\Controllers\Auth\LoginController;
use App\Interfaces\Http\Controllers\Auth\LogoutController;
use App\Interfaces\Http\Controllers\Auth\MeController;
use App\Interfaces\Http\Controllers\Auth\RefreshController;
use App\Interfaces\Http\Controllers\Customer\CreateCustomerController;
use App\Interfaces\Http\Controllers\Customer\DeleteCustomerController;
use App\Interfaces\Http\Controllers\Customer\FindCustomerController;
use App\Interfaces\Http\Controllers\Customer\ListCustomersController;
use App\Interfaces\Http\Controllers\Customer\UpdateCustomerController;
use App\Interfaces\Http\Controllers\Health\LivenessController;
use App\Interfaces\Http\Controllers\Health\ReadinessController;
use App\Interfaces\Http\Controllers\Product\CreateProductController;
use App\Interfaces\Http\Controllers\Product\DecreaseStockController;
use App\Interfaces\Http\Controllers\Product\DeleteProductController;
use App\Interfaces\Http\Controllers\Product\FindProductController;
use App\Interfaces\Http\Controllers\Product\IncreaseStockController;
use App\Interfaces\Http\Controllers\Product\ListProductsController;
use App\Interfaces\Http\Controllers\Product\UpdateProductController;
use App\Interfaces\Http\Controllers\Service\CreateServiceController;
use App\Interfaces\Http\Controllers\Service\DeleteServiceController;
use App\Interfaces\Http\Controllers\Service\FindServiceController;
use App\Interfaces\Http\Controllers\Service\ListServicesController;
use App\Interfaces\Http\Controllers\Service\UpdateServiceController;
use App\Interfaces\Http\Controllers\ServiceOrder\AddServiceOrderItemsController;
use App\Interfaces\Http\Controllers\ServiceOrder\ApproveServiceOrderController;
use App\Interfaces\Http\Controllers\ServiceOrder\CreateServiceOrderController;
use App\Interfaces\Http\Controllers\ServiceOrder\DeleteServiceOrderController;
use App\Interfaces\Http\Controllers\ServiceOrder\FindServiceOrderController;
use App\Interfaces\Http\Controllers\ServiceOrder\ListServiceOrdersController;
use App\Interfaces\Http\Controllers\ServiceOrder\RejectServiceOrderController;
use App\Interfaces\Http\Controllers\ServiceOrder\ServiceOrderTrackingController;
use App\Interfaces\Http\Controllers\ServiceOrder\UpdateServiceOrderStatusController;
use App\Interfaces\Http\Controllers\User\CreateUserController;
use App\Interfaces\Http\Controllers\User\DeleteUserController;
use App\Interfaces\Http\Controllers\User\FindUserController;
use App\Interfaces\Http\Controllers\User\ListUsersController;
use App\Interfaces\Http\Controllers\User\UpdateUserController;
use App\Interfaces\Http\Controllers\Vehicule\CreateVehiculeController;
use App\Interfaces\Http\Controllers\Vehicule\DeleteVehiculeController;
use App\Interfaces\Http\Controllers\Vehicule\FindVehiculeByCustomerController;
use App\Interfaces\Http\Controllers\Vehicule\FindVehiculeController;
use App\Interfaces\Http\Controllers\Vehicule\ListVehiculesController;
use App\Interfaces\Http\Controllers\Vehicule\UpdateVehiculeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas da API
|--------------------------------------------------------------------------
|
| Estrutura de autenticação:
|   - Rotas públicas: apenas POST /auth/login
|   - Rotas protegidas: middleware 'jwt' valida o token Bearer
|   - Rotas restritas por role: middleware 'admin' ou 'manager'
|
| Hierarquia de permissões:
|   Administrador > Gerente > Mecânico
|
*/

// Sondas de saúde, usadas pelas probes do Kubernetes e pelo monitoramento sintético.
// Sem autenticação: quem pergunta é o kubelet, que não tem como se autenticar. Nenhuma das
// duas revela informação interna — a resposta é "ok" ou o nome da checagem que falhou.
Route::prefix('health')->group(function () {
    Route::get('/', LivenessController::class);
    Route::get('/ready', ReadinessController::class);
});

// Rota de teste (sem autenticação)
Route::prefix('test')->group(function () {
    Route::get('/', fn() => response()->json(['status' => 'success', 'mensagem' => 'Working oto API! Marcel']));
});

Route::prefix('service-orders')->group(function () {
    Route::get('/approve', ApproveServiceOrderController::class);
    Route::get('/reject', RejectServiceOrderController::class);
});

// Rota exclusiva de cliente: autenticado via CPF pela auth-lambda (ver
// CustomerJwtMiddleware). Só o dono do veículo da ordem consegue consultar.
Route::middleware('customer.jwt')->prefix('service-orders')->group(function () {
    Route::get('/{orderId}/track/status', ServiceOrderTrackingController::class);
});

// -----------------------------------------------------------------------------
// Autenticação (rotas públicas)
// -----------------------------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('login', LoginController::class);

    // Rotas protegidas por JWT
    Route::middleware('jwt')->group(function () {
        Route::get('me', MeController::class);
        Route::post('logout', LogoutController::class);
    });

    Route::post('refresh', RefreshController::class);
});

// -----------------------------------------------------------------------------
// Recursos protegidos (requerem JWT válido)
// -----------------------------------------------------------------------------
Route::middleware('jwt')->group(function () {

    // CRUD de usuários — apenas Admin e Gerente podem gerenciar usuários
    Route::middleware('manager')->prefix('users')->group(function () {
        Route::get('/', ListUsersController::class);
        Route::get('/{id}', FindUserController::class);

        // Criar e deletar usuários: apenas Administrador
        Route::middleware('admin')->group(function () {
            Route::post('/', CreateUserController::class);
            Route::delete('/{id}', DeleteUserController::class);
        });

        // Atualizar: Admin ou Gerente
        Route::put('/{id}', UpdateUserController::class);
    });

    // CRUD de clientes — apenas Admin e Gerente
    Route::middleware('manager')->prefix('customers')->group(function () {
        Route::get('/', ListCustomersController::class);
        Route::get('/{id}', FindCustomerController::class);
        Route::put('/{id}', UpdateCustomerController::class);

        Route::middleware('admin')->group(function () {
            Route::post('/', CreateCustomerController::class);
            Route::delete('/{id}', DeleteCustomerController::class);
        });
    });

    // Listagem de veículos por cliente
    Route::middleware('manager')->get(
        '/customers/{customerId}/vehicules',
        FindVehiculeByCustomerController::class
    );

    // CRUD de veículos — apenas Admin e Gerente
    Route::middleware('manager')->prefix('vehicules')->group(function () {
        Route::get('/', ListVehiculesController::class);
        Route::get('/{id}', FindVehiculeController::class);
        Route::put('/{id}', UpdateVehiculeController::class);

        Route::middleware('admin')->group(function () {
            Route::post('/', CreateVehiculeController::class);
            Route::delete('/{id}', DeleteVehiculeController::class);
        });
    });

    // Ordens de Serviço — Admin e Gerente listam/buscam/excluem; todos criam
    Route::middleware('manager')->prefix('service-orders')->group(function () {
        Route::get('/', ListServiceOrdersController::class);
        Route::get('/{id}', FindServiceOrderController::class);
        Route::post('/', CreateServiceOrderController::class);
        Route::post('/{id}/items', AddServiceOrderItemsController::class);

        Route::patch('/{id}/status', UpdateServiceOrderStatusController::class);

        Route::middleware('admin')->group(function () {
            Route::delete('/{id}', DeleteServiceOrderController::class);
        });
    });

    // CRUD de serviços da mecânica — apenas Admin e Gerente
    Route::middleware('manager')->prefix('services')->group(function () {
        Route::get('/', ListServicesController::class);
        Route::get('/{id}', FindServiceController::class);
        Route::put('/{id}', UpdateServiceController::class);

        // Criar e excluir: apenas Administrador
        Route::middleware('admin')->group(function () {
            Route::post('/', CreateServiceController::class);
            Route::delete('/{id}', DeleteServiceController::class);
        });
    });

    // CRUD de Produtos — apenas Admin e Gerente
    Route::middleware('manager')->prefix('products')->group(function () {
        Route::get('/', ListProductsController::class);
        Route::get('/{id}', FindProductController::class);
        Route::put('/{id}', UpdateProductController::class);

        // Atualização de estoque
        Route::patch('/{id}/increase-stock', IncreaseStockController::class);
        Route::patch('/{id}/decrease-stock', DecreaseStockController::class);

        Route::middleware('admin')->group(function () {
            Route::post('/', CreateProductController::class);
            Route::delete('/{id}', DeleteProductController::class);
        });
    });
});

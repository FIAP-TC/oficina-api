<?php

namespace App\Providers;

use App\Domain\ServiceOrder\Notifications\OrderApprovalNotifierInterface;
use App\Domain\ServiceOrder\Security\OrderApprovalTokenSigner;
use App\Infrastructure\Notifications\OrderApprovalMailNotifier;
use App\Infrastructure\Observability\RequestContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(OrderApprovalTokenSigner::class, function ($app) {
            return new OrderApprovalTokenSigner(config('services.order_approval.secret'));
        });

        $this->app->bind(OrderApprovalNotifierInterface::class, OrderApprovalMailNotifier::class);

        // Singleton de propósito: o middleware de observabilidade escreve o identificador
        // da requisição e o processador do Monolog lê a mesma instância na hora de gravar
        // cada linha de log.
        $this->app->singleton(RequestContext::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}

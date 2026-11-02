<?php

namespace App\Interfaces\Http\Controllers\Health;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Prova de prontidão: este pod consegue **atender** uma requisição agora?
 *
 * Diferente do LivenessController, aqui as dependências são checadas. A probe de readiness
 * do Kubernetes responde a "mando tráfego para este pod?" — e um pod que não alcança o banco
 * não tem como responder nada útil, então deve sair do balanceamento sem ser reiniciado.
 *
 * Cada pod checa o que **ele** precisa, e só isso. O Nginx continua com uma probe que olha
 * só o Nginx: se ele ficasse "não pronto" porque a API caiu, os pods dele sairiam do
 * LoadBalancer e o cliente receberia falha de conexão em vez de um 502/503, que ao menos diz
 * o que aconteceu.
 *
 * A checagem é barata de propósito: a probe roda a cada 10 segundos em cada pod.
 */
final class ReadinessController
{
    public function __invoke(): JsonResponse
    {
        $checks = ['database' => $this->database()];

        $ready = !in_array(false, $checks, true);

        return new JsonResponse(
            ['status' => $ready ? 'ok' : 'unavailable', 'checks' => $checks],
            $ready ? 200 : 503,
        );
    }

    /**
     * `SELECT 1` é o suficiente: confirma que há conexão aberta e que o servidor responde,
     * sem tocar em nenhuma tabela da aplicação.
     */
    private function database(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}

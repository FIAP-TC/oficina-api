<?php

namespace Tests\Feature\Observability;

use App\Interfaces\Http\Middleware\RequestLoggerMiddleware;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Garante o conteúdo da linha de log que o RequestLoggerMiddleware grava por requisição.
 *
 * O que importa aqui é o campo `route`: é por ele que os dashboards agregam latência, e
 * mudar o que vai nele muda o nome das séries no Grafana.
 */
class RequestLogTest extends TestCase
{
    public function test_a_rota_registrada_e_o_padrao_e_nao_o_caminho_concreto(): void
    {
        $contexto = $this->contextoDoLogDe('/api/test');

        $this->assertSame('api/test', $contexto['route']);
        $this->assertSame('/api/test', $contexto['path']);
        $this->assertSame('http_request', $contexto['event']);
        $this->assertIsFloat($contexto['duration_ms']);
    }

    public function test_requisicao_sem_rota_correspondente_e_registrada_como_unmatched(): void
    {
        // Não pode ser `null`: no LogQL o `| json` converte null em string vazia e o
        // `by (route)` agruparia estas linhas numa série sem nome no gráfico.
        $contexto = $this->contextoDoLogDe('/api/rota-que-nao-existe');

        $this->assertSame(RequestLoggerMiddleware::UNMATCHED_ROUTE, $contexto['route']);
        $this->assertSame(404, $contexto['status']);
    }

    /**
     * Faz a requisição e devolve o contexto da linha de log gravada em terminate().
     *
     * @return array<string, mixed>
     */
    private function contextoDoLogDe(string $uri): array
    {
        $capturado = [];

        Log::shouldReceive('log')
            ->andReturnUsing(function (string $level, string $message, array $context) use (&$capturado): void {
                if (($context['event'] ?? null) === 'http_request') {
                    $capturado = $context;
                }
            });

        $this->getJson($uri);

        $this->assertNotEmpty($capturado, 'nenhuma linha de http_request foi gravada');

        return $capturado;
    }
}

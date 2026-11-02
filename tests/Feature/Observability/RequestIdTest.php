<?php

namespace Tests\Feature\Observability;

use App\Infrastructure\Observability\RequestContext;
use App\Interfaces\Http\Middleware\RequestIdMiddleware;
use Tests\TestCase;

class RequestIdTest extends TestCase
{
    public function test_a_resposta_sempre_traz_o_identificador_da_requisicao(): void
    {
        $response = $this->getJson('/api/test');

        $response->assertStatus(200);
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9_.:-]{8,64}$/',
            (string) $response->headers->get(RequestIdMiddleware::HEADER)
        );
    }

    public function test_reaproveita_o_identificador_que_o_nginx_enviou(): void
    {
        $response = $this->call('GET', '/api/test', [], [], [], ['REQUEST_ID' => 'a1b2c3d4e5f60718']);

        $response->assertStatus(200);
        $this->assertSame(
            'a1b2c3d4e5f60718',
            $response->headers->get(RequestIdMiddleware::HEADER)
        );
        // O mesmo valor fica disponível para o processador do log.
        $this->assertSame('a1b2c3d4e5f60718', app(RequestContext::class)->requestId());
    }

    public function test_reaproveita_o_cabecalho_do_cliente_quando_nao_ha_nginx_na_frente(): void
    {
        $response = $this->getJson('/api/test', [RequestIdMiddleware::HEADER => 'chamada-externa-42']);

        $response->assertStatus(200);
        $this->assertSame(
            'chamada-externa-42',
            $response->headers->get(RequestIdMiddleware::HEADER)
        );
    }

    public function test_descarta_identificador_de_formato_invalido(): void
    {
        // Curto demais, e com caractere que não pode ir para um log.
        $response = $this->getJson('/api/test', [RequestIdMiddleware::HEADER => 'x" injetado']);

        $response->assertStatus(200);
        $received = (string) $response->headers->get(RequestIdMiddleware::HEADER);

        $this->assertNotSame('x" injetado', $received);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.:-]{8,64}$/', $received);
    }
}

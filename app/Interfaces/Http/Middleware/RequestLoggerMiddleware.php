<?php

namespace App\Interfaces\Http\Middleware;

use App\Infrastructure\Observability\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Registra uma linha de log por requisição atendida.
 *
 * Isto não substitui o access log do Nginx, que mede a latência vista de fora (incluindo
 * rede e fila do PHP-FPM) e registra até o que nem chega na aplicação. A linha daqui
 * acrescenta o que só a aplicação sabe:
 *
 * - `route`: o padrão da rota (`api/customers/{id}`) em vez do caminho concreto
 *   (`/api/customers/42`). É isso que permite agregar latência por endpoint no Grafana —
 *   com o caminho concreto cada id viraria uma série diferente.
 * - `user_id`: quem fez a chamada.
 * - o mesmo `request_id` do Nginx, que o processador do log acrescenta sozinho.
 *
 * O registro acontece em terminate(), ou seja, depois de a resposta já ter sido enviada ao
 * cliente: observabilidade não deve adicionar latência à requisição.
 */
class RequestLoggerMiddleware
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }

    public function terminate(Request $request, mixed $response): void
    {
        $status = $response instanceof Response ? $response->getStatusCode() : 0;
        $path = '/' . ltrim($request->path(), '/');
        $route = $request->route();

        Log::log($this->levelFor($status), sprintf('%s %s %d', $request->getMethod(), $path, $status), [
            'event' => 'http_request',
            'method' => $request->getMethod(),
            'path' => $path,
            'route' => $route instanceof Route ? $route->uri() : null,
            'status' => $status,
            'duration_ms' => round($this->context->elapsedMs(), 1),
            // Mesmo nome de campo que o access log do Nginx usa.
            'client_ip' => $request->ip(),
            // Limite de tamanho porque o User-Agent é texto livre vindo do cliente.
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 200),
            'user_id' => $this->userId(),
        ]);
    }

    /**
     * Erro do servidor é `error`; erro do cliente (401, 404, 422) é `warning`, porque é
     * esperado e não indica defeito; o resto é `info`.
     */
    private function levelFor(int $status): string
    {
        return match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default => 'info',
        };
    }

    /**
     * Usa explicitamente o guard 'api' (JWT): o guard padrão da aplicação é de sessão e
     * numa requisição de API não há sessão iniciada.
     */
    private function userId(): int|string|null
    {
        try {
            $id = auth('api')->id();

            return is_int($id) || is_string($id) ? $id : null;
        } catch (Throwable) {
            return null;
        }
    }
}

<?php

namespace App\Interfaces\Http\Middleware;

use App\Infrastructure\Observability\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante que toda requisição tenha um identificador de correlação.
 *
 * O identificador aparece no log do Nginx, em todas as linhas de log da aplicação durante
 * aquela requisição e no cabeçalho X-Request-Id da resposta. Com ele, um cliente que
 * recebeu um erro pode informar o identificador e o time encontra no Grafana exatamente o
 * que aconteceu, sem precisar adivinhar horário.
 *
 * De onde vem o valor, em ordem de preferência:
 *
 * 1. $_SERVER['REQUEST_ID'], que o Nginx envia junto da requisição. Essa é a fonte boa:
 *    o Nginx já validou o formato e gravou o mesmo valor no access log dele.
 * 2. O cabeçalho X-Request-Id, para quando não há Nginx na frente (teste automatizado,
 *    `php artisan serve`).
 * 3. Um UUID gerado aqui.
 *
 * O valor recebido de fora é sempre validado: ele vai parar em log e em cabeçalho de
 * resposta, e texto arbitrário de cliente em log é injeção de conteúdo.
 */
class RequestIdMiddleware
{
    public const HEADER = 'X-Request-Id';

    /**
     * Mesmo formato aceito pelo `map $http_x_request_id` da configuração do Nginx
     * (docker/nginx/sites-enabled/laravel.conf). Se mudar aqui, mude lá.
     */
    private const PATTERN = '/^[A-Za-z0-9_.:-]{8,64}$/';

    public function __construct(private readonly RequestContext $context)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $requestId = $this->firstValid([
            $request->server('REQUEST_ID'),
            $request->header(self::HEADER),
        ]) ?? (string) Str::uuid();

        $this->context->startRequest($requestId);

        // Normaliza o cabeçalho da requisição para que qualquer código adiante (e um
        // eventual cliente HTTP chamando outro serviço) veja o mesmo valor do log.
        $request->headers->set(self::HEADER, $requestId);

        $response = $next($request);

        if ($response instanceof Response) {
            $response->headers->set(self::HEADER, $requestId);
        }

        return $response;
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private function firstValid(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match(self::PATTERN, $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }
}

<?php

namespace App\Infrastructure\Observability\Logging;

use App\Infrastructure\Observability\RequestContext;
use Monolog\Processor\ProcessorInterface;

/**
 * Acrescenta a toda linha de log o identificador da requisição e a identificação do
 * serviço.
 *
 * É o que permite, no Grafana, partir de um erro e ver todas as outras linhas daquela
 * mesma requisição — inclusive a linha do Nginx, que carrega o mesmo request_id.
 *
 * O RequestContext é injetado como singleton: quando o canal de log é construído o
 * middleware pode ainda não ter definido o identificador, mas como é sempre a mesma
 * instância, o valor lido na hora de gravar já está preenchido.
 */
class RequestContextProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly string $service,
        private readonly string $environment,
    ) {
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function __invoke(array $record): array
    {
        $extra = is_array($record['extra'] ?? null) ? $record['extra'] : [];

        $extra['service'] = $this->service;
        $extra['env'] = $this->environment;

        $requestId = $this->context->requestId();

        // Em comando de console não existe requisição; melhor não ter o campo do que
        // tê-lo vazio (no Loki um campo vazio ainda conta como campo e atrapalha filtro).
        if ($requestId !== null) {
            $extra['request_id'] = $requestId;
        }

        $record['extra'] = $extra;

        return $record;
    }
}

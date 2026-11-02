<?php

namespace App\Infrastructure\Observability\Logging;

use App\Infrastructure\Observability\RequestContext;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Monta o canal de log "json" (ver config/logging.php).
 *
 * Três decisões estão concentradas aqui:
 *
 * 1. A saída é `php://stderr`, e não um arquivo em storage/logs. No Kubernetes o que o
 *    kubelet grava em /var/log/containers — e o Grafana Alloy lê — é exatamente a saída
 *    padrão do container. Log em arquivo dentro do container fica invisível para o agente
 *    e ainda enche o disco do nó.
 *
 * 2. O formato é uma linha de JSON por registro, para o Loki saber separar os campos
 *    (`| json` no LogQL) em vez de tratar a linha como texto corrido.
 *
 * 3. Os dois processadores: um enriquece (identificador da requisição, serviço, ambiente)
 *    e o outro mascara. A ordem do array é a ordem de execução no Monolog, então o
 *    mascarador é o último e também limpa o que o primeiro acrescentou.
 */
class JsonLoggerFactory
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly SensitiveDataMasker $masker,
    ) {
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $handler = new StreamHandler('php://stderr', $this->level($config));
        $handler->setFormatter(new JsonLineFormatter($this->masker));

        return new Logger(
            is_string($config['name'] ?? null) ? $config['name'] : 'api',
            [$handler],
            [
                new RequestContextProcessor(
                    $this->context,
                    (string) config('app.name', 'api'),
                    (string) config('app.env', 'production'),
                ),
                new MaskSensitiveDataProcessor($this->masker),
            ],
        );
    }

    /**
     * Converte LOG_LEVEL no nível correspondente do Monolog.
     *
     * Um valor inválido não pode derrubar a aplicação — sem log não há como investigar nem
     * o próprio erro de configuração — então qualquer coisa fora da lista cai em "info",
     * que é o nível de produção. Este projeto já teve `LOG_LEVEL=dev` configurado, que não
     * é um nível do Monolog.
     *
     * @param  array<string, mixed>  $config
     *
     * @phpstan-return 100|200|250|300|400|500|550|600 os níveis do Monolog, que o
     *                 StreamHandler aceita como valor exato e não como int qualquer
     */
    private function level(array $config): int
    {
        $level = is_string($config['level'] ?? null) ? strtolower($config['level']) : 'info';

        return match ($level) {
            'debug' => Logger::DEBUG,
            'notice' => Logger::NOTICE,
            'warning' => Logger::WARNING,
            'error' => Logger::ERROR,
            'critical' => Logger::CRITICAL,
            'alert' => Logger::ALERT,
            'emergency' => Logger::EMERGENCY,
            default => Logger::INFO,
        };
    }
}

<?php

namespace App\Infrastructure\Observability;

/**
 * Guarda os dados da requisição em andamento que precisam ser lidos de outros lugares
 * da aplicação — hoje o identificador de correlação e o instante em que a requisição
 * começou.
 *
 * É registrado como singleton (ver AppServiceProvider), então o middleware escreve e o
 * processador do log lê sempre a mesma instância, sem precisar passar o valor de mão em
 * mão por toda a pilha. Em comandos de console e em testes nenhum middleware roda, então
 * requestId() devolve null e o campo simplesmente não aparece no log.
 */
class RequestContext
{
    private ?string $requestId = null;

    private ?float $startedAt = null;

    /**
     * Marca o início da requisição.
     *
     * Usa a constante LARAVEL_START (definida em public/index.php, antes de o framework
     * carregar) para que a duração registrada inclua o tempo de boot do Laravel, e não só
     * o tempo gasto dentro do controller.
     */
    public function startRequest(string $requestId): void
    {
        $this->requestId = $requestId;
        $this->startedAt = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Tempo decorrido desde o início da requisição, em milissegundos.
     */
    public function elapsedMs(): float
    {
        if ($this->startedAt === null) {
            return 0.0;
        }

        return (microtime(true) - $this->startedAt) * 1000;
    }
}

<?php

namespace App\Interfaces\Http\Controllers\Health;

use Illuminate\Http\JsonResponse;

/**
 * Prova de vida: este processo consegue executar PHP e subir o Laravel?
 *
 * **Não** consulta banco nem nenhuma dependência externa, de propósito. A probe de liveness
 * do Kubernetes responde à pergunta "este processo travou? reinicia ele". Se ela checasse o
 * banco, uma indisponibilidade do RDS faria o Kubernetes reiniciar todos os pods da API em
 * laço — reiniciar não conserta um banco remoto, e os reinícios ainda atrasam a recuperação
 * quando o banco volta. Dependência externa é assunto da probe de readiness
 * (ReadinessController), que tira o pod do balanceamento sem matá-lo.
 */
final class LivenessController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}

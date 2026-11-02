<?php

namespace App\Domain\ServiceOrder\Exceptions;

use DomainException;
use Illuminate\Http\Response;

/**
 * Token de aprovação de ordem de serviço que não pode ser aceito.
 *
 * Quem recebe esta exceção é um **cliente no navegador**, clicando no link que chegou por
 * e-mail — não um sistema consumindo a API. Por isso o `render()` devolve uma página, e não
 * JSON: mostrar `{"error":"invalid_token"}` para quem clicou num link não ajuda ninguém.
 *
 * A distinção entre "inválido" e "expirado" é intencional e segura: o payload do token é
 * base64 de texto puro, então a data de expiração já é legível por quem tem o link. Saber que
 * expirou é a informação que o cliente precisa para pedir um link novo. O que a mensagem
 * **não** diferencia é token malformado de assinatura incorreta — isso seria dizer a quem
 * tenta forjar um token o quão perto chegou.
 */
class InvalidApprovalTokenException extends DomainException
{
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly string $customerMessage,
    ) {
        parent::__construct($message);
    }

    /**
     * Token que não tem o formato esperado: sem o separador, base64 inválido, ou com menos
     * campos do que o payload deveria ter.
     */
    public static function malformed(): self
    {
        return new self(
            'Token de aprovação malformado.',
            'malformed',
            'Este link de aprovação não é válido.',
        );
    }

    public static function signatureMismatch(): self
    {
        return new self(
            'Assinatura do token de aprovação não confere.',
            'signature_mismatch',
            'Este link de aprovação não é válido.',
        );
    }

    public static function expired(): self
    {
        return new self(
            'Token de aprovação expirado.',
            'expired',
            'Este link de aprovação expirou. Peça um novo à oficina.',
        );
    }

    /**
     * Identificador curto do motivo, para o log. Fica fora da página mostrada ao cliente.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    public function render(): Response
    {
        return response()->view(
            'approval.invalid',
            ['mensagem' => $this->customerMessage],
            Response::HTTP_BAD_REQUEST,
        );
    }
}

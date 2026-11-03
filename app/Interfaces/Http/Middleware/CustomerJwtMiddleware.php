<?php

namespace App\Interfaces\Http\Middleware;

use App\Infrastructure\Persistence\Eloquent\Customer\Models\Customer;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

/**
 * Middleware de autenticação JWT de cliente.
 *
 * Verifica o token emitido pela auth-lambda (autenticação por CPF) usando lcobucci/jwt
 * direto — já vendorizado como dependência do tymon/jwt-auth — em vez do facade
 * Tymon\JWTAuth usado pelo JwtMiddleware de funcionário.
 *
 * O motivo de não reaproveitar o Tymon aqui não é só o subject (sub = id de Customer,
 * não de User): o Tymon verifica contra um único segredo configurado globalmente
 * (config('jwt.secret')), e cliente e funcionário usam segredos DIFERENTES e
 * independentes (AUTH_LAMBDA_JWT_SECRET vs. JWT_SECRET) — ver ADR-0005. Assinar com
 * duas chaves diferentes faz um token do tipo errado falhar na própria verificação de
 * assinatura, antes de qualquer checagem de aplicação (iss, cliente ativo etc.).
 */
class CustomerJwtMiddleware
{
    private const ISSUER = 'FIAP-TC/auth-lambda';

    public function handle(Request $request, Closure $next): mixed
    {
        $authorization = (string) $request->header('Authorization', '');

        if (!str_starts_with($authorization, 'Bearer ')) {
            return $this->unauthorized('Token não fornecido.');
        }

        $jwt = substr($authorization, 7);

        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText((string) config('services.auth_lambda.jwt_secret')),
        );

        try {
            $token = $config->parser()->parse($jwt);
        } catch (\Throwable) {
            return $this->unauthorized('Token inválido.');
        }

        if (!$token instanceof Plain) {
            return $this->unauthorized('Token inválido.');
        }

        $assinaturaValida = $config->validator()->validate(
            $token,
            new SignedWith($config->signer(), $config->verificationKey()),
        );

        if (!$assinaturaValida) {
            return $this->unauthorized('Token inválido.');
        }

        if ($token->isExpired(new DateTimeImmutable())) {
            return $this->unauthorized('Token expirado.');
        }

        if ($token->claims()->get('iss') !== self::ISSUER) {
            return $this->unauthorized('Token inválido para este recurso.');
        }

        $customer = Customer::find((int) $token->claims()->get('sub'));

        if (!$customer || !$customer->status) {
            return $this->unauthorized('Cliente não encontrado ou inativo.');
        }

        // Evita o controller reabrir e reparsear o token: ele já foi verificado aqui.
        $request->attributes->set('customer', $customer);

        return $next($request);
    }

    private function unauthorized(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'errors'  => [$message],
            'data'    => [],
        ], 401);
    }
}

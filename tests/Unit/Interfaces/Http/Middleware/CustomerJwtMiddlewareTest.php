<?php

namespace Tests\Unit\Interfaces\Http\Middleware;

use App\Infrastructure\Persistence\Eloquent\Customer\Models\Customer;
use App\Interfaces\Http\Middleware\CustomerJwtMiddleware;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Tests\TestCase;

/**
 * Testes unitários do CustomerJwtMiddleware.
 *
 * Tokens são assinados de verdade com lcobucci/jwt nos testes (não há facade para
 * mockar: o middleware não usa o Tymon\JWTAuth, de propósito — ver ADR-0005). A tabela
 * customer usa o banco real (RefreshDatabase): a checagem de "cliente existe e está
 * ativo" é justamente a regra deste middleware, então faz sentido testá-la contra um
 * registro de verdade em vez de mockar o Eloquent.
 */
class CustomerJwtMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'FIAP-TC/auth-lambda';

    private CustomerJwtMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new CustomerJwtMiddleware();
    }

    private function handleWithToken(?string $authorizationHeader): mixed
    {
        $server = $authorizationHeader !== null
            ? ['HTTP_AUTHORIZATION' => $authorizationHeader]
            : [];

        $request = Request::create(
            '/api/service-orders/1/track/status',
            'GET',
            [],
            [],
            [],
            $server,
        );

        return $this->middleware->handle($request, fn ($req) => response()->json(['ok' => true]));
    }

    private function makeToken(
        string $secret,
        string $issuer,
        string|int $sub,
        ?DateTimeImmutable $expiresAt = null,
    ): string {
        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));
        $now = new DateTimeImmutable();

        $token = $config->builder()
            ->issuedBy($issuer)
            ->issuedAt($now)
            ->expiresAt($expiresAt ?? $now->modify('+1 hour'))
            ->relatedTo((string) $sub)
            ->getToken($config->signer(), $config->signingKey());

        return $token->toString();
    }

    private function validToken(string|int $sub): string
    {
        return $this->makeToken(
            secret: (string) config('services.auth_lambda.jwt_secret'),
            issuer: self::ISSUER,
            sub: $sub,
        );
    }

    public function test_sem_cabecalho_authorization_retorna_401(): void
    {
        $response = $this->handleWithToken(null);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals(['Token não fornecido.'], $response->getData(true)['errors']);
    }

    public function test_cabecalho_sem_prefixo_bearer_retorna_401(): void
    {
        $response = $this->handleWithToken('Token abc123');

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals(['Token não fornecido.'], $response->getData(true)['errors']);
    }

    public function test_token_malformado_retorna_401(): void
    {
        $response = $this->handleWithToken('Bearer isso.nao.e.um.jwt');

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals(['Token inválido.'], $response->getData(true)['errors']);
    }

    public function test_token_assinado_com_segredo_errado_retorna_401(): void
    {
        $token = $this->makeToken(secret: 'segredo-errado-nao-e-o-configurado', issuer: self::ISSUER, sub: '1');

        $response = $this->handleWithToken("Bearer {$token}");

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals(['Token inválido.'], $response->getData(true)['errors']);
    }

    public function test_token_expirado_retorna_401(): void
    {
        $token = $this->makeToken(
            secret: (string) config('services.auth_lambda.jwt_secret'),
            issuer: self::ISSUER,
            sub: '1',
            expiresAt: (new DateTimeImmutable())->modify('-1 hour'),
        );

        $response = $this->handleWithToken("Bearer {$token}");

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals(['Token expirado.'], $response->getData(true)['errors']);
    }

    public function test_token_com_emissor_diferente_retorna_401(): void
    {
        $token = $this->makeToken(
            secret: (string) config('services.auth_lambda.jwt_secret'),
            issuer: 'outro-emissor',
            sub: '1',
        );

        $response = $this->handleWithToken("Bearer {$token}");

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals(['Token inválido para este recurso.'], $response->getData(true)['errors']);
    }

    public function test_cliente_inexistente_retorna_401(): void
    {
        $response = $this->handleWithToken('Bearer ' . $this->validToken(999999));

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals(['Cliente não encontrado ou inativo.'], $response->getData(true)['errors']);
    }

    public function test_cliente_inativo_retorna_401(): void
    {
        $customer = Customer::create([
            'name'                  => 'Cliente Inativo',
            'identification'        => 'CPF',
            'identification_number' => 11111111111,
            'email'                 => 'inativo@mecanica.local',
            'status'                => false,
            'create_date'           => now(),
        ]);

        $response = $this->handleWithToken('Bearer ' . $this->validToken($customer->id));

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals(['Cliente não encontrado ou inativo.'], $response->getData(true)['errors']);
    }

    public function test_cliente_ativo_com_token_valido_segue_para_o_proximo_middleware(): void
    {
        $customer = Customer::create([
            'name'                  => 'Cliente Teste',
            'identification'        => 'CPF',
            'identification_number' => 39985941080,
            'email'                 => 'cliente.teste@mecanica.local',
            'status'                => true,
            'create_date'           => now(),
        ]);

        $response = $this->handleWithToken('Bearer ' . $this->validToken($customer->id));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(['ok' => true], $response->getData(true));
    }
}

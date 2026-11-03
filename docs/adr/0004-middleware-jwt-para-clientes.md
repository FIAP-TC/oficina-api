# ADR-0004: Middleware JWT separado para autenticação de cliente

**Status:** Aceita
**Data:** 2026-11-02

## Contexto

Requisito do desafio: proteger rotas sensíveis com autenticação via CPF, usando uma função
serverless (`auth-lambda`, noutro repositório) que valida o CPF, consulta se o cliente existe
e qual seu status, e devolve um JWT para consumir as APIs protegidas.

O app já tinha autenticação JWT — `POST /auth/login` (usuário/senha), guard `api` do
`tymon/jwt-auth`, provider `users`, middleware `jwt` (`JwtMiddleware`). Mas é para
**funcionário** (Admin/Gerente/Mecânico), não para cliente: o token carrega `sub` = id de
`User`, e o guard resolve esse `sub` buscando um `User::find($sub)`.

O token da `auth-lambda` tem outro formato: `iss: "FIAP-TC/auth-lambda"`, `sub` = id de
`Customer`. Passar esse token pelo guard `api` existente faria o Tymon procurar um `User`
com esse id — na melhor hipótese não encontra nada, na pior encontra um usuário diferente
que coincide em id, autenticando a pessoa errada. As duas assinaturas de token também usam
segredos diferentes (`JWT_SECRET` do `fiap-tc-1` vs. `jwt_secret` da `auth-lambda`), que
precisam ser o mesmo valor para a verificação funcionar — isso é configurado fora do código
(variável de ambiente/secret do Kubernetes), não garantido pelo tipo do sistema.

## Decisão

**1. Middleware novo, `CustomerJwtMiddleware`, com alias `customer.jwt`.** Não reaproveita o
guard/provider `api`. Ele:

- Decodifica o token com `JWTAuth::parseToken()->getPayload()` (mesma biblioteca do
  `JwtMiddleware`, só não chama `authenticate()`, que resolveria contra o provider errado).
- Confere `iss === "FIAP-TC/auth-lambda"` — rejeita token de outro emissor (ex: um token de
  funcionário, que não tem essa claim) antes mesmo de tentar achar o cliente.
- Resolve o `sub` como `Customer::find()`, não `User::find()`.
- Rejeita cliente inexistente ou com `status = false`, com a mesma mensagem para os dois
  casos — não revela qual dos dois aconteceu.

**2. Primeira rota protegida: `GET /service-orders/{orderId}/track/status`.** Era pública.
O `GetServiceOrderTrackingUseCase` passou a exigir o `customerId` do token e confere
`VehiculeEntity::belongsToCustomer($customerId)` (método que já existia, pronto para isso)
antes de devolver a ordem. Se não pertence ao cliente, lança
`ServiceOrderNotFoundException` — **404, não 403**: não confirma a quem não tem acesso que
aquele ID de ordem existe.

**3. Sem guard/provider novo no `config/auth.php`.** Daria pra criar um guard `customer`
(JWT + provider apontando pro model `Customer`), mas isso exige `Customer implements
Authenticatable` e todo o aparato do Tymon por trás, para uma única rota hoje. Um
middleware direto, no mesmo estilo de `AdminMiddleware`/`ManagerMiddleware` (que já leem o
payload na mão em vez de passar pelo guard), é mais simples e já segue um padrão existente
no projeto.

## Alternativas consideradas

**Reaproveitar o guard `api`/`JwtMiddleware` existente.** Rejeitada pelo motivo do
Contexto: resolve o `sub` como `User`, não `Customer` — autenticação incorreta por design,
não só por falta de dado.

**Guard + provider Laravel dedicados para `Customer`.** Mais "nativo" do framework, mas
exige implementar `Authenticatable` no model `Customer` e configurar um provider só para
isso. Desproporcional para uma rota; fica como opção se mais rotas de cliente aparecerem.

**Aceitar o token por query string (reaproveitando o padrão do link de aprovação por
e-mail).** Rejeitada: o link de aprovação (`/service-orders/approve?token=...`) usa um
mecanismo propositalmente diferente — um token de uso único assinado por
`OrderApprovalTokenSigner`, não o JWT do cliente — e a ADR-0002 do repositório `infra-k8s`
já registra token em query string como algo a evitar (vaza para logs de acesso).

## Consequências

**Fica mais fácil:**

- Qualquer rota futura de cliente usa o mesmo middleware (`customer.jwt`), sem repetir a
  lógica de validar emissor/status.
- Autenticação de cliente e de funcionário ficam claramente separadas — não tem como um
  token do tipo errado autenticar algo, mesmo por acidente de IDs coincidirem.

**Fica mais difícil / riscos:**

- Uma chave de verificação (`AUTH_LAMBDA_JWT_SECRET` aqui, que precisa ser igual ao
  `jwt_secret` da `auth-lambda`) e nada no código garante isso — é um acordo operacional
  entre dois repositórios, via secret do Kubernetes de um lado e `terraform.tfvars`/
  variável do pipeline do outro. Se um dos dois girar o segredo sem avisar o outro lado,
  todo cliente autenticado passa a tomar "Token inválido" sem nenhuma mudança de código
  aqui. (Na primeira versão desta ADR, essa chave era o próprio `JWT_SECRET`, compartilhado
  com o login de funcionário — corrigido na [ADR-0005](0005-segredo-proprio-para-jwt-de-cliente.md),
  que tira o acoplamento entre os dois domínios de autenticação.)
- Token de cliente não tem blacklist/logout, diferente do token de funcionário
  (`AuthService::logout()` usa a blacklist do Tymon). Um token de cliente vazado continua
  válido até expirar — aceitável para o prazo do desafio, mas é uma lacuna real.
- Cliente que é desativado (`status = false`) depois de já ter um token emitido só perde o
  acesso quando o token expirar nas chamadas seguintes (a checagem roda a cada request, não
  é cache — então na prática o próximo request já nega, mas o token em si continua "válido"
  do ponto de vista de assinatura).

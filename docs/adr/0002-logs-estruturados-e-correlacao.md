# ADR-0002: Logs estruturados em JSON e correlação de requisições

**Status:** Aceita
**Data:** 2026-11-01

## Contexto

O Tech Challenge exige logs estruturados em JSON, com correlação entre as requisições, e o
monitoramento da latência das APIs. É a fase 2 do plano de observabilidade registrado no
[ADR-0002 do repositório `infra-k8s`](https://github.com/FIAP-TC/infra-k8s/blob/main/docs/adr/0002-observabilidade-com-grafana-cloud.md),
que decidiu usar o Grafana Cloud e já instalou o agente de coleta no cluster (fase 1).

De onde partimos:

- A aplicação gravava log em `storage/logs/laravel.log`, em texto de uma linha por registro.
  Dentro de um container isso é duplamente ruim: o agente de observabilidade só lê a saída
  padrão do container, então o arquivo é invisível para ele, e o arquivo cresce no disco do
  nó sem ninguém rotacionar.
- O `LOG_LEVEL` configurado no cluster era `dev`, que não é um nível do Monolog.
- O access log do Nginx estava no formato padrão (texto), **sem tempo de resposta** — ou
  seja, não havia como medir latência de API nenhuma.
- Nada ligava uma linha de log à requisição que a originou.

Restrições que pesaram na decisão:

- **Os logs saem da AWS** e vão para um serviço externo. CPF, e-mail e token não podem ir
  junto.
- **Plano gratuito** do Grafana Cloud: o volume de ingestão importa.
- **Um nó t3.medium**, que aceita no máximo 17 pods e já roda a API e o agente: não há
  espaço para um pod extra de coleta ou processamento de log.

## Decisão

**1. Uma linha de JSON plana por registro, na saída padrão do container.** Um canal de log novo
(`json`, em `config/logging.php`, montado por `JsonLoggerFactory`) escreve em `php://stderr`
com um formatador derivado do `JsonFormatter` do Monolog. A linha é plana e usa os mesmos
nomes de campo do access log do Nginx (`timestamp`, `level`, `channel`, `request_id`,
`method`, `path`, `status`), para que uma única consulta no Grafana sirva para as duas
fontes. O PHP-FPM da imagem oficial captura a saída do processo
filho e repassa sem prefixo (`catch_workers_output = yes`, `decorate_workers_output = no`),
então o que sai no stdout do pod é JSON válido, linha a linha. Esse canal é o padrão em
todos os ambientes, inclusive no desenvolvimento local, para que o log que se lê na máquina
seja o mesmo que se lê em produção.

**2. O identificador de correlação é gerado e validado no Nginx.** Se o cliente mandou um
`X-Request-Id` de formato aceitável, ele é reaproveitado; senão o Nginx gera um. O valor vai
para o access log do Nginx, é repassado ao PHP como `REQUEST_ID` (FastCGI), entra em **toda**
linha de log da aplicação por um processador do Monolog, e volta ao cliente no cabeçalho
`X-Request-Id` da resposta. Validar no Nginx — e de novo na aplicação — importa porque o
valor vem de fora e termina dentro de um log: texto arbitrário de cliente em log é injeção
de conteúdo.

**3. Duas linhas por requisição, com papéis diferentes.** O Nginx registra a latência vista
de fora (`duration_seconds`, incluindo rede e fila do PHP-FPM) e registra até o que não chega
na aplicação. A aplicação registra o que só ela sabe: o **padrão da rota**
(`api/customers/{id}`, e não `/api/customers/42`, que viraria uma série por id nos
dashboards) e o usuário autenticado. O registro da aplicação acontece em `terminate()`, após
a resposta já ter sido enviada: observabilidade não deve somar latência à requisição.

**4. Dados pessoais são mascarados na origem, em duas camadas.** Um processador do Monolog
mascara pelo *nome* do campo (`password`, `identification_number`, `email`) enquanto o
registro ainda é um array PHP; o formatador mascara pelo *formato* do valor (CPF, CNPJ, JWT,
e-mail) na linha de JSON já pronta, que é o que alcança o conteúdo de exceções. CPF e CNPJ
ficam com os dois últimos dígitos à vista — o suficiente para conferir um cadastro com o
cliente ao telefone.

**5. Nada de corpo de requisição nem de query string no log.** A query string carrega o token
de aprovação da ordem de serviço (`GET /api/service-orders/approve?token=...`), então o Nginx
registra só o caminho, antes do `?`.

**6. Ruído fica fora do log.** O `/nginx_status`, consultado pelas probes do Kubernetes a
cada 10 segundos em cada pod, seria o endpoint mais "acessado" da API; e o access log do
próprio PHP-FPM (que a imagem oficial liga por padrão, em texto, no mesmo stderr) repetiria
em formato pior algo que já existe em dois lugares melhores. Ambos desligados.

## Alternativas consideradas

**Manter o log em texto e extrair os campos no Grafana.** O Loki consegue aplicar expressão
regular na consulta. Mas o padrão passa a viver na consulta, e não no código: qualquer
mudança de mensagem quebra dashboard e alerta silenciosamente, sem erro de pipeline. Com
JSON, o contrato está no repositório.

**Gravar em arquivo e coletar com um container auxiliar (sidecar) em cada pod.** Resolveria a
invisibilidade do arquivo, mas custa um pod a mais por pod da aplicação, num nó que aceita 17
no total, para resolver um problema que a saída padrão já resolve de graça.

**Usar um formatador pronto do Monolog (`LogstashFormatter`) ou um pacote de terceiros.** O
`LogstashFormatter` nomeia os campos na convenção do Elasticsearch (`@timestamp`,
`@version`), que não é a do Loki, e nenhum dos dois oferece gancho para mascarar dado pessoal
— que é requisito aqui, não enfeite. O `JsonFormatter` padrão, com dois processadores
próprios de cerca de 40 linhas cada, resolve com menos dependência.

**Mascarar os dados no agente de coleta (Grafana Alloy) ou no próprio Grafana Cloud.** Daria
menos código na aplicação, mas o dado pessoal já teria saído do processo e atravessado o
cluster. Mascarar na origem é a única opção em que o dado nunca é escrito.

**Logs via OpenTelemetry.** É a fase 6 do plano, e opcional: depende de validar o suporte ao
Laravel 8. Não resolveria nada desta fase mais cedo.

## Consequências

**Fica mais fácil:**

- Partir de um erro e ver tudo o que aconteceu naquela requisição, filtrando por
  `request_id` — inclusive a linha do Nginx, que carrega o mesmo identificador.
- Medir latência por rota e por status, que é o requisito de "latência das APIs".
- Um cliente que recebeu erro pode informar o `X-Request-Id` da resposta, e o time acha a
  requisição no Grafana sem precisar adivinhar horário.
- O log local e o log de produção têm o mesmo formato.

**Fica mais difícil / riscos:**

- O formato aceito do identificador existe em dois lugares (a configuração do Nginx e o
  `RequestIdMiddleware`) e precisa ser mudado nos dois juntos.
- A configuração do Nginx existe em duas cópias (`docker/nginx/` para o Docker Compose e
  `k8s/base/nginx/` para o ConfigMap do cluster). O pipeline compara as duas e falha se
  divergirem, mas é duplicação.
- O mascaramento por formato tem falso positivo: um telefone celular com DDD também tem 11
  dígitos e será mascarado como CPF. Aceitável — telefone também é dado pessoal.
- O stack trace registrado não inclui os argumentos das chamadas (o Monolog guarda apenas
  arquivo e linha). Perde-se um pouco na investigação, e ganha-se que o trace não vaza dado
  de cliente.
- São duas linhas de log por requisição. É o custo de ter latência de fora e contexto de
  dentro; as probes e o access log do FPM saíram do caminho em troca.

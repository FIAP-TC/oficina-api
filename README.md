# Tech Challenge - Fase 1: Oficina Mecânica (MVP)

Este projeto consiste na primeira versão (MVP) do back-end para um Sistema Integrado de Atendimento e Execução de Serviços de uma oficina mecânica.

## Objetivos do Sistema

O sistema garante uma gestão interna eficiente através dos seguintes fluxos principais:

* **Criação e Acompanhamento de OS:** Gestão completa do ciclo de vida da Ordem de Serviço, desde a recepção até a entrega, com orçamentos automatizados.
* **Gestão Administrativa:** CRUDs para controle de Clientes, Veículos, Serviços e Peças.
* **Segurança e Qualidade:** Autenticação via JWT para rotas administrativas, validação de dados sensíveis (CPF/CNPJ/Placa) e cobertura de testes automatizados.

## Arquitetura e Tecnologias

Como se trata de um MVP, o projeto foi desenvolvido como um **Monolito utilizando arquitetura em camadas**:
* **Controller:** Responsável por receber as requisições e retornar as respostas da API.
* **Service:** Onde reside toda a regra de negócio e orquestração dos domínios.
* **Repository:** Camada de abstração para as chamadas e persistência no banco de dados.
* **Model:** Representação das tabelas e entidades de dados.

**Tecnologias Utilizadas:**

* **PHP / Laravel:** Framework principal para o desenvolvimento das APIs RESTful.
* **MySQL 8.4:** Banco de dados relacional escolhido. Por se tratar de um sistema estruturado focado em integridade transacional (clientes, veículos, ordem de serviço, controle rigoroso de estoque e finanças), um banco relacional como o MySQL garante a consistência necessária para esses domínios.
* **Docker & Docker Compose:** Containerização e orquestração do ambiente completo (API, Nginx, Database e Mailpit para testes de envio da Ordem de serviço por e-mail).
* **APIdocs:** Documentação das rotas da API RESTful.

## Documentação DDD

Para o mapeamento dos fluxos e descoberta do domínio, aplicamos as seguintes dinâmicas:

* **Event Storming:** Modelagem dos fluxos de Criação/Acompanhamento da OS e Gestão de Peças/Insumos.
    * [https://miro.com/app/board/uXjVHQxkki4=/?share_link_id=478657558979](#)
* **Domain Storytelling:** Detalhamento do domínio.
  <img width="1567" height="975" alt="Domain StoryTelling_2026-06-30" src="https://github.com/user-attachments/assets/4b2b4732-4288-48fa-9f1d-d6ed0fef2bc4" />


## Como Executar o Projeto Localmente

Siga o passo a passo abaixo para rodar a aplicação através do Docker:

1. **Clone o repositório:**
   ```bash
   git clone https://github.com/FIAP-TC/fiap-tc-1.git
    ```

2. **Configuração de Variáveis de Ambiente:**
    Copie o arquivo de exemplo e configure suas variáveis (como credenciais de banco e JWT):
    ```bash
    cp .env.example .env
    ```

3. Suba os containers com Docker Compose:
    O comando abaixo fará o build da aplicação e inicializará os serviços (API, Nginx, MySQL e Mailpit):
    ```bash
    docker-compose up -d --build
    ```

4. Rode as migrations:
    Acesse o container da API para instalar os pacotes do Laravel e estruturar o banco de dados:
    ```bash
    docker-compose exec api composer install
    docker-compose exec api php artisan key:generate
    docker-compose exec api php artisan jwt:secret
    docker-compose exec api php artisan migrate --seed
    ```
5. Gerar e Acessar a Documentação das APIs (APIDocs):
A documentação foi construída utilizando o apidoc. Para gerar e visualizar localmente, certifique-se de ter o Node.js instalado em sua máquina e execute na raiz do projeto:
    ```Bash
    npm install
    npm run docs
    ```

    Após a geração, o APIDocs criará os arquivos estáticos na pasta pública. Para abrir a documentação, copie o caminho completo do arquivo index.html gerado e cole diretamente na barra de endereço do seu navegador.

    Exemplo de caminho local:
    ```Plaintext
    file:///seu-caminho-local/fiap-tc-1/public/docs/index.html
    ```

5. Acesse a Aplicação:
    - API: http://localhost:9000 (via Nginx)
    - Mailpit (Teste de E-mails): http://localhost:8025

## Testes
O projeto conta com testes unitários e de integração para garantir a estabilidade dos fluxos principais, com cobertura mínima de 80% nos domínios críticos. Para rodar os testes, execute:  
```bash
docker-compose exec api php artisan test
```

## Análise Estática e Qualidade de Código

Para garantir a manutenibilidade, a correta tipagem do ecossistema Laravel e prevenir bugs em ambiente de desenvolvimento, o projeto utiliza o **PHPStan** como ferramenta de análise estática (SAST).

A análise está configurada para varrer os domínios críticos da aplicação. Para contornar possíveis gargalos de processamento em projetos monolíticos, execute o comando abaixo forçando a liberação do limite de memória do PHP:

```bash
docker-compose exec api ./vendor/bin/phpstan analyse --memory-limit=-1
```

## Observabilidade

Os logs da aplicação e do Nginx saem em **JSON, uma linha por registro**, na saída padrão do
container, com um identificador de correlação por requisição (`X-Request-Id`) e com CPF,
e-mail e token mascarados antes de serem escritos. O detalhamento — os campos de cada linha,
como ler no local, no cluster e no Grafana, e a convenção para registrar eventos novos — está
em [`docs/OBSERVABILIDADE.md`](docs/OBSERVABILIDADE.md).

```bash
docker compose logs -f --no-log-prefix api | jq -c '{level,event,route,status,duration_ms,request_id}'
```

## Infraestrutura, Kubernetes e Terraform

A documentação de infraestrutura (arquitetura, Kubernetes local/AWS, provisionamento via Terraform e CI/CD) está em [`docs/INFRAESTRUTURA.md`](docs/INFRAESTRUTURA.md).

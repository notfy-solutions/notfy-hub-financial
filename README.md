# notfy-hub-financial

Pacote Composer (Laravel 13) com a integração de gateways de pagamento (cobrança/checkout), compartilhado entre o `notfyAdmin` e (futuramente) o `notfy-app`. A emissão de nota fiscal vive em `notfy/fiscal-hub`, um pacote separado — este aqui cuida só de cobrança.

## Por que existe

Hoje só o `notfyAdmin` usa este pacote, para cobrar a assinatura SaaS dos clientes do notfy (Asaas). No futuro, o `notfy-app` vai instalar o mesmo pacote diretamente para permitir que os clientes do notfy (escritórios, etc.) cobrem os próprios clientes deles, com a própria conta de gateway — sem depender do admin como intermediário.

## Instalação (consumo local via path repository)

No `composer.json` da aplicação consumidora:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../notfy-hub-financial",
            "options": { "symlink": true }
        }
    ],
    "require": {
        "notfy/hub-financial": "*"
    }
}
```

```bash
composer require notfy/hub-financial:@dev
php artisan vendor:publish --tag=hub-financial-config
```

Se um dia for necessário versionar/distribuir separadamente (outro time, CI isolado), troque o `repositories` para um repositório VCS git privado — o código do pacote não muda.

## Estrutura

- `Contracts\BillingGateway` — cobrança (customer, charge, checkout, billing portal). Único contrato para qualquer gateway.
- `Gateways\Asaas\*` — implementação Asaas do contrato (Brasil: pix/boleto/cartão), via HTTP puro (`AsaasHttpClient`) — a Asaas não mantém um SDK PHP oficial.
- `Gateways\Stripe\*` — implementação Stripe do contrato (cartão internacional), via `stripe/stripe-php` (SDK oficial). Diferente do Asaas, o Stripe mantém e testa esse cliente — não faz sentido reimplementar serialização/erros/retries na mão.
- `Support\GatewayResolver` — resolve o gateway certo por preferência do cliente ou por país/tipo de pagamento. Roteamento padrão: Brasil → Asaas, qualquer outro país → Stripe (`config/hub-financial.php` → `routing`).
- `Webhooks\AsaasWebhookParser` — valida (token estático) e normaliza webhooks recebidos do Asaas.
- `Webhooks\StripeWebhookParser` — valida (via `Stripe\Webhook::constructEvent()`, HMAC-SHA256 do header `Stripe-Signature` com janela de tolerância contra replay) e normaliza webhooks recebidos do Stripe.

O pacote é agnóstico de persistência: recebe DTOs simples, fala com o gateway e devolve DTOs. Toda a gravação em banco (webhook events, payments, invoices) é responsabilidade da aplicação consumidora.

Cada gateway garante idempotência (exigida pelo contrato `BillingGateway`) à sua maneira: o Asaas deduplica pelo campo `externalReference` no próprio payload; o Stripe usa o header `Idempotency-Key` (via `RequestOptions` do SDK), derivado do `externalReference` do DTO.

## Uso com chave própria vs. chave de terceiro (BYO key)

Os gateways recebem a credencial via construtor (`AsaasHttpClient($apiKey, $baseUrl)` / `StripeClient($apiKey)`), não leem `config()` internamente. Isso permite dois modos de uso:

- **Conta própria**: resolvida automaticamente pelo `HubFinancialServiceProvider` via `config/hub-financial.php` (bind no container).
- **Conta de terceiro (BYO key)**: a aplicação consumidora instancia manualmente, ex.: `new AsaasBillingGateway(new AsaasHttpClient($team->asaas_api_key))` ou `new StripeBillingGateway(new StripeClient($team->stripe_secret_key))`, sem passar pelo container.

## Testando o gateway Stripe (SDK oficial)

`StripeBillingGateway` usa `Stripe\StripeClient` de verdade — nada de wrapper HTTP próprio. Para testar sem bater na API real, os testes registram um `FakeStripeHttpClient` (`tests/Support/FakeStripeHttpClient.php`, implementa `Stripe\HttpClient\ClientInterface`) via `Stripe\ApiRequestor::setHttpClient()`, o mesmo mecanismo que o próprio SDK do Stripe usa na sua suíte de testes. Isso exercita a serialização real do SDK (objetos tipados, mapeamento de erros) fakeando só a camada de rede.

## Testes

```bash
composer install
vendor/bin/pest
```

## Qualidade

```bash
composer quality   # stan + pint:check + insights + test
```

PHPStan nível 9 (strict rules + deprecation rules), Pint (PSR-12 + regras do projeto), PHP Insights (mínimo 99 em qualidade/complexidade/arquitetura/estilo) e Pest com cobertura mínima de 99%.

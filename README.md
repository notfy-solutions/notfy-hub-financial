# notfy-hub-financial

Pacote Composer (Laravel 13) com a integração de gateways de pagamento (cobrança/checkout), compartilhado entre o `notfyAdmin` e (futuramente) o `notfy-app`. A emissão de nota fiscal vive em `notfy/hub-tax`, um pacote separado — este aqui cuida só de cobrança.

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

- `Contracts\BillingGateway` — cobrança (customer, charge, checkout, billing portal) **e** descrição do gateway (`DescribesGateway`). É composição de contratos menores (`ChargeGateway`, `CustomerGateway`, `CheckoutGateway`); drivers que cobrem só uma parte implementam só o que suportam.
- `Contracts\DescribesGateway` — todo gateway declara `capabilities()`, `supportedCountries()`, `supportedCurrencies()` e `supportedPaymentMethods()` (`'*'` atende qualquer país/moeda). É o que permite escolher um provedor que atenda o pedido.
- `Contracts\StatementGateway` — extrato e saldo de conta bancária (`fetchStatement`, `fetchBalance`), base da conciliação, independente de OFX, CNAB, Open Banking ou API de banco.
- `Domain\Enums\{PaymentMethod, ChargeStatus, Capability, ...}` — vocabulário universal: meios locais (Boleto, Pix) e internacionais (cartão, ACH, débito SEPA, transferência, carteira).
- `DTO\ChargeInput` / `ChargeResult` / `CustomerInput` — dados tipados e agnósticos de país (moeda ISO 4217 na cobrança; país, tipo de documento e endereço no cliente).
- `Gateways\Asaas\*` — Brasil: Pix, boleto e cartão, via HTTP puro (`AsaasHttpClient`), só em BRL.
- `Gateways\Stripe\*` — mundo: cartão, ACH, SEPA e outros meios, em qualquer moeda, via `stripe/stripe-php`.
- `Gateways\Fake\FakeGateway` — gateway em memória que implementa todos os contratos (inclui extrato); serve para testes e como referência mínima de um driver novo.
- `Support\GatewayResolver` — roteia por país/tipo de pagamento (`resolve`) e valida país, moeda, meio de pagamento e capacidade (`resolveFor`); `supportsCountry()` diz se há cobrança para o país. Padrão: Brasil → Asaas, demais países → Stripe (`config/hub-financial.php` → `routing`).
- `Support\GatewayManager` + `GatewayAccount` + `Credentials\*` — constroem um driver a partir de uma conta (credencial de qualquer origem). Provedores novos entram com `extend()`.
- `Webhooks\*Parser` — validam e normalizam webhooks (Asaas por token estático; Stripe por assinatura HMAC com tolerância contra replay) em um `PaymentEvent` agnóstico.

O pacote é agnóstico de persistência: recebe DTOs simples, fala com o gateway e devolve DTOs. Toda a gravação em banco (webhook events, payments, invoices) é responsabilidade da aplicação consumidora.

Cada gateway garante idempotência (exigida pelo contrato) à sua maneira: o Asaas deduplica pelo `externalReference` no payload; o Stripe usa o header `Idempotency-Key`, derivado do `externalReference` do DTO.

## Adicionando um país ou provedor

1. **Implemente o driver** em `Gateways\{Provedor}\*` com `BillingGateway` (e `SubscriptionGateway`/`StatementGateway`/`WebhookHandler` se fizer sentido). Declare em `DescribesGateway` os países, moedas, meios de pagamento e capacidades **reais** do provedor; o resolvedor recusa o que não estiver ali (`UnsupportedGatewayRequestException`). Use o `FakeGateway` como esqueleto.
2. **Mapeie o vocabulário local** para os enums universais dentro do driver (status do provedor → `ChargeStatus`; `PaymentMethod` → o tipo do provedor). Nada específico do provedor sobe para os DTOs: o que for único vai em `extra`.
3. **Registre** o provedor: `app(GatewayManager::class)->extend('meu-provedor', fn (GatewayAccount $account) => new MeuGateway(...))` para credencial por conta, e uma entrada em `gateways`/`routing` de `config/hub-financial.php` para o gateway padrão da aplicação.
4. **Webhook**: implemente `WebhookHandler` (verificação de autenticidade + normalização em `PaymentEvent`).
5. **Teste**: cubra cobrança, mapeamento de status e as recusas por país/moeda/meio (veja `tests/Gateways/*`).

A aplicação monta o contexto a partir do cadastro do cliente (`DefaultGatewayContext::make(country: ..., currency: ...)`) e pergunta `supportsCountry()` antes de oferecer cobrança.

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

# notfy-hub-financial

Pacote Composer (Laravel 13) com a integração de gateways de pagamento e emissão de notas fiscais, compartilhado entre o `notfyAdmin` e (futuramente) o `notfy-app`.

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

- `Contracts\BillingGateway` — cobrança (customer, charge, checkout, billing portal).
- `Contracts\NotaFiscalGateway` — emissão de nota fiscal (NFS-e).
- `Gateways\Asaas\*` — implementação Asaas de ambos os contratos.
- `Support\GatewayResolver` — resolve o gateway certo por preferência do cliente ou por país/tipo de pagamento.
- `Webhooks\AsaasWebhookParser` — valida e normaliza webhooks recebidos do Asaas.

O pacote é agnóstico de persistência: recebe DTOs simples, fala com o gateway via HTTP e devolve DTOs. Toda a gravação em banco (webhook events, payments, invoices) é responsabilidade da aplicação consumidora.

## Uso com chave própria vs. chave de terceiro (BYO key)

Os gateways recebem a credencial via construtor (`AsaasHttpClient($apiKey, $baseUrl)`), não leem `config()` internamente. Isso permite dois modos de uso:

- **Conta própria**: resolvida automaticamente pelo `HubFinancialServiceProvider` via `config/hub-financial.php` (bind no container).
- **Conta de terceiro (BYO key)**: a aplicação consumidora instancia manualmente, ex.: `new AsaasBillingGateway(new AsaasHttpClient($team->asaas_api_key))`, sem passar pelo container.

## Testes

```bash
composer install
vendor/bin/pest
```

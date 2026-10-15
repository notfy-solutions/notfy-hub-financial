---
name: project-architecture-plan
description: Plano de refatoração em 5 fases para tornar o hub financeiro agnóstico de provedor
metadata:
  type: project
---

Hub financeiro agnóstico de provedor — plano de 5 fases acordado com o usuário.

**Why:** Preparar o pacote para suportar Kobana, Tecnospeed e banco direto sem reescrita, mantendo o notfyAdmin em produção funcionando.

**How to apply:** Sempre evoluir de forma aditiva. Nunca quebrar os contratos existentes. Cada fase deve rodar os testes (`pest --coverage --min=99`), PHPStan nível 9 e Pint antes de commitar.

## Fase 1 (em andamento — 2026-10-08)
Núcleo agnóstico sem mudar comportamento:
- Enums: PaymentMethod, ChargeStatus, PaymentEventType, Capability
- Contratos segregados: ChargeGateway, CustomerGateway, SubscriptionGateway, CheckoutGateway, WebhookHandler
- BillingGateway vira composição (extends ChargeGateway, CustomerGateway, CheckoutGateway) — backward compat
- Credentials tipadas: GatewayCredentials (interface), ApiKey, OAuth2 (stub), MutualTls (stub)
- GatewayAccount VO — envelope agnóstico; credenciais podem vir de env, DB ou passadas diretamente
- GatewayManager com extend() e forAccount()
- GatewayException base; AsaasApiException e StripeApiException herdam dela
- PaymentEvent VO + WebhookHandler contract; parsers existentes implementam o contrato
- FakeGateway para testes

## Credenciais — decisão importante
As credenciais NÃO vêm só do env. Podem vir de:
1. env/config (comportamento padrão, backward compat)
2. banco de dados (por tenant) — app carrega e constrói GatewayAccount
3. passadas diretamente (BYO key)
O pacote só recebe GatewayAccount. Quem decide a fonte é a aplicação.
`GatewayManager::forAccount(GatewayAccount $account)` — constrói driver a partir da conta.

## Fase 2: Asaas completo (pós Fase 1)
Endpoints a confirmar via MCP da Asaas: payments completo, boleto (identificationField, barcode, nossoNumero), Pix (pixQrCode), subscriptions, accounts (subconta), financialTransactions, finance/balance, transfers, paymentLinks, webhooks por API.

## Fase 3: Conciliação (independente de provedor)
StatementEntry, ReconciliationEngine (função pura), ReceivableRepository (contrato), OfxStatementProvider.

## Fase 4: Pontos de extensão prontos
Esqueleto de driver, guia "como criar um driver", CNAB 240/400, OAuth2TokenProvider, suporte mTLS.

## Fase 5: Integração no notfy-app
Tabelas, Filament, Jobs (atenção ao TeamScope 1=0 em jobs/webhooks), migrations só aditivas.

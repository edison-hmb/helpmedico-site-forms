# helpmedico-site-forms

Recebem os dados dos formulários da Help Médico, enviam ao ERP (Supabase) e avisam por e-mail (Gmail/OAuth2).

| Endpoint | Usado por | Campos | Função no ERP |
|---|---|---|---|
| `queroConhecer.php` | Seção "Pronto para simplificar sua vida?" do site | `name`, `crm`, `email`, `phone`, `referral` (todos obrigatórios) | `want-to-know` |
| `faleConosco.php` | Contato da Área do Assinante | `name`, `email`, `phone`, `type`, `message` (obrigatórios) e `crm` (opcional) | `contact-us` |

Resposta sempre em JSON: `{"resposta": "...", "sucesso": true|false}`.

## Configuração

Cada endpoint lê o seu arquivo em `conf/` (fora do git): `conf/queroConhecerConfig.json` e `conf/faleConoscoConfig.json`.
Use `config.exemplo.json` como modelo.

Obrigatórias para o e-mail: `gmailUser`, `clientId`, `clientSecret`, `refreshToken`, `to` (texto ou lista).
Obrigatória para o ERP: `token`.

Opcionais:

- `erpCrmField` — nome do campo do CRM no ERP (ex.: `"crm"`). Vazio = o CRM vai só no e-mail e no backup.
- `allowedOrigins` — domínios que podem chamar os formulários pelo navegador (CORS). Aceita `https://*.dominio`.
- `erpUrl` — base das funções do ERP (padrão: Supabase de produção).
- `dataDir` — pasta dos backups; de preferência fora da pasta pública. Padrão: `dados/`.
- `rateLimitMax` / `rateLimitWindow` — limite de envios por IP (padrão: 5 a cada 600 s; `0` desliga).

## Comportamento

1. Valida e limpa os campos (e-mail, telefone com DDD, CRM com números, tamanhos máximos).
2. Campo-armadilha `website`: se vier preenchido (robô), responde sucesso e descarta.
3. Limita envios por IP.
4. Grava backup em `dados/` antes de enviar; depois anota se ERP e e-mail deram certo.
5. Envia ao ERP (só 2xx conta como sucesso) e o e-mail (com os dados escapados e o aviso "Registrado no ERP").
6. Responde sucesso se o ERP **ou** o e-mail funcionou; se os dois falharem, responde erro 500 (o backup fica guardado).

## Publicação

Enviar ao servidor: `faleConosco.php`, `queroConhecer.php`, `inc/`, `vendor/`, `.htaccess` e os arquivos de `conf/`.
O `.htaccess` bloqueia o acesso pela web a `conf/`, `dados/`, `inc/`, `vendor/`, `.git` e arquivos `.json`/`.lock`/`.md`
(servidor Apache). Em Nginx, criar regras equivalentes.
Apagar `queroConhecerOld.php` do servidor.

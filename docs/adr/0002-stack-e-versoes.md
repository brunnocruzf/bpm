# ADR-0002 — Stack e versões fixadas

- **Status:** aceito

| Camada | Versão fixada | Observação |
|---|---|---|
| PHP | 8.4 (php:8.4-fpm-alpine) | extensões pdo_pgsql, intl, zip, bcmath, pcntl, opcache |
| Laravel | 13.x (lock em `apps/api/composer.lock`) | PHPUnit 12, Larastan (nível 5), Pint |
| Flowable REST | `flowable/flowable-rest:7.2.0` | fixar digest em produção (`apps/flowable/Dockerfile`) |
| PostgreSQL | `postgres:17.6-alpine` | bancos `bpm_app` e `flowable`, roles distintos |
| Node (build) | 24 (node:24-alpine) | |
| Vue | 3.5 + TypeScript 6 + Vite 8 | Pinia 4, Vue Router 5 |
| PrimeVue | **4.5.5 (MIT)** + `@primeuix/themes` 2.0.3 + primeicons 7.0.0 | ver ADR-0008 (licença) |
| Form.io | `@formio/js` 5.7 (MIT) | client-side apenas (ADR-0003) |
| bpmn-js | 18.x | licença MIT + marca d'água bpmn.io obrigatória |
| MSAL Browser | 5.x (MIT) | Entra ID (ADR-0004) |
| Nginx | 1.29-alpine | edge proxy, api-web, SPA |
| Storage dev | `rustfs/rustfs:1.0.1` | ADR-0007 |
| Mailpit (dev) | `axllent/mailpit:v1.27` | captura e-mails |

Regras: nada de `latest` em produção; imagens por tag + digest no CI; revisar SBOM/CVEs e licenças a cada atualização.

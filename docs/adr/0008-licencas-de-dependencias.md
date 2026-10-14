# ADR-0008 — Revisão de licenças de dependências

- **Status:** aceito

## Achados

- **PrimeVue 5.x** (e `@primeuix/themes` 3.x, `primeicons` 8.x) passaram a usar a **PrimeUI License** comercial: a licença gratuita vale apenas para organizações com menos de US$ 1 mi de receita, menos de 5 desenvolvedores e menos de 10 funcionários; sem chave, a interface exibe aviso "Invalid PrimeUI License". A TMSA não se enquadra.
- **Decisão:** fixar **PrimeVue 4.5.5 (MIT)**, `@primeuix/themes` 2.0.3 (MIT) e `primeicons` 7.0.0 (MIT). Atualizar para a linha 5 somente com compra de licença ou troca por alternativa (ex.: shadcn-vue, citado na especificação).
- **Form.io**: `@formio/js`, `@formio/core`, `@formio/bootstrap` — MIT. O servidor Form.io (OSL 3.0) **não** é utilizado.
- **bpmn-js**: MIT com condição adicional — a marca d'água bpmn.io deve permanecer visível nos diagramas. Mantida.
- **n8n**: não utilizado (Sustainable Use License).
- Backend PHP: Laravel e dependências diretas (`firebase/php-jwt`, `league/flysystem-aws-s3-v3`) MIT/BSD/Apache-2.0.
- Flowable Open Source: Apache-2.0. PostgreSQL: PostgreSQL License. RustFS: Apache-2.0.

## Regra

Toda atualização de dependência passa por verificação de licença (campo `license` + arquivo LICENSE), SBOM e CVEs no CI antes do merge.

## Atualização (customizações fase 2)

- **Carbone**: Community Edition sob "CCL Agreement" (não permissiva; restringe oferta como serviço) e recursos Enterprise sob licença comercial. **Não habilitado** até aprovação jurídica/segurança — ver [ADR-0011](0011-relatorios-e-carbone.md). Imagem `carbone/carbone-ee:full-5.15.4` fixada por digest apenas no perfil `carbone`.
- **PDF/CSV/XLSX nativos**: código próprio (sem novas dependências). Avaliados e não adotados: Gotenberg (MIT, imagem pesada), dompdf (LGPL-2.1, exige revisão).
- **E-mail**: Symfony Mailer (MIT, já incluso no Laravel); nenhum transport de provedor transacional instalado.
- **Extensão PHP GD** (licença BSD-like) adicionada à imagem para recodificar logos.
- Nenhuma dependência npm nova no SPA (MSAL já presente).

## Atualização (tela de login — ADR-0015)

- **`@fontsource-variable/inter` 5.3.0** e **`@fontsource-variable/manrope` 5.3.0** — fontes sob **SIL Open Font License 1.1** (arquivo LICENSE conferido nos pacotes), versões fixadas; servidas pela própria aplicação (sem Google Fonts em runtime). A OFL permite empacotar com software, proibindo apenas a venda das fontes isoladamente.
- Foto do painel: Unsplash License (uso gratuito, crédito exibido; não permite compilar/revender as fotos como coleção). Confirmar com marca/jurídico ou substituir por foto institucional (pendência 32).

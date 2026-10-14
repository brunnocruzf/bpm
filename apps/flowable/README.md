# Flowable REST (engine BPMN)

Imagem fixada `flowable/flowable-rest:7.2.0`, sem lógica TMSA customizada (ADR-0001/0002).

- Configuração por variáveis no `deploy/docker/docker-compose.yml`: datasource PostgreSQL próprio (`flowable`), usuário técnico (`FLOWABLE_REST_APP_ADMIN_USER_ID/PASSWORD`) usado somente pelo Laravel.
- Rede interna apenas; o proxy de borda bloqueia `/flowable-rest`.
- Actuator expõe só `health`/`info` e exige autenticação.
- Em produção: fixar o digest da imagem, revisar CVEs/SBOM, rotacionar a credencial técnica e não publicar Modeler/Admin UI.

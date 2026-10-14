# Flowable REST — versão fixada (ADR-0001/0002). Sem lógica TMSA customizada nesta imagem.
# Em produção, fixar também o digest (flowable/flowable-rest:7.2.0@sha256:...) e revisar SBOM/CVEs.
FROM flowable/flowable-rest:7.2.0

# Habilita health/info do actuator (sem detalhes) para o healthcheck interno.
ENV MANAGEMENT_ENDPOINTS_WEB_EXPOSURE_INCLUDE=health,info \
    MANAGEMENT_ENDPOINT_HEALTH_SHOW_DETAILS=never \
    FLOWABLE_ASYNC_EXECUTOR_ACTIVATE=true

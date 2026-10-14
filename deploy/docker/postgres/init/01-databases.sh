#!/bin/sh
# Bancos e roles separados: aplicação TMSA (Laravel) e engine (Flowable).
# Laravel nunca faz DML nas tabelas do engine; cada um tem credencial própria.
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<SQL
CREATE ROLE bpm_app LOGIN PASSWORD '${BPM_APP_DB_PASSWORD}';
CREATE ROLE flowable LOGIN PASSWORD '${FLOWABLE_DB_PASSWORD}';
CREATE DATABASE bpm_app OWNER bpm_app;
CREATE DATABASE flowable OWNER flowable;
REVOKE ALL ON DATABASE bpm_app FROM PUBLIC;
REVOKE ALL ON DATABASE flowable FROM PUBLIC;
SQL

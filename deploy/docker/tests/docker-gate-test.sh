#!/usr/bin/env bash
# Confere a lista do filtro docker-gate com o modelo real e containers descartáveis: o que é liberado funciona, o resto é 403.
# Rode depois de qualquer mudança em nginx/docker-gate.conf.template. Não toca nas stacks em uso.
set -u
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")/.."
IMG=nginx:1.29-alpine
STACK=gatetest
failed=0

cleanup() {
  docker rm -f "$STACK-gate" "$STACK-scheduler-1" "$STACK-api-1" "$STACK-client" outra-queue-worker-1 >/dev/null 2>&1
  docker network rm "$STACK" >/dev/null 2>&1
}
trap cleanup EXIT
cleanup

docker network create --internal "$STACK" >/dev/null
for name in "$STACK-scheduler-1" "$STACK-api-1" "$STACK-client" outra-queue-worker-1; do
  docker run -d --name "$name" --network "$STACK" "$IMG" >/dev/null
done
docker run -d --name "$STACK-gate" --network "$STACK" -e BPM_STACK="$STACK" -e NGINX_ENVSUBST_OUTPUT_DIR=/etc/nginx \
  -v /var/run/docker.sock:/var/run/docker.sock:ro -v "$PWD/nginx/docker-gate.conf.template:/etc/nginx/templates/nginx.conf.template:ro" "$IMG" >/dev/null

until docker exec "$STACK-client" curl -s -o /dev/null --max-time 2 "http://$STACK-gate:2375/"; do
  [ "$(docker inspect -f '{{.State.Running}}' "$STACK-gate")" = true ] || { docker logs --tail 5 "$STACK-gate"; exit 1; }
done

check() {
  code=$(docker exec "$STACK-client" curl -s -o /dev/null -w '%{http_code}' --path-as-is --max-time 40 -X "$2" "http://$STACK-gate:2375$3")
  if [ "$code" = "$4" ]; then
    printf '  ok    %-48s %s\n' "$1" "$code"
  else
    printf '  FALHA %-48s %s (esperado %s)\n' "$1" "$code" "$4"
    failed=1
  fi
}

image=$(docker inspect -f '{{.Image}}' "$STACK-api-1")

echo '-- liberado'
check 'consultar serviço da stack'            GET  "/containers/$STACK-api-1/json" 200
check 'com prefixo de versão da API'          GET  "/v1.47/containers/$STACK-api-1/json" 200
check 'parar serviço da lista'                POST "/containers/$STACK-scheduler-1/stop?t=1" 204
check 'iniciar serviço da lista'              POST "/containers/$STACK-scheduler-1/start" 204
check 'exportar imagem por ID'                GET  "/images/$image/get" 200

echo '-- negado'
check 'parar a API (fora da lista)'           POST "/containers/$STACK-api-1/stop" 403
check 'parar o próprio filtro'                POST "/containers/$STACK-gate/stop" 403
check 'parar outra stack'                     POST /containers/outra-queue-worker-1/stop 403
check 'consultar outra stack'                 GET  /containers/outra-queue-worker-1/json 403
check 'criar container'                       POST /containers/create 403
check 'exec'                                  POST "/containers/$STACK-api-1/exec" 403
check 'apagar container'                      DELETE "/containers/$STACK-api-1" 403
check 'listar todos os containers'            GET  /containers/json 403
check 'carregar imagem'                       POST /images/load 403
check 'baixar imagem'                         POST '/images/create?fromImage=alpine' 403
check 'apagar imagem'                         DELETE "/images/$image" 403
check 'exportar imagem por nome'              GET  /images/nginx:1.29-alpine/get 403
check 'ler dados do host'                     GET  /info 403
check 'método errado no stop'                 GET  "/containers/$STACK-scheduler-1/stop" 403
check 'método errado no export'               POST "/images/$image/get" 403

echo '-- contorno pelo caminho'
check 'subir pasta para criar'                POST "/containers/$STACK-scheduler-1/stop/../../create" 403
check 'subir pasta codificada'                POST "/containers/$STACK-scheduler-1/stop/%2e%2e/%2e%2e/create" 403
check 'barra codificada'                      POST "/containers/$STACK-scheduler-1%2fexec" 403

[ "$failed" = 0 ] && echo 'Filtro conferido.' || { echo 'O filtro não está como deveria.'; exit 1; }

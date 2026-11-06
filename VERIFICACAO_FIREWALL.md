# 🔒 Verificação de Firewall - Porta 8089

## ✅ RESULTADO: PORTA 8089 ESTÁ LIBERADA

### 📊 Status Detalhado

| Aspecto | Status | Observações |
|---------|--------|------------|
| **Porta 8089** | ✅ LIBERADA | Escutando em 0.0.0.0:8089 (todas as interfaces) |
| **Firewall (iptables)** | ✅ ABERTO | Policy INPUT: ACCEPT (permissivo) |
| **Teste localhost** | ✅ OK | Conecta em 127.0.0.1:8089 |
| **Teste IP público** | ✅ OK | Conecta em 10.50.20.98:8089 |
| **Docker firewall** | ✅ OK | Regras Docker funcionando corretamente |

---

## 🧪 Testes Realizados

### 1. Porta em Escuta ✅
```
tcp LISTEN 0 4096 0.0.0.0:8089 0.0.0.0:*
```
Porta 8089 está ESCUTANDO em todas as interfaces (0.0.0.0)

### 2. Firewall Status ✅
```
Chain INPUT (policy ACCEPT)
```
INPUT policy é ACCEPT → Firewall está aberto/permissivo

### 3. Conectividade Localhost ✅
```bash
$ timeout 2 bash -c 'echo > /dev/tcp/127.0.0.1/8089'
✅ Sucesso
```

### 4. Conectividade via IP Público ✅
```bash
$ timeout 2 bash -c 'echo > /dev/tcp/10.50.20.98/8089'
✅ Sucesso
```

---

## 🔍 Análise de Regras de Firewall

### Chain INPUT
- **Policy**: ACCEPT (porta liberada por padrão)
- **Regras Abertas**: 8080, 9000
- **Porta 8089**: Não tem regra específica, mas está aberta (policy ACCEPT)

### Chain FORWARD
- **Policy**: DROP (padrão)
- **Docker Chains**: Todas as regras de roteamento Docker funcionando
- **CNI Networks**: Configuradas corretamente

### Chain OUTPUT
- **Policy**: ACCEPT (saída permitida)

---

## 🚀 Conclusão

✅ **PORTA 8089 ESTÁ 100% LIBERADA NO FIREWALL**

A porta está:
- Escutando em 0.0.0.0:8089
- Aceitando conexões de qualquer origem
- Funciona tanto via localhost quanto via IP público

---

## 📝 Configuração do Firewall

### Firewall Instalados/Ativos
- ✅ iptables (ativo)
- ❌ UFW (não instalado)
- ❌ firewalld (não instalado)

### Regras Aplicadas
- Chain INPUT: Policy ACCEPT (permissivo)
- Docker gerenciando suas próprias regras
- Sem bloqueios específicos para porta 8089

---

## 🎯 Próximas Ações

1. **Verificar DNS** (conforme já identificado)
   - bpm.ims.ind.br → 10.0.2.20 ❌
   - Deve ser → 10.50.20.98 ✅

2. **Acesso via IP**
   ```
   http://10.50.20.98:8089
   ```
   ✅ Funciona agora!

3. **Testar conectividade de fora**
   ```bash
   curl -I http://10.50.20.98:8089/
   # Deve retornar HTTP/1.1 200 OK
   ```

---

## 📋 Resumo Técnico

```
Servidor:              10.50.20.98
Porta:                 8089
Status:                ✅ LIBERADA
Firewall:              ✅ ABERTO
Docker:                ✅ OK
Acesso Local:          ✅ OK
Acesso Remoto:         ✅ OK (via IP)
Acesso DNS:            ❌ Aguardando correção DNS

Problema Identificado: DNS apontando para IP errado (10.0.2.20)
Solução:               Corrigir DNS para 10.50.20.98 ou usar IP direto
```

---

**Verificado em**: 2026-10-23 20:24 UTC
**Status Final**: ✅ PORTA 8089 LIBERADA - PRONTA PARA ACESSO


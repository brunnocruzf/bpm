<script setup lang="ts">
import type { SettingView } from '@/api/types'
import { fmtDateTime } from '@/composables/format'
import SettingStatus from './SettingStatus.vue'

defineProps<{ setting: SettingView | null; title: string; subtitle: string }>()
</script>

<template>
  <div class="tm-heading" style="margin-top:0">
    <div>
      <h1>{{ title }}</h1>
      <p class="tm-subtitle">{{ subtitle }}</p>
    </div>
    <div v-if="setting" class="meta">
      <SettingStatus :status="setting.status" />
      <div class="tm-hint">
        Versão {{ setting.version }}<template v-if="setting.updatedAt && setting.updatedBy"> · alterada em {{ fmtDateTime(setting.updatedAt) }} por {{ setting.updatedBy }}</template>
      </div>
      <div v-if="setting.statusDetail" class="tm-hint">{{ setting.statusDetail }}</div>
    </div>
  </div>
  <div v-if="setting?.lastValidation" class="tm-note" :class="setting.lastValidation.ok ? '' : 'err'" role="status" style="margin:-8px 0 16px">
    <i class="pi" :class="setting.lastValidation.ok ? 'pi-check-circle' : 'pi-times-circle'" aria-hidden="true" />
    <div style="flex:1">
      <strong>Última validação: {{ setting.lastValidation.ok ? 'sucesso' : 'falhou' }}</strong>
      <span v-if="setting.lastValidation.code" class="tm-muted"> · código <code>{{ setting.lastValidation.code }}</code></span>
      <span class="tm-muted"> · {{ fmtDateTime(setting.lastValidatedAt) }}<template v-if="setting.lastValidation.by"> por {{ setting.lastValidation.by }}</template><template v-if="setting.lastValidation.durationMs != null"> · {{ setting.lastValidation.durationMs }} ms</template></span>
      <div v-if="setting.lastValidation.message" style="margin-top:3px">{{ setting.lastValidation.message }}</div>
      <ul v-if="setting.lastValidation.checks?.length" class="checks">
        <li v-for="c in setting.lastValidation.checks" :key="c.name">
          <i class="pi" :class="c.ok ? (c.warning ? 'pi-exclamation-triangle' : 'pi-check') : 'pi-times'" :style="{ color: c.ok ? (c.warning ? 'var(--amber)' : 'var(--green)') : 'var(--red)' }" aria-hidden="true" />
          <span class="tm-sr-only">{{ c.ok ? (c.warning ? 'Alerta' : 'OK') : 'Falhou' }}:</span> {{ c.name }}<template v-if="(!c.ok || c.warning) && c.message"> — {{ c.message }}</template>
          <div v-if="c.detail" class="tm-hint detail">{{ c.detail }}</div>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.meta { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; text-align: right; max-width: 420px; }
.checks { margin: 6px 0 0; padding-left: 0; list-style: none; display: grid; gap: 3px; }
.detail { margin: 1px 0 0 20px; overflow-wrap: anywhere; }
code { font-size: 11px; background: rgba(0, 0, 0, 0.05); padding: 1px 4px; border-radius: 4px; }
</style>

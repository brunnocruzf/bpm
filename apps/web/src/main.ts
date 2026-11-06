import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import ConfirmationService from 'primevue/confirmationservice'
import Tooltip from 'primevue/tooltip'
import 'primeicons/primeicons.css'
import './styles/formio.scss'
import './styles/app.scss'
import App from './App.vue'
import { router } from './router'
import { TmsaPreset } from './theme'
import { auth } from './auth'
import { setMaintenanceHandler, setUnauthorizedHandler } from './api/http'
import { useBranding } from './stores/branding'

async function bootstrap() {
  await auth.init()

  const app = createApp(App)
  const pinia = createPinia()
  app.use(pinia)
  app.use(router)
  app.use(PrimeVue, {
    theme: { preset: TmsaPreset, options: { darkModeSelector: false, cssLayer: false } },
    locale: {
      accept: 'Sim', reject: 'Não', cancel: 'Cancelar', choose: 'Escolher', upload: 'Enviar', clear: 'Limpar',
      emptyMessage: 'Nenhum resultado', emptyFilterMessage: 'Nenhum resultado', searchMessage: '{0} resultados',
      selectionMessage: '{0} selecionados', emptySelectionMessage: 'Nenhum selecionado',
      dayNames: ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'],
      monthNames: ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'],
      firstDayOfWeek: 0, dateFormat: 'dd/mm/yy',
    },
  })
  app.use(ToastService)
  app.use(ConfirmationService)
  app.directive('tooltip', Tooltip)

  setMaintenanceHandler(() => {
    if (router.currentRoute.value.name !== 'maintenance') router.replace({ name: 'maintenance' })
  })
  setUnauthorizedHandler(() => {
    // sessão de visitante/SuperAdmin expirada ou revogada no servidor
    auth.expireLocal()
    if (!router.currentRoute.value.meta.public) {
      router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
    }
  })

  // identidade visual aplicada (pública) antes de renderizar — inclusive na tela de login
  await useBranding(pinia).load()
  app.mount('#app')
}

bootstrap().catch((e) => {
  document.getElementById('app')!.innerHTML = `<div style="padding:40px;font-family:sans-serif"><h2>Não foi possível iniciar o BPM</h2><p>${String(e?.message ?? e).replace(/[<>&]/g, '')}</p></div>`
})

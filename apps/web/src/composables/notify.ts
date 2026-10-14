import { useToast } from 'primevue/usetoast'
import { errorMessage } from '@/api/http'

/** Toasts padronizados (sucesso/erro) no estilo do protótipo. */
export function useNotify() {
  const toast = useToast()
  return {
    success(detail: string, summary = 'Pronto') {
      toast.add({ severity: 'success', summary, detail, life: 4000 })
    },
    info(detail: string, summary = 'Informação') {
      toast.add({ severity: 'info', summary, detail, life: 4000 })
    },
    error(e: unknown, summary = 'Não foi possível concluir') {
      toast.add({ severity: 'error', summary, detail: errorMessage(e), life: 6500 })
    },
  }
}

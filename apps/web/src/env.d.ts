/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_BASE_URL?: string
  readonly VITE_AUTH_MODE?: 'dev' | 'entra'
  readonly VITE_ENTRA_CLIENT_ID?: string
  readonly VITE_ENTRA_TENANT_ID?: string
  readonly VITE_ENTRA_API_SCOPE?: string
  readonly VITE_ENV_LABEL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<object, object, unknown>
  export default component
}

declare module 'bpmn-js/lib/Modeler' {
  const Modeler: any
  export default Modeler
}
declare module 'bpmn-js/lib/NavigatedViewer' {
  const Viewer: any
  export default Viewer
}

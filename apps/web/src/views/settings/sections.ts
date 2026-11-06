export interface SettingsSection { name: string; slug: string; label: string; icon: string; description: string }

export const SETTINGS_SECTIONS: SettingsSection[] = [
  { name: 'settings-branding', slug: 'branding', label: 'Organização e identidade visual', icon: 'pi-palette', description: 'Nome, logos, favicon e cores com contraste acessível.' },
  { name: 'settings-entra-auth', slug: 'entra/auth', label: 'Entra ID — login da aplicação', icon: 'pi-sign-in', description: 'SPA (MSAL + PKCE) e validação do access token na API.' },
  { name: 'settings-entra-directory', slug: 'entra/directory-sync', label: 'Entra ID — usuários e grupos', icon: 'pi-users', description: 'Importação via Microsoft Graph (app-only, somente leitura).' },
  { name: 'settings-email', slug: 'email', label: 'E-mail e notificações', icon: 'pi-envelope', description: 'Provedor, remetente, teste de conexão e envio de teste.' },
  { name: 'settings-audit', slug: 'audit-retention', label: 'Auditoria e retenção', icon: 'pi-shield', description: 'Prazo aprovado, arquivamento e expurgo controlado.' },
  { name: 'settings-reports', slug: 'reports', label: 'Relatórios e modelos', icon: 'pi-file-pdf', description: 'Renderer, confidencialidade e modelos versionados.' },
  { name: 'settings-backups', slug: 'backups', label: 'Backup e recuperação', icon: 'pi-database', description: 'Agendamentos, restauração, envio ao Azure e preparo para recuperação de desastre.' },
  { name: 'settings-attachments-storage', slug: 'attachments-storage', label: 'Armazenamento de anexos', icon: 'pi-cloud-upload', description: 'Onde os arquivos dos formulários são gravados: plataforma ou Azure Blob Storage.' },
]

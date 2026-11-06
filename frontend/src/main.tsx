import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import './i18n'
import './styles/index.css'
import { applyTheme, initialTheme } from '@/lib/theme'
import { AuthProvider } from '@/auth/AuthContext'
import App from './App'
import { ToastProvider } from '@/components/Toast'

applyTheme(initialTheme())
const qc = new QueryClient({ defaultOptions: { queries: { retry: false, refetchOnWindowFocus: false, staleTime: 30_000 } } })

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={qc}>
      <BrowserRouter>
        <ToastProvider><AuthProvider><App /></AuthProvider></ToastProvider>
      </BrowserRouter>
    </QueryClientProvider>
  </StrictMode>,
)

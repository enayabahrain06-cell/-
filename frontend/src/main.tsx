import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import './lib/i18n'
import './index.css'
import { AuthProvider } from './app/AuthContext'
import { AppCertificatesProvider } from './app/certificates'
import { AppIdCardProvider } from './app/idCard'
import { router } from './app/routes'
import { initOrnamentLevel } from './lib/ornament'
import { registerPwa } from './lib/pwa'

initOrnamentLevel()
registerPwa()

const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: 1, refetchOnWindowFocus: false } },
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <AppCertificatesProvider>
          <AppIdCardProvider>
            <RouterProvider router={router} />
          </AppIdCardProvider>
        </AppCertificatesProvider>
      </AuthProvider>
    </QueryClientProvider>
  </StrictMode>,
)

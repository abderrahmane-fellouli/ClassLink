import { RouterProvider } from 'react-router-dom'
import { AuthProvider } from './context/AuthContext'
import { I18nProvider } from './i18n'
import { router } from './router'

export default function App() {
  return (
    <I18nProvider>
      <AuthProvider>
        <RouterProvider router={router}/>
      </AuthProvider>
    </I18nProvider>
  )
}

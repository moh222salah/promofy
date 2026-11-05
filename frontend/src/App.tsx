import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import AdminLayout from '@/layouts/AdminLayout'
import { Spinner } from '@/components/ui'
import ResourcePage from '@/components/crud/ResourcePage'
import { serviceCategories, services } from '@/pages/admin/modules'

const Login = lazy(() => import('@/pages/admin/Login'))
const Dashboard = lazy(() => import('@/pages/admin/Dashboard'))

export default function App() {
  return (
    <Suspense fallback={<Spinner />}>
      <Routes>
        <Route path="/admin/login" element={<Login />} />
        <Route path="/admin" element={<AdminLayout />}>
          <Route index element={<Dashboard />} />
          <Route path="services" element={<ResourcePage cfg={services} />} />
          <Route path="service-categories" element={<ResourcePage cfg={serviceCategories} />} />
        </Route>
        <Route path="*" element={<Navigate to="/admin" replace />} />
      </Routes>
    </Suspense>
  )
}

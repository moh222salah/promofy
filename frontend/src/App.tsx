import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import AdminLayout from '@/layouts/AdminLayout'
import { Spinner } from '@/components/ui'
import ResourcePage from '@/components/crud/ResourcePage'
import { serviceCategories, services } from '@/pages/admin/modules'
import * as commerce from '@/pages/admin/modules-commerce'
import OrderDetail from '@/pages/admin/OrderDetail'
import * as content from '@/pages/admin/modules-content'

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
          <Route path="products" element={<ResourcePage cfg={content.products} />} />
          <Route path="product-categories" element={<ResourcePage cfg={content.productCategories} />} />
          <Route path="portfolio" element={<ResourcePage cfg={content.portfolio} />} />
          <Route path="articles" element={<ResourcePage cfg={content.articles} />} />
          <Route path="article-categories" element={<ResourcePage cfg={content.articleCategories} />} />
          <Route path="testimonials" element={<ResourcePage cfg={content.testimonials} />} />
          <Route path="orders" element={<ResourcePage cfg={commerce.orders} />} />
          <Route path="orders/:id" element={<OrderDetail />} />
          <Route path="payments" element={<ResourcePage cfg={commerce.payments} />} />
          <Route path="customers" element={<ResourcePage cfg={commerce.customers} />} />
          <Route path="team" element={<ResourcePage cfg={content.team} />} />
        </Route>
        <Route path="*" element={<Navigate to="/admin" replace />} />
      </Routes>
    </Suspense>
  )
}

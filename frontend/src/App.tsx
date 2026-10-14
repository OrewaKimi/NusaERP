import { QueryClient, QueryClientProvider, useQuery, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
import { useEffect, useState } from 'react'
import { BrowserRouter, Link, Navigate, Route, Routes } from 'react-router-dom'
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'

type Product = {
  id: number
  sku: string
  name: string
  price: number
  stock: number
  min_stock: number
  description?: string
}

type Customer = {
  id: number
  name: string
  email?: string
  phone?: string
  address?: string
}

type Supplier = {
  id: number
  name: string
  email?: string
  phone?: string
  address?: string
}

type Contact = Customer | Supplier

type ProductForm = {
  sku: string
  name: string
  price: string
  stock: string
  min_stock: string
  description: string
}

type ContactForm = {
  name: string
  email: string
  phone: string
  address: string
}

type StockMovement = {
  id: number
  type: 'in' | 'out' | 'adjust'
  qty: number
  ref_type: string
  ref_id: number
  note?: string
  created_at: string
}

type SalesOrder = {
  id: number
  status: string
  total: number
  created_at: string
  customer: Customer
  invoice?: { id: number; number: string; amount: number; status: string; due_date?: string }
}

type PurchaseOrder = {
  id: number
  status: 'draft' | 'submitted' | 'approved' | 'received'
  total: number
  created_at: string
  supplier: Supplier
  items: Array<{ id: number; qty: number; cost: number; product: Product }>
}

type Summary = {
  total_revenue: number
  total_orders: number
  unpaid_invoices: number
  low_stock_products: number
}

type UserRole = 'admin' | 'sales' | 'gudang'

const API_BASE = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api'
const queryClient = new QueryClient()
const currency = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
})

const getHeaders = (token: string | null) => ({
  Authorization: token ? `Bearer ${token}` : undefined,
})

const getErrorMessage = (error: unknown) => {
  if (axios.isAxiosError(error)) {
    const errors = error.response?.data?.errors as Record<string, string[]> | undefined
    return errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message ?? 'Permintaan gagal. Coba lagi.'
  }
  return 'Permintaan gagal. Coba lagi.'
}

const emptyProductForm: ProductForm = {
  sku: '',
  name: '',
  price: '0',
  stock: '0',
  min_stock: '0',
  description: '',
}

const emptyContactForm: ContactForm = { name: '', email: '', phone: '', address: '' }

function App() {
  const [token, setToken] = useState<string | null>(() => localStorage.getItem('nusaerp_token'))
  const [role, setRole] = useState<UserRole | null>(() => localStorage.getItem('nusaerp_role') as UserRole | null)

  useEffect(() => {
    if (!token) return

    let active = true
    axios.get(`${API_BASE}/me`, { headers: getHeaders(token) })
      .then((response) => {
        if (active) {
          setRole(response.data.user.role)
          localStorage.setItem('nusaerp_role', response.data.user.role)
        }
      })
      .catch((error: unknown) => {
        if (active && axios.isAxiosError(error) && error.response?.status === 401) {
          localStorage.removeItem('nusaerp_token')
          setToken(null)
          setRole(null)
          localStorage.removeItem('nusaerp_role')
        }
      })

    return () => {
      active = false
    }
  }, [token])

  const handleLogin = (newToken: string, newRole: UserRole) => {
    setToken(newToken)
    setRole(newRole)
    localStorage.setItem('nusaerp_token', newToken)
    localStorage.setItem('nusaerp_role', newRole)
  }

  const handleLogout = async () => {
    if (!token) return

    try {
      await axios.post(`${API_BASE}/logout`, {}, { headers: getHeaders(token) })
    } catch {
      // ignore
    }

    setToken(null)
    setRole(null)
    localStorage.removeItem('nusaerp_token')
    localStorage.removeItem('nusaerp_role')
  }

  return (
    <QueryClientProvider client={queryClient}>
      {token ? (
        <BrowserRouter>
          <AppShell token={token} role={role ?? 'admin'} onLogout={handleLogout} />
        </BrowserRouter>
      ) : (
        <LoginPage onLogin={handleLogin} />
      )}
    </QueryClientProvider>
  )
}

function AppShell({ token, role, onLogout }: { token: string; role: UserRole; onLogout: () => void }) {
  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div>
          <div className="brand">NusaERP Lite</div>
          <nav className="nav">
            <Link to="/">Dashboard</Link>
            {(role === 'admin' || role === 'gudang') && <Link to="/products">Inventory</Link>}
            {(role === 'admin' || role === 'sales') && <Link to="/customers">Customers</Link>}
            {(role === 'admin' || role === 'gudang') && <Link to="/suppliers">Suppliers</Link>}
            {(role === 'admin' || role === 'sales') && <Link to="/sales">Sales</Link>}
            {(role === 'admin' || role === 'gudang') && <Link to="/purchases">Purchasing</Link>}
          </nav>
        </div>
        <button type="button" className="logout-btn" onClick={onLogout}>Logout</button>
      </aside>

      <main className="page">
        <Routes>
          <Route path="/" element={<DashboardPage token={token} role={role} />} />
          <Route path="/products" element={role === 'admin' || role === 'gudang' ? <ProductsPage token={token} /> : <Navigate to="/" replace />} />
          <Route path="/customers" element={role === 'admin' || role === 'sales' ? <ContactsPage token={token} resource="customers" title="Customers" /> : <Navigate to="/" replace />} />
          <Route path="/suppliers" element={role === 'admin' || role === 'gudang' ? <ContactsPage token={token} resource="suppliers" title="Suppliers" /> : <Navigate to="/" replace />} />
          <Route path="/sales" element={role === 'admin' || role === 'sales' ? <SalesPage token={token} /> : <Navigate to="/" replace />} />
          <Route path="/purchases" element={role === 'admin' || role === 'gudang' ? <PurchasesPage token={token} /> : <Navigate to="/" replace />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>
    </div>
  )
}

function LoginPage({ onLogin }: { onLogin: (token: string, role: UserRole) => void }) {
  const [email, setEmail] = useState('admin@nusaerp.test')
  const [password, setPassword] = useState('password')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()
    setLoading(true)
    setError('')

    try {
      const response = await axios.post(`${API_BASE}/login`, { email, password })
      onLogin(response.data.token, response.data.user.role)
    } catch (err) {
      setError('Login gagal. Cek email dan password demo.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="login-screen">
      <div className="login-card">
        <div className="eyebrow">Mini ERP</div>
        <h1>NusaERP Lite</h1>
        <p>Silakan login untuk membuka dashboard operasional.</p>
        <form onSubmit={handleSubmit} className="login-form">
          <label>
            Email
            <input value={email} onChange={(e) => setEmail(e.target.value)} />
          </label>
          <label>
            Password
            <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
          </label>
          {error && <div className="error-box">{error}</div>}
          <button type="submit" disabled={loading}>{loading ? 'Memproses...' : 'Login'}</button>
        </form>
        <small>Demo account: admin@nusaerp.test / password</small>
      </div>
    </div>
  )
}

function DashboardPage({ token, role }: { token: string; role: UserRole }) {
  const { data: summary } = useQuery({
    queryKey: ['summary', token],
    queryFn: async () => {
      const response = await axios.get(`${API_BASE}/dashboard/summary`, { headers: getHeaders(token) })
      return response.data as Summary
    },
  })

  const { data: chartData = [] } = useQuery({
    queryKey: ['chart', token],
    queryFn: async () => {
      const response = await axios.get(`${API_BASE}/dashboard/sales-chart`, { headers: getHeaders(token) })
      return response.data as Array<{ date: string; total: number }>
    },
  })

  const { data: exchangeRate } = useQuery({
    queryKey: ['exchange-rate', 'USD'],
    queryFn: async () => {
      const response = await axios.get(`${API_BASE}/external/exchange-rate?to=USD`)
      return response.data as { date: string; rates: { USD: number } }
    },
    staleTime: 60 * 60 * 1000,
  })

  return (
    <div>
      <header className="page-header">
        <div>
          <div className="eyebrow">Overview</div>
          <h2>Dashboard</h2>
        </div>
      </header>

      <div className="stats-grid">
        <StatCard title="Omzet" value={currency.format(summary?.total_revenue ?? 0)} tone="green" />
        <StatCard title="Jumlah Order" value={String(summary?.total_orders ?? 0)} tone="blue" />
        <StatCard title="Invoice belum dibayar" value={String(summary?.unpaid_invoices ?? 0)} tone="amber" />
        <StatCard title="Stok menipis" value={String(summary?.low_stock_products ?? 0)} tone="red" />
      </div>

      <section className="quick-actions" aria-label="Master data">
        {(role === 'admin' || role === 'gudang') && <Link to="/products"><span>Inventory</span><strong>Kelola produk</strong></Link>}
        {(role === 'admin' || role === 'sales') && <Link to="/customers"><span>Sales</span><strong>Kelola customer</strong></Link>}
        {(role === 'admin' || role === 'gudang') && <Link to="/suppliers"><span>Purchasing</span><strong>Kelola supplier</strong></Link>}
      </section>

      <div className="rate-strip">Kurs referensi: 1 IDR = {exchangeRate?.rates.USD ?? '...'} USD <span>{exchangeRate?.date ?? ''}</span></div>

      <div className="panel chart-panel">
        <h3>Penjualan 7 hari</h3>
        <div style={{ width: '100%', height: 280 }}>
          <ResponsiveContainer>
            <AreaChart data={chartData}>
              <defs>
                <linearGradient id="fillSales" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="#3b82f6" stopOpacity={0.8} />
                  <stop offset="95%" stopColor="#3b82f6" stopOpacity={0.1} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
              <XAxis dataKey="date" tickLine={false} axisLine={false} />
              <YAxis tickLine={false} axisLine={false} />
              <Tooltip formatter={(value) => currency.format(Number(Array.isArray(value) ? value[0] : value ?? 0))} />
              <Area type="monotone" dataKey="total" stroke="#2563eb" fill="url(#fillSales)" strokeWidth={2} />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </div>
    </div>
  )
}

function ProductsPage({ token }: { token: string }) {
  const queryClient = useQueryClient()
  const { data: products = [], refetch, isPending, isError, error: loadError } = useQuery({
    queryKey: ['products', token],
    queryFn: async () => {
      const response = await axios.get(`${API_BASE}/products`, { headers: getHeaders(token) })
      return response.data as Product[]
    },
  })

  const [form, setForm] = useState<ProductForm>(emptyProductForm)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [adjustment, setAdjustment] = useState({ product_id: '', direction: 'in', qty: '1', note: '' })
  const [movementProductId, setMovementProductId] = useState<number | null>(null)
  const { data: movements = [], isPending: movementsPending } = useQuery({
    queryKey: ['product-movements', token, movementProductId],
    enabled: movementProductId !== null,
    queryFn: async () => {
      const response = await axios.get(`${API_BASE}/products/${movementProductId}/movements`, { headers: getHeaders(token) })
      return response.data as StockMovement[]
    },
  })

  const saveProduct = async (event: React.FormEvent) => {
    event.preventDefault()
    setBusy(true)
    setError('')
    setMessage('')

    const payload = {
      ...form,
      price: Number(form.price),
      stock: Number(form.stock),
      min_stock: Number(form.min_stock),
    }

    try {
      if (editingId) {
        await axios.put(`${API_BASE}/products/${editingId}`, payload, { headers: getHeaders(token) })
        setMessage('Produk berhasil diperbarui.')
      } else {
        await axios.post(`${API_BASE}/products`, payload, { headers: getHeaders(token) })
        setMessage('Produk berhasil ditambahkan.')
      }
      setForm(emptyProductForm)
      setEditingId(null)
      await queryClient.invalidateQueries({ queryKey: ['products', token] })
      await queryClient.invalidateQueries({ queryKey: ['sale-products', token] })
      await queryClient.invalidateQueries({ queryKey: ['purchase-products', token] })
    } catch (saveError) {
      setError(getErrorMessage(saveError))
    } finally {
      setBusy(false)
    }
  }

  const editProduct = (product: Product) => {
    setEditingId(product.id)
    setForm({
      sku: product.sku,
      name: product.name,
      price: String(product.price),
      stock: String(product.stock),
      min_stock: String(product.min_stock),
      description: product.description ?? '',
    })
    setError('')
    setMessage('')
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const deleteProduct = async (product: Product) => {
    if (!window.confirm(`Hapus produk “${product.name}”?`)) return
    setError('')
    setMessage('')
    try {
      await axios.delete(`${API_BASE}/products/${product.id}`, { headers: getHeaders(token) })
      setMessage('Produk berhasil dihapus.')
      await refetch()
      await queryClient.invalidateQueries({ queryKey: ['sale-products', token] })
      await queryClient.invalidateQueries({ queryKey: ['purchase-products', token] })
    } catch (deleteError) {
      setError(getErrorMessage(deleteError))
    }
  }

  const adjustStock = async (event: React.FormEvent) => {
    event.preventDefault()
    setError('')
    setMessage('')
    try {
      await axios.post(`${API_BASE}/products/${adjustment.product_id}/adjust-stock`, {
        direction: adjustment.direction,
        qty: Number(adjustment.qty),
        note: adjustment.note,
      }, { headers: getHeaders(token) })
      setMessage('Penyesuaian stok dicatat.')
      setAdjustment({ product_id: '', direction: 'in', qty: '1', note: '' })
      await queryClient.invalidateQueries({ queryKey: ['products', token] })
      await queryClient.invalidateQueries({ queryKey: ['product-movements', token] })
    } catch (adjustError) {
      setError(getErrorMessage(adjustError))
    }
  }

  return (
    <div>
      <header className="page-header"><div><div className="eyebrow">Inventory</div><h2>Produk</h2></div></header>
      <form className="panel" onSubmit={saveProduct}>
        <div className="section-heading"><h3>{editingId ? 'Ubah produk' : 'Tambah produk'}</h3>{editingId && <button type="button" className="text-btn" onClick={() => { setEditingId(null); setForm(emptyProductForm) }}>Batal</button>}</div>
        <div className="form-grid">
          <label>SKU<input required maxLength={100} value={form.sku} onChange={(e) => setForm({ ...form, sku: e.target.value })} /></label>
          <label>Nama produk<input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
          <label>Harga<input required min={0} type="number" value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} /></label>
          <label>Stok<input required min={0} type="number" value={form.stock} onChange={(e) => setForm({ ...form, stock: e.target.value })} /></label>
          <label>Stok minimum<input required min={0} type="number" value={form.min_stock} onChange={(e) => setForm({ ...form, min_stock: e.target.value })} /></label>
          <label>Deskripsi<input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></label>
        </div>
        {error && <div className="error-box" role="alert">{error}</div>}
        {message && <div className="success-box" role="status">{message}</div>}
        <button type="submit" disabled={busy}>{busy ? 'Menyimpan...' : editingId ? 'Simpan perubahan' : 'Simpan produk'}</button>
      </form>

      <form className="panel" onSubmit={adjustStock}>
        <h3>Penyesuaian stok</h3>
        <div className="form-grid">
          <label>Produk<select required value={adjustment.product_id} onChange={(event) => setAdjustment({ ...adjustment, product_id: event.target.value })}><option value="">Pilih produk</option>{products.map((product) => <option key={product.id} value={product.id}>{product.sku} · {product.name}</option>)}</select></label>
          <label>Jenis<select value={adjustment.direction} onChange={(event) => setAdjustment({ ...adjustment, direction: event.target.value })}><option value="in">Stok masuk</option><option value="out">Stok keluar</option></select></label>
          <label>Jumlah<input required min={1} type="number" value={adjustment.qty} onChange={(event) => setAdjustment({ ...adjustment, qty: event.target.value })} /></label>
          <label>Catatan<input value={adjustment.note} onChange={(event) => setAdjustment({ ...adjustment, note: event.target.value })} /></label>
        </div>
        <button type="submit" disabled={!adjustment.product_id}>Catat penyesuaian</button>
      </form>

      <div className="panel table-panel">
        <div className="section-heading"><h3>Daftar produk</h3><span>{products.length} produk</span></div>
        <div className="table-scroll">
          <table>
            <thead>
              <tr><th>SKU</th><th>Nama</th><th>Harga</th><th>Stok</th><th>Min</th><th>Aksi</th></tr>
            </thead>
            <tbody>
              {isPending && <tr><td colSpan={6} className="empty-state">Memuat produk...</td></tr>}
              {isError && <tr><td colSpan={6} className="empty-state error-text">{getErrorMessage(loadError)}</td></tr>}
              {products.map((product) => (
                <tr key={product.id}>
                  <td>{product.sku}</td>
                  <td>{product.name}</td>
                  <td>{currency.format(product.price)}</td>
                  <td>{product.stock}</td>
                  <td>{product.min_stock}</td>
                  <td><div className="row-actions"><button type="button" className="text-btn" onClick={() => editProduct(product)}>Ubah</button><button type="button" className="text-btn" onClick={() => setMovementProductId(movementProductId === product.id ? null : product.id)}>Riwayat</button><button type="button" className="danger-btn" onClick={() => deleteProduct(product)}>Hapus</button></div></td>
                </tr>
              ))}
              {!isPending && !isError && !products.length && <tr><td colSpan={6} className="empty-state">Belum ada produk.</td></tr>}
            </tbody>
          </table>
        </div>
        {movementProductId !== null && <div className="movement-panel">
          <div className="section-heading"><h3>Riwayat stok: {products.find((product) => product.id === movementProductId)?.name}</h3><button type="button" className="text-btn" onClick={() => setMovementProductId(null)}>Tutup</button></div>
          {movementsPending ? <p>Memuat riwayat...</p> : movements.length ? <div className="table-scroll"><table><thead><tr><th>Tanggal</th><th>Tipe</th><th>Qty</th><th>Referensi</th><th>Catatan</th></tr></thead><tbody>{movements.map((movement) => <tr key={movement.id}><td>{new Date(movement.created_at).toLocaleString('id-ID')}</td><td>{movement.type}</td><td>{movement.qty}</td><td>{movement.ref_type} #{movement.ref_id}</td><td>{movement.note || '-'}</td></tr>)}</tbody></table></div> : <p className="empty-state">Belum ada pergerakan stok.</p>}
        </div>}
      </div>
    </div>
  )
}

function ContactsPage({ token, resource, title }: { token: string; resource: 'customers' | 'suppliers'; title: string }) {
  const queryClient = useQueryClient()
  const queryKey = [resource, token]
  const { data: contacts = [], isPending, isError, error: loadError } = useQuery({
    queryKey,
    queryFn: async () => {
      const response = await axios.get(`${API_BASE}/${resource}`, { headers: getHeaders(token) })
      return response.data as Contact[]
    },
  })
  const [form, setForm] = useState<ContactForm>(emptyContactForm)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  const saveContact = async (event: React.FormEvent) => {
    event.preventDefault()
    setBusy(true)
    setError('')
    setMessage('')
    const payload = { ...form, email: form.email || null, phone: form.phone || null, address: form.address || null }

    try {
      if (editingId) {
        await axios.put(`${API_BASE}/${resource}/${editingId}`, payload, { headers: getHeaders(token) })
        setMessage(`${title.slice(0, -1)} berhasil diperbarui.`)
      } else {
        await axios.post(`${API_BASE}/${resource}`, payload, { headers: getHeaders(token) })
        setMessage(`${title.slice(0, -1)} berhasil ditambahkan.`)
      }
      setForm(emptyContactForm)
      setEditingId(null)
      await queryClient.invalidateQueries({ queryKey })
    } catch (saveError) {
      setError(getErrorMessage(saveError))
    } finally {
      setBusy(false)
    }
  }

  const editContact = (contact: Contact) => {
    setEditingId(contact.id)
    setForm({ name: contact.name, email: contact.email ?? '', phone: contact.phone ?? '', address: contact.address ?? '' })
    setError('')
    setMessage('')
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const deleteContact = async (contact: Contact) => {
    if (!window.confirm(`Hapus ${title.slice(0, -1).toLowerCase()} “${contact.name}”?`)) return
    setError('')
    setMessage('')
    try {
      await axios.delete(`${API_BASE}/${resource}/${contact.id}`, { headers: getHeaders(token) })
      setMessage(`${title.slice(0, -1)} berhasil dihapus.`)
      await queryClient.invalidateQueries({ queryKey })
    } catch (deleteError) {
      setError(getErrorMessage(deleteError))
    }
  }

  return (
    <div>
      <header className="page-header"><div><div className="eyebrow">Master Data</div><h2>{title}</h2></div></header>
      <form className="panel" onSubmit={saveContact}>
        <div className="section-heading"><h3>{editingId ? `Ubah ${title.slice(0, -1).toLowerCase()}` : `Tambah ${title.slice(0, -1).toLowerCase()}`}</h3>{editingId && <button type="button" className="text-btn" onClick={() => { setEditingId(null); setForm(emptyContactForm) }}>Batal</button>}</div>
        <div className="form-grid">
          <label>Nama<input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
          <label>Email<input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></label>
          <label>Telepon<input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></label>
          <label>Alamat<input value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} /></label>
        </div>
        {error && <div className="error-box" role="alert">{error}</div>}
        {message && <div className="success-box" role="status">{message}</div>}
        <button type="submit" disabled={busy}>{busy ? 'Menyimpan...' : editingId ? 'Simpan perubahan' : `Simpan ${title.slice(0, -1).toLowerCase()}`}</button>
      </form>

      <div className="panel table-panel">
        <div className="section-heading"><h3>Daftar {title.toLowerCase()}</h3><span>{contacts.length} data</span></div>
        <div className="table-scroll">
          <table>
            <thead><tr><th>Nama</th><th>Email</th><th>Telepon</th><th>Alamat</th><th>Aksi</th></tr></thead>
            <tbody>
              {isPending && <tr><td colSpan={5} className="empty-state">Memuat data...</td></tr>}
              {isError && <tr><td colSpan={5} className="empty-state error-text">{getErrorMessage(loadError)}</td></tr>}
              {contacts.map((contact) => (
                <tr key={contact.id}>
                  <td>{contact.name}</td><td>{contact.email || '-'}</td><td>{contact.phone || '-'}</td><td>{contact.address || '-'}</td>
                  <td><div className="row-actions"><button type="button" className="text-btn" onClick={() => editContact(contact)}>Ubah</button><button type="button" className="danger-btn" onClick={() => deleteContact(contact)}>Hapus</button></div></td>
                </tr>
              ))}
              {!isPending && !isError && !contacts.length && <tr><td colSpan={5} className="empty-state">Belum ada data.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}

function SalesPage({ token }: { token: string }) {
  const queryClient = useQueryClient()
  const { data: customers = [] } = useQuery({
    queryKey: ['customers', token],
    queryFn: async () => {
      const response = await axios.get(`${API_BASE}/customers`, { headers: getHeaders(token) })
      return response.data as Customer[]
    },
  })

  const { data: products = [] } = useQuery({
    queryKey: ['sale-products', token],
    queryFn: async () => {
      const response = await axios.get(`${API_BASE}/products`, { headers: getHeaders(token) })
      return response.data as Product[]
    },
  })

  const { data: orders = [], isPending: ordersPending, isError: ordersError } = useQuery({
    queryKey: ['sales-orders', token],
    queryFn: async () => {
      const response = await axios.get(`${API_BASE}/sales-orders`, { headers: getHeaders(token) })
      return response.data as SalesOrder[]
    },
  })

  const [customerId, setCustomerId] = useState('')
  const [items, setItems] = useState([{ product_id: '', qty: 1 }])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [result, setResult] = useState<string>(() => {
    const paymentState = new URLSearchParams(window.location.search).get('payment')
    if (paymentState === 'finish') return 'Kembali dari checkout. Status terbaru tersedia pada daftar Sales Order dan Invoice.'
    if (paymentState === 'pending') return 'Pembayaran menunggu penyelesaian. Periksa status terbaru pada daftar invoice.'
    if (paymentState === 'error') return 'Pembayaran belum berhasil. Anda dapat mencoba checkout kembali.'
    return ''
  })

  const total = items.reduce((sum, item) => {
    const product = products.find((entry) => String(entry.id) === String(item.product_id))
    if (!product) return sum
    return sum + product.price * item.qty
  }, 0)

  const updateItem = (index: number, value: Partial<{ product_id: string; qty: number }>) => {
    setItems((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, ...value } : item))
  }

  const addItem = () => setItems((current) => [...current, { product_id: '', qty: 1 }])

  const submitOrder = async () => {
    setBusy(true)
    setError('')
    const payload = {
      customer_id: Number(customerId),
      items: items.map((item) => ({
        product_id: Number(item.product_id),
        qty: Number(item.qty),
      })),
    }

    try {
      const response = await axios.post(`${API_BASE}/sales-orders`, payload, { headers: getHeaders(token) })
      await queryClient.invalidateQueries({ queryKey: ['sales-orders', token] })
      const payResponse = await axios.post(`${API_BASE}/invoices/${response.data.invoice.id}/pay`, {}, { headers: getHeaders(token) })
      if (payResponse.data.snap_url) {
        window.location.assign(payResponse.data.snap_url)
        return
      }
      setResult(payResponse.data.message ?? 'Order dibuat, tetapi URL pembayaran belum tersedia.')
    } catch (submitError) {
      setError(getErrorMessage(submitError))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <header className="page-header"><div><div className="eyebrow">Sales</div><h2>Sales Order</h2></div></header>
      <div className="panel">
        <label>
          Customer
          <select value={customerId} onChange={(e) => setCustomerId(e.target.value)}>
            <option value="">Pilih customer</option>
            {customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}
          </select>
        </label>
        {items.map((item, index) => (
          <div key={index} className="order-row">
            <select value={item.product_id} onChange={(e) => updateItem(index, { product_id: e.target.value })}>
              <option value="">Pilih produk</option>
              {products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
            </select>
            <input type="number" min={1} value={item.qty} onChange={(e) => updateItem(index, { qty: Number(e.target.value) })} />
          </div>
        ))}
        <div className="stack-row">
          <button type="button" className="secondary-btn" onClick={addItem}>Tambah item</button>
          <div className="amount-box">Total: {currency.format(total)}</div>
        </div>
        {error && <div className="error-box" role="alert">{error}</div>}
        <button onClick={submitOrder} disabled={busy || !customerId || items.some((item) => !item.product_id)}>{busy ? 'Membuat order...' : 'Buat order & bayar'}</button>
        {result && <div className="success-box">{result}</div>}
      </div>
      <div className="panel table-panel">
        <div className="section-heading"><h3>Sales Order & Invoice</h3><span>{orders.length} order</span></div>
        <div className="table-scroll"><table><thead><tr><th>Order</th><th>Tanggal</th><th>Customer</th><th>Total</th><th>Invoice</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
          {ordersPending && <tr><td colSpan={7} className="empty-state">Memuat order...</td></tr>}
          {ordersError && <tr><td colSpan={7} className="empty-state error-text">Gagal memuat sales order.</td></tr>}
          {orders.map((order) => <tr key={order.id}><td>SO-{String(order.id).padStart(6, '0')}</td><td>{new Date(order.created_at).toLocaleDateString('id-ID')}</td><td>{order.customer?.name ?? '-'}</td><td>{currency.format(order.total)}</td><td>{order.invoice?.number ?? 'Belum dibuat'}</td><td><span className={`status-badge ${order.invoice?.status ?? order.status}`}>{order.invoice?.status ?? order.status}</span></td><td>{order.invoice && order.invoice.status !== 'paid' ? <button type="button" className="text-btn" onClick={async () => { try { const response = await axios.post(`${API_BASE}/invoices/${order.invoice?.id}/pay`, {}, { headers: getHeaders(token) }); if (response.data.snap_url) window.location.assign(response.data.snap_url) } catch (payError) { setError(getErrorMessage(payError)) } }}>Bayar</button> : '-'}</td></tr>)}
          {!ordersPending && !ordersError && !orders.length && <tr><td colSpan={7} className="empty-state">Belum ada sales order.</td></tr>}
        </tbody></table></div>
      </div>
    </div>
  )
}

function PurchasesPage({ token }: { token: string }) {
  const queryClient = useQueryClient()
  const { data: suppliers = [] } = useQuery({
    queryKey: ['suppliers', token],
    queryFn: async () => {
      const response = await axios.get(`${API_BASE}/suppliers`, { headers: getHeaders(token) })
      return response.data as Supplier[]
    },
  })

  const { data: products = [] } = useQuery({
    queryKey: ['purchase-products', token],
    queryFn: async () => {
      const response = await axios.get(`${API_BASE}/products`, { headers: getHeaders(token) })
      return response.data as Product[]
    },
  })

  const { data: purchaseOrders = [], isPending: ordersPending, isError: ordersError } = useQuery({
    queryKey: ['purchase-orders', token],
    queryFn: async () => {
      const response = await axios.get(`${API_BASE}/purchase-orders`, { headers: getHeaders(token) })
      return response.data as PurchaseOrder[]
    },
  })

  const [supplierId, setSupplierId] = useState('')
  const [items, setItems] = useState([{ product_id: '', qty: 1, cost: 0 }])
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const total = items.reduce((sum, item) => {
    const product = products.find((entry) => String(entry.id) === String(item.product_id))
    if (!product) return sum
    return sum + item.cost * item.qty
  }, 0)

  const addItem = () => setItems((current) => [...current, { product_id: '', qty: 1, cost: 0 }])

  const submitPo = async () => {
    setBusy(true)
    setError('')
    setMessage('')
    try {
      const response = await axios.post(`${API_BASE}/purchase-orders`, {
        supplier_id: Number(supplierId),
        items: items.map((item) => ({ product_id: Number(item.product_id), qty: Number(item.qty), cost: Number(item.cost) })),
      }, { headers: getHeaders(token) })
      const poId = response.data.purchase_order.id
      await axios.post(`${API_BASE}/purchase-orders/${poId}/submit`, {}, { headers: getHeaders(token) })
      setMessage(`PO #${poId} dikirim untuk approval. Stok belum berubah.`)
      setSupplierId('')
      setItems([{ product_id: '', qty: 1, cost: 0 }])
      await queryClient.invalidateQueries({ queryKey: ['purchase-orders', token] })
    } catch (submitError) {
      setError(getErrorMessage(submitError))
    } finally {
      setBusy(false)
    }
  }

  const advancePurchaseOrder = async (purchaseOrder: PurchaseOrder, action: 'approve' | 'receive') => {
    setError('')
    setMessage('')
    try {
      await axios.post(`${API_BASE}/purchase-orders/${purchaseOrder.id}/${action}`, {}, { headers: getHeaders(token) })
      setMessage(action === 'approve' ? `PO #${purchaseOrder.id} disetujui.` : `Barang PO #${purchaseOrder.id} diterima dan stok ditambahkan.`)
      await queryClient.invalidateQueries({ queryKey: ['purchase-orders', token] })
      await queryClient.invalidateQueries({ queryKey: ['products', token] })
      await queryClient.invalidateQueries({ queryKey: ['purchase-products', token] })
      await queryClient.invalidateQueries({ queryKey: ['summary', token] })
    } catch (actionError) {
      setError(getErrorMessage(actionError))
    }
  }

  return (
    <div>
      <header className="page-header"><div><div className="eyebrow">Purchasing</div><h2>Purchase Order</h2></div></header>
      <div className="panel">
        <label>
          Supplier
          <select value={supplierId} onChange={(e) => setSupplierId(e.target.value)}>
            <option value="">Pilih supplier</option>
            {suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}
          </select>
        </label>
        {items.map((item, index) => (
          <div key={index} className="order-row">
            <select value={item.product_id} onChange={(e) => setItems((current) => current.map((entry, itemIndex) => itemIndex === index ? { ...entry, product_id: e.target.value } : entry))}>
              <option value="">Pilih produk</option>
              {products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
            </select>
            <input type="number" min={1} value={item.qty} onChange={(e) => setItems((current) => current.map((entry, itemIndex) => itemIndex === index ? { ...entry, qty: Number(e.target.value) } : entry))} />
            <input type="number" min={0} value={item.cost} onChange={(e) => setItems((current) => current.map((entry, itemIndex) => itemIndex === index ? { ...entry, cost: Number(e.target.value) } : entry))} />
          </div>
        ))}
        <div className="stack-row">
          <button type="button" className="secondary-btn" onClick={addItem}>Tambah item</button>
          <div className="amount-box">Total: {currency.format(total)}</div>
        </div>
        {error && <div className="error-box" role="alert">{error}</div>}
        <button onClick={submitPo} disabled={busy || !supplierId || items.some((item) => !item.product_id || item.cost < 0)}>{busy ? 'Mengirim...' : 'Buat & kirim untuk approval'}</button>
        {message && <div className="success-box">{message}</div>}
      </div>
      <div className="panel table-panel">
        <div className="section-heading"><h3>Purchase Orders</h3><span>{purchaseOrders.length} PO</span></div>
        <div className="table-scroll"><table><thead><tr><th>PO</th><th>Tanggal</th><th>Supplier</th><th>Item</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
          {ordersPending && <tr><td colSpan={7} className="empty-state">Memuat purchase order...</td></tr>}
          {ordersError && <tr><td colSpan={7} className="empty-state error-text">Gagal memuat purchase order.</td></tr>}
          {purchaseOrders.map((purchaseOrder) => <tr key={purchaseOrder.id}><td>PO-{String(purchaseOrder.id).padStart(6, '0')}</td><td>{new Date(purchaseOrder.created_at).toLocaleDateString('id-ID')}</td><td>{purchaseOrder.supplier?.name ?? '-'}</td><td>{purchaseOrder.items.map((item) => `${item.product.name} x${item.qty}`).join(', ')}</td><td>{currency.format(purchaseOrder.total)}</td><td><span className={`status-badge ${purchaseOrder.status}`}>{purchaseOrder.status}</span></td><td>{purchaseOrder.status === 'submitted' ? <button type="button" className="text-btn" onClick={() => advancePurchaseOrder(purchaseOrder, 'approve')}>Approve</button> : purchaseOrder.status === 'approved' ? <button type="button" className="text-btn" onClick={() => advancePurchaseOrder(purchaseOrder, 'receive')}>Receive</button> : '-'}</td></tr>)}
          {!ordersPending && !ordersError && !purchaseOrders.length && <tr><td colSpan={7} className="empty-state">Belum ada purchase order.</td></tr>}
        </tbody></table></div>
      </div>
    </div>
  )
}

function StatCard({ title, value, tone }: { title: string; value: string; tone: 'green' | 'blue' | 'amber' | 'red' }) {
  return (
    <div className={`stat-card ${tone}`}>
      <div className="stat-title">{title}</div>
      <div className="stat-value">{value}</div>
    </div>
  )
}

export default App

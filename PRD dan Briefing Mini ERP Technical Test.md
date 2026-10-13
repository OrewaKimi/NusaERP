# PRD dan Briefing: Mini ERP Technical Test

Dokumen perencanaan sebelum eksekusi. Target pengerjaan: 1 hari (sekitar 10 jam efektif).

## 1. Ringkasan Singkat

Nama proyek (usulan): **NusaERP Lite**, sebuah mini ERP untuk UMKM distribusi/ritel yang mencakup penjualan, persediaan, dan pembelian, lengkap dengan pembayaran online (sandbox) dan otomasi proses lewat n8n.

Yang dinilai penilai: konsep aplikasi, struktur sistem, pembagian modul, dan arsitektur. Artinya, **kerapian arsitektur dan README lebih penting daripada jumlah fitur**. Modul boleh tidak penuh, tetapi alur utama harus jalan dari ujung ke ujung (end to end) dan bisa didemokan.

## 2. Pemetaan Ketentuan ke Solusi

| Ketentuan | Solusi di proyek ini |
| --- | --- |
| Minimal 3 modul | Sales, Inventory, Purchasing (+ Dashboard dan Auth sebagai modul pendukung) |
| API Connection | REST API internal (Laravel) yang dikonsumsi React, plus API eksternal: kurs mata uang (Frankfurter) dan Midtrans |
| Payment Gateway | Midtrans Snap mode sandbox, dengan webhook notifikasi. Fallback: mock payment page jika akun sandbox bermasalah |
| Automation n8n | 3 workflow: pembayaran berhasil, stok menipis (terjadwal), persetujuan Purchase Order |
| Frontend modern | React + Vite + TypeScript |
| Backend bebas, bukan Firebase | Laravel 11 (REST API) + PostgreSQL |
| GitHub dan README | Repo monorepo + README berisi instalasi, cara menjalankan, arsitektur, fitur |

## 3. Keputusan Teknologi

### Frontend

- React 18 + Vite + TypeScript
- React Router, TanStack Query (data fetching dan cache), Axios
- Tailwind CSS + komponen sederhana (shadcn/ui bila sempat; jika tidak, Tailwind murni)
- Recharts untuk grafik dashboard

### Backend: rekomendasi Laravel 11 (API-only)

Alasan utama: paling cepat untuk CRUD, validasi, auth token (Sanctum), migration, seeder, queue, dan HTTP client. Integrasi Midtrans dan n8n cukup lewat HTTP biasa. Dalam waktu 1 hari, kecepatan dan keakraban dengan tool lebih berharga daripada stack yang "lebih keren".

Alternatif yang dipertimbangkan:

| Opsi | Kelebihan | Kekurangan |
| --- | --- | --- |
| Laravel (dipilih) | Paling cepat, ekosistem lengkap, mudah dihubungkan ke n8n dan Midtrans | Perlu Docker/PHP di mesin penilai |
| NestJS | TypeScript satu bahasa dengan frontend | Boilerplate lebih banyak, lebih lambat untuk 1 hari |
| Supabase | Sangat cepat | Mirip BaaS seperti Firebase, berisiko mengurangi nilai arsitektur |

### Infrastruktur

- Database: PostgreSQL 16
- n8n: container resmi (self-hosted)
- Orkestrasi lokal: **Docker Compose** (satu perintah menjalankan semuanya)
- Opsional: tunnel (ngrok atau cloudflared) agar webhook Midtrans bisa masuk ke localhost saat demo

## 4. Arsitektur Sistem

```
[ React SPA ] --REST/JSON--> [ Laravel API ] --SQL--> [ PostgreSQL ]
                                  |   ^
          create transaction      |   | webhook notification
                                  v   |
                            [ Midtrans Sandbox ]

[ Laravel API ] --HTTP webhook (event)--> [ n8n ] --HTTP callback + token--> [ Laravel API ]
                                            |
                                            +--> Email / Telegram / Google Sheets
                                            +--> API eksternal (kurs, dll.)
```

Prinsip arsitektur (poin yang sebaiknya ditulis di README):

1. **Separation of concerns**: frontend, backend, dan automation dipisah, berkomunikasi lewat kontrak HTTP.
2. **Layering di backend**: Controller → Service → Model; logika bisnis (mis. pengurangan stok) ada di Service, bukan di Controller.
3. **Event-driven ringan**: Laravel memancarkan event (OrderPaid, StockLow, PurchaseOrderCreated), listener meneruskannya ke n8n.
4. **Idempotency webhook**: notifikasi Midtrans yang datang dua kali tidak boleh memproses pesanan dua kali.
5. **Keamanan dasar**: token Sanctum, role-based access (admin, sales, gudang), verifikasi signature Midtrans, shared secret untuk callback n8n.

## 5. Pembagian Modul dan Cakupan

Prioritas memakai MoSCoW. Targetnya semua "Must" selesai; "Should" dikerjakan jika waktu ada.

### Modul 0: Auth dan User (pendukung)

- Must: login, logout, role (admin, sales, gudang), seeder akun demo
- Won't: registrasi publik, reset password

### Modul 1: Sales dan Payment (modul utama, dibuat paling lengkap)

- Must: CRUD customer; buat Sales Order (pilih produk, qty, otomatis hitung total); generate invoice; tombol "Bayar" membuka Midtrans Snap; status invoice berubah lewat webhook (unpaid, paid, expired)
- Should: tampilkan total dalam mata uang lain (API kurs); cetak invoice (halaman print)
- Could: refund

### Modul 2: Inventory

- Must: CRUD produk (SKU, harga, stok, minimum stok); riwayat pergerakan stok (stock movement); stok berkurang otomatis saat invoice paid
- Should: penyesuaian stok manual
- Could: multi-gudang

### Modul 3: Purchasing

- Must: CRUD supplier; buat Purchase Order (draft, submitted, approved, received); saat "received" stok bertambah
- Should: approval PO lewat n8n (tombol approve di notifikasi)
- Could: dokumen PO PDF

### Modul 4: Dashboard

- Must: kartu ringkasan (omzet, jumlah order, invoice belum dibayar, produk stok menipis)
- Should: grafik penjualan 7 hari

## 6. Skenario Otomasi n8n

Semua workflow diekspor sebagai JSON dan disimpan di folder `n8n/workflows/` agar penilai bisa mengimpornya.

| Workflow | Pemicu | Alur | Hasil |
| --- | --- | --- | --- |
| WF1: Payment Success | Webhook dari Laravel saat invoice paid | Webhook → format pesan → kirim email/Telegram ke customer dan admin → catat baris ke Google Sheets (opsional) | Notifikasi pembayaran dan pencatatan otomatis |
| WF2: Low Stock Alert | Cron harian (atau setiap beberapa menit untuk demo) | Cron → HTTP GET `/api/automation/low-stock` ke Laravel → IF ada item → kirim ringkasan ke Telegram/email | Peringatan stok menipis |
| WF3: PO Approval | Webhook dari Laravel saat PO submitted | Webhook → kirim pesan ke approver dengan link approve → HTTP POST ke `/api/automation/po/{id}/approve` | Persetujuan PO terotomasi |

Catatan: bila kanal Telegram/email repot dikonfigurasi, gunakan node yang paling mudah (mis. Telegram Bot, atau cukup node Webhook Response dan log) agar tetap ada bukti workflow berjalan.

## 7. Desain Data (ERD Ringkas)

Tabel inti:

- `users` (id, name, email, password, role)
- `customers` (id, name, email, phone, address)
- `products` (id, sku, name, price, stock, min\_stock)
- `stock_movements` (id, product\_id, type \[in/out/adjust\], qty, ref\_type, ref\_id, note)
- `sales_orders` (id, customer\_id, status, total, created\_by)
- `sales_order_items` (id, sales\_order\_id, product\_id, qty, price, subtotal)
- `invoices` (id, sales\_order\_id, number, amount, status, due\_date)
- `payments` (id, invoice\_id, gateway, order\_id, snap\_token, status, raw\_payload, paid\_at)
- `suppliers` (id, name, email, phone)
- `purchase_orders` (id, supplier\_id, status, total, approved\_at)
- `purchase_order_items` (id, purchase\_order\_id, product\_id, qty, cost)
- `automation_logs` (id, event, target, status, payload, created\_at)

Relasi inti: customer 1-N sales\_order 1-1 invoice 1-N payment; sales\_order 1-N items N-1 product; purchase\_order 1-N items N-1 product; product 1-N stock\_movements.

## 8. Rancangan Endpoint API (ringkas)

| Area | Endpoint |
| --- | --- |
| Auth | `POST /api/login`, `POST /api/logout`, `GET /api/me` |
| Customers | `GET/POST /api/customers`, `GET/PUT/DELETE /api/customers/{id}` |
| Products | `GET/POST /api/products`, `GET/PUT/DELETE /api/products/{id}`, `GET /api/products/{id}/movements` |
| Sales | `GET/POST /api/sales-orders`, `GET /api/sales-orders/{id}`, `POST /api/sales-orders/{id}/invoice` |
| Payment | `POST /api/invoices/{id}/pay` (mengembalikan snap token), `POST /api/payments/midtrans/notification` (webhook publik) |
| Purchasing | `GET/POST /api/suppliers`, `GET/POST /api/purchase-orders`, `POST /api/purchase-orders/{id}/submit`, `POST /api/purchase-orders/{id}/receive` |
| Dashboard | `GET /api/dashboard/summary`, `GET /api/dashboard/sales-chart` |
| Eksternal | `GET /api/external/exchange-rate?to=USD` (proxy ke Frankfurter) |
| Automation (diproteksi shared secret) | `GET /api/automation/low-stock`, `POST /api/automation/po/{id}/approve` |

## 9. Alur Utama untuk Demo (Happy Path)

1. Login sebagai admin.
2. Tambah produk (stok 10, minimum 5).
3. Buat Sales Order 6 unit untuk satu customer, buat invoice.
4. Klik Bayar, selesaikan pembayaran di Midtrans Snap sandbox.
5. Webhook masuk, invoice menjadi paid, stok turun menjadi 4, stock movement tercatat.
6. n8n WF1 mengirim notifikasi pembayaran; WF2 mendeteksi stok di bawah minimum dan mengirim peringatan.
7. Buat PO ke supplier, submit, approve lewat n8n, receive, stok naik.
8. Dashboard menampilkan angka terbaru.

Alur ini membuktikan ketiga syarat wajib (API, payment, n8n) sekaligus dalam satu cerita.

## 10. Rencana Waktu 1 Hari

| Jam | Aktivitas | Keluaran |
| --- | --- | --- |
| 0:00 - 0:45 | Setup repo, Docker Compose (Laravel, Postgres, n8n), scaffold React | `docker compose up` jalan, halaman kosong tampil |
| 0:45 - 2:15 | Migration, model, seeder; auth Sanctum + role | Login berfungsi, data demo ada |
| 2:15 - 3:30 | Modul Inventory (API + UI) | CRUD produk dan riwayat stok |
| 3:30 - 5:30 | Modul Sales + invoice + integrasi Midtrans + webhook | Pembayaran sandbox end to end |
| 5:30 - 6:30 | Modul Purchasing (versi ringkas) | PO dan receive stok |
| 6:30 - 8:00 | Workflow n8n (3 workflow) + endpoint automation | Workflow ter-ekspor dan teruji |
| 8:00 - 8:45 | Dashboard + API kurs eksternal | Ringkasan dan grafik |
| 8:45 - 10:00 | README, diagram arsitektur, rapikan, rekaman/screenshot demo, push | Repo siap dinilai |

Aturan darurat: jika terlambat lebih dari 1 jam pada tahap mana pun, potong item "Should/Could", jangan potong README dan alur Payment + n8n.

## 11. Struktur Repository (Monorepo)

```
nusaerp-lite/
├── backend/            # Laravel API
│   ├── app/Http/Controllers/
│   ├── app/Services/   # SalesService, StockService, PaymentService, AutomationService
│   ├── app/Events/ + Listeners/
│   └── database/migrations, seeders
├── frontend/           # React + Vite + TS
│   └── src/{pages,components,api,hooks,routes}
├── n8n/workflows/      # JSON export workflow
├── docs/               # diagram arsitektur, ERD, screenshot
├── docker-compose.yml
├── .env.example        # semua variabel, tanpa rahasia asli
└── README.md
```

## 12. Outline README (wajib dipenuhi)

1. Deskripsi singkat dan tujuan aplikasi
2. Daftar fitur per modul (tandai yang penuh dan yang parsial)
3. Diagram arsitektur dan penjelasan singkat tiap komponen
4. Tech stack dan alasan pemilihan
5. Prasyarat (Docker, Node, dsb.)
6. Instalasi dan cara menjalankan (salin `.env.example`, `docker compose up`, migrate dan seed)
7. Akun demo (admin, sales, gudang)
8. Konfigurasi Midtrans sandbox (cara mengisi key, cara menguji, kartu uji) dan fallback mock
9. Cara impor workflow n8n dan konfigurasi kredensial
10. Daftar endpoint API ringkas
11. Keterbatasan yang diketahui dan rencana pengembangan

## 13. Risiko dan Mitigasi

| Risiko | Mitigasi |
| --- | --- |
| Webhook Midtrans tidak bisa menjangkau localhost | Gunakan tunnel (ngrok/cloudflared); sediakan endpoint simulasi pembayaran sebagai fallback |
| Akun sandbox Midtrans lambat disetujui | Daftar di awal hari; siapkan mock payment (halaman pilih Sukses/Gagal yang memanggil endpoint notifikasi yang sama) |
| n8n sulit dijangkau dari container lain | Satu jaringan Docker Compose; gunakan nama service (mis. `http://n8n:5678`) |
| Terlalu banyak fitur | Patuhi MoSCoW; kunci fitur setelah jam ke-8 |
| Penilai kesulitan menjalankan | Satu perintah Docker Compose, seeder otomatis, README diuji dari clone bersih |
| Rahasia bocor di repo | Hanya `.env.example`; cek riwayat commit sebelum push |

## 14. Kriteria Selesai (Definition of Done)

- Tiga modul utama dapat didemokan dan terhubung (penjualan mengurangi stok, pembelian menambah stok)
- Pembayaran sandbox (atau mock) mengubah status invoice lewat webhook
- Minimal 2 workflow n8n berjalan nyata, 3 diekspor di repo
- Satu panggilan API eksternal berfungsi
- `docker compose up` berhasil dari clone bersih
- README lengkap sesuai outline, ada diagram arsitektur
- Repo publik di GitHub/GitLab dengan commit yang rapi dan bertahap

## 15. Briefing Eksekusi (Catatan Cepat)

- Mulai dari fondasi (Docker, auth, data), lalu kerjakan alur yang paling berisiko lebih dulu: **Payment + webhook**, baru modul lain.
- Commit kecil dan sering dengan pesan jelas; ini terbaca sebagai disiplin engineering.
- Gunakan data seeder yang realistis agar demo langsung terlihat hidup.
- Siapkan 3 sampai 5 menit narasi demo: masalah, arsitektur, alur utama, lalu apa yang akan dikembangkan berikutnya.
- Tuliskan secara jujur bagian yang belum berfungsi penuh di README; ketentuan memperbolehkan hal ini, dan kejujuran dengan arsitektur yang jelas dinilai lebih baik daripada fitur setengah jadi yang disembunyikan.

## 16. Pertanyaan Terbuka (Tentukan Sebelum Mulai)

1. Apakah Docker tersedia di mesin Anda dan akan tersedia di mesin penilai? (Jika tidak, siapkan panduan instalasi manual.)
2. Kanal notifikasi n8n yang dipilih: Telegram, email, atau Google Sheets?
3. Apakah akan menyiapkan demo online (deploy) selain repo? Disarankan hanya jika waktu tersisa.

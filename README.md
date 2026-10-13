# NusaERP Lite

Mini ERP untuk penjualan, persediaan, dan purchasing. Backend Laravel menyediakan REST API dan business rules; React mengonsumsi API tersebut; PostgreSQL menyimpan data operasional; n8n menerima event dan menjalankan pemeriksaan low-stock terjadwal.

## Status Fitur

| Area | Status | Catatan |
| --- | --- | --- |
| Auth | Terintegrasi | Sanctum login/logout, akun demo, role admin/sales/gudang. Akses API dibatasi role. |
| Master data | Terintegrasi | CRUD produk, customer, supplier; penghapusan ditolak bila data memiliki histori. |
| Sales | Terintegrasi sebagian | Buat order, invoice, Snap checkout, daftar order/status invoice, bayar ulang. Belum ada edit/hapus order atau cetak invoice. |
| Midtrans | Terintegrasi sebagian | Membuat transaksi Sandbox dan memvalidasi signature/amount callback. Settlement publik memerlukan tunnel HTTPS dan belum dites dengan callback nyata. |
| Inventory | Terintegrasi | CRUD produk, movement history, adjustment masuk/keluar yang diaudit dengan proteksi stok negatif. Belum mendukung multi-gudang. |
| Purchasing | Terintegrasi sebagian | Buat/submit, daftar status, approve, receive; transisi status tervalidasi dan stok masuk idempoten. Belum ada edit/hapus draft atau PDF. |
| Dashboard | Terintegrasi sebagian | KPI, chart tujuh hari kalender dengan nilai nol, dan kurs IDR/USD dari Frankfurter. |
| n8n | Terintegrasi sebagian | Laravel mengirim event pembayaran/PO; low-stock workflow polling tiap 5 menit. Workflow belum menjalankan kanal email/Telegram/Sheets dan belum diverifikasi di runtime n8n. |
| Docker Compose | Belum diverifikasi | Definisi service tersedia, tetapi Compose belum dijalankan karena Docker daemon tidak tersedia saat pengembangan. |

## Arsitektur

```text
React SPA --REST/Sanctum--> Laravel API --SQL--> PostgreSQL
                                  |  ^
                                  |  +-- Midtrans notification (signature-verified)
                                  +----> n8n webhooks / scheduled API polling
                                  +----> Frankfurter exchange-rate API
```

## Stack

- Frontend: React 19, Vite, TypeScript, TanStack Query, Axios, Recharts
- Backend: Laravel 13, Sanctum, PHP 8.4
- Database: PostgreSQL 16 (Compose); PostgreSQL lokal juga didukung
- Automation: n8n workflow exports

## Prasyarat

- Docker Desktop/Engine untuk menjalankan semua service melalui Compose
- Node.js dan npm untuk frontend lokal
- PHP 8.4+, Composer, PostgreSQL, dan ekstensi `pdo_pgsql` untuk backend lokal

## Menjalankan dengan Docker Compose

1. Salin root environment example dan isi key sandbox bila ingin menguji checkout:
   ```bash
   cp .env.example .env
   ```
2. Jalankan service:
   ```bash
   docker compose up --build
   ```
3. Buka frontend `http://localhost:5173`, backend `http://localhost:8000`, dan n8n `http://localhost:5678`.
4. Import workflow JSON dari `n8n/workflows` melalui editor n8n. Aktifkan workflow WF1/WF3 untuk menerima event dan WF2 untuk polling terjadwal. Set `NUSAERP_AUTOMATION_TOKEN` pada service n8n agar sama dengan `AUTOMATION_TOKEN` backend.

Compose menjalankan migrasi dan seeder saat backend dimulai. Data PostgreSQL disimpan di named volume `postgres_data`.

## Menjalankan backend lokal

1. Buat database PostgreSQL bernama `nusaerp` dan siapkan role yang dapat mengaksesnya.
2. Siapkan backend:
   ```bash
   cd backend
   cp .env.example .env
   composer install
   ```
3. Sesuaikan `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD` pada `backend/.env`.
4. Jalankan migrasi, demo data, dan API:
   ```bash
   php artisan migrate --seed
   php artisan serve --host=127.0.0.1 --port=8000
   ```
5. Di terminal lain, jalankan frontend:
   ```bash
   cd frontend
   npm install
   npm run dev -- --host 127.0.0.1 --port 5173
   ```

## Akun Demo dan Role

- `admin@nusaerp.test` / `password`: seluruh modul dan approval PO
- `sales@nusaerp.test` / `password`: customer dan sales
- `gudang@nusaerp.test` / `password`: inventory, supplier, dan purchasing

## Midtrans Sandbox

Isi `MIDTRANS_SERVER_KEY` dan `MIDTRANS_CLIENT_KEY` pada environment backend (Compose memakai root `.env`; lokal memakai `backend/.env`). Jangan commit file `.env` atau key asli. Endpoint `POST /api/invoices/{id}/pay` membuat Snap checkout.

Untuk callback, set Notification URL dashboard Midtrans ke `https://<public-host>/api/payments/midtrans/notification`, lalu arahkan tunnel HTTPS ke backend port 8000. Callback settlement harus melewati signature dan amount validation sebelum invoice paid dan stok berkurang. Return URL checkout kembali ke halaman Sales; redirect kembali bukan bukti pembayaran.

## Workflow n8n

- `wf1-payment-success.json`: menerima event settlement Laravel, memvalidasi shared token, mencatat event pada execution n8n, lalu memberi acknowledgment.
- `wf2-low-stock-alert.json`: polling endpoint low-stock setiap lima menit dan menyusun ringkasan item stok rendah.
- `wf3-po-approval.json`: menerima event PO submitted dengan shared token dan menyusun informasi permintaan approval. Approval tetap dilakukan user berwenang melalui endpoint ERP.

Email, Telegram, dan Google Sheets belum dikonfigurasi. Tambahkan node dan credentials kanal tersebut sebelum menganggap notifikasi eksternal aktif. Workflow diekspor dalam repository, tetapi perlu diimpor/diaktifkan di instance n8n.

## Endpoint Utama

- Auth: `POST /api/login`, `POST /api/logout`, `GET /api/me`
- Master data: `/api/customers`, `/api/suppliers`, `/api/products`
- Movement: `GET /api/products/{id}/movements`, `POST /api/products/{id}/adjust-stock`
- Sales/payment: `/api/sales-orders`, `POST /api/invoices/{id}/pay`, `POST /api/payments/midtrans/notification`
- Purchasing: `/api/purchase-orders`, `POST /api/purchase-orders/{id}/submit|approve|receive`
- Dashboard/external: `/api/dashboard/summary`, `/api/dashboard/sales-chart`, `/api/external/exchange-rate?to=USD`
- Automation: `GET /api/automation/low-stock`, `POST /api/automation/po/{id}/approve` (header `X-Automation-Token`)

## Validasi Terakhir

- Backend test suite: 21 test, 71 assertion lulus.
- Frontend production build berhasil; Vite masih memberi warning ukuran chunk di atas 500 kB.
- Compose syntax dan JSON/graph ketiga workflow lolos pemeriksaan statis.
- Runtime `docker compose up` dan import/aktivasi n8n belum diuji karena Docker daemon/socket tidak tersedia pada mesin pengembangan ini.

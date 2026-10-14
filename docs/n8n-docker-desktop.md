# Menjalankan n8n dari Docker Desktop (macOS)

Panduan ini untuk kondisi image `n8nio/n8n:latest` sudah di-pull dan Anda sedang berada pada dialog **Run a new container** Docker Desktop. Cara ini menjalankan n8n sebagai container mandiri, bukan service di Compose.

## 1. Isi Optional settings pada dialog Run

Gunakan nilai berikut:

| Field | Nilai |
| --- | --- |
| Container name | `nusaerp-n8n` |
| Host port | `5678` (container port yang ditampilkan harus `5678/tcp`) |
| Host path / Container path (Volumes) | `/Users/mac/Downloads/NusaERPLite/n8n/data` → `/home/node/.n8n` |
| Variable | Isi setiap pasangan environment variable di bawah sebagai baris terpisah |

Environment variables yang perlu ditambahkan satu per satu memakai tombol `+`:

| Variable | Value |
| --- | --- |
| `N8N_HOST` | `0.0.0.0` |
| `N8N_PORT` | `5678` |
| `N8N_PROTOCOL` | `http` |
| `N8N_EDITOR_BASE_URL` | `http://localhost:5678/` |
| `N8N_WEBHOOK_URL` | `http://localhost:5678/` |
| `N8N_BASIC_AUTH_ACTIVE` | `false` |
| `N8N_BLOCK_ENV_ACCESS_IN_NODE` | `false` |
| `NUSAERP_AUTOMATION_TOKEN` | Harus sama persis dengan `AUTOMATION_TOKEN` pada `backend/.env` (setup demo lokal saat ini: `nusaerp-secret`) |
| `FRONTEND_URL` | `http://localhost:5173` |
| `N8N_SECURE_COOKIE` | `false` (hanya untuk editor lokal melalui HTTP) |

Mount folder data lokal agar database n8n dan encryption key yang dibuat otomatis tetap ada saat container diganti. Jangan mount folder workflow repository ke direktori data n8n. Jangan hapus folder `n8n/data` setelah n8n mulai menyimpan credentials.

Klik **Run**. Bila Docker Desktop melaporkan host port `5678` sudah digunakan, hentikan container n8n lama atau pilih host port lain (misalnya `5679`) dan sesuaikan semua URL di panduan/config setelah itu. Container port tetap `5678`.

## 2. Buka n8n dan selesaikan onboarding

Buka:

```text
http://localhost:5678
```

Pada instalasi baru, n8n dapat meminta pembuatan owner account. Buat akun lokal untuk editor. Jangan gunakan password yang sama dengan akun ERP. Ini mengunci editor n8n; webhook URLs tetap berjalan di port yang dipublish.

Pastikan container sehat dari terminal:

```bash
curl -i http://127.0.0.1:5678/healthz
```

Respons `200` menandakan n8n menerima koneksi. Bila gagal, periksa Docker Desktop > Containers > `nusaerp-n8n` > Logs, port mapping `5678:5678`, dan pastikan host port belum dipakai proses lain.

## 3. Pastikan backend Laravel aktif

Workflow mandiri mengakses backend host menggunakan hostname khusus Docker Desktop macOS, bukan `localhost` atau nama service Compose.

Jalankan PostgreSQL lokal, lalu backend:

```bash
cd /Users/mac/Downloads/NusaERPLite/backend
php artisan config:clear
php artisan serve --host=0.0.0.0 --port=8000
```

Periksa dari Mac:

```bash
curl -i http://127.0.0.1:8000/up
```

Harus HTTP `200`. `--host=0.0.0.0` membuat Laravel listen pada interface yang dapat diteruskan Docker Desktop. Workflow WF2 sudah memakai:

```text
http://host.docker.internal:8000/api/automation/low-stock
```

Itu khusus akses dari container macOS menuju host. Untuk Docker Compose, gunakan hostname service `backend` pada workflow/config Compose.

## 4. Periksa konfigurasi backend

Pastikan `backend/.env` mengandung konfigurasi berikut dan restart Laravel setelah ada perubahan:

```dotenv
AUTOMATION_TOKEN=nusaerp-secret
N8N_PAYMENT_SUCCESS_WEBHOOK=http://127.0.0.1:5678/webhook/payment-success
N8N_PO_SUBMITTED_WEBHOOK=http://127.0.0.1:5678/webhook/po-submitted
```

Token pada container `NUSAERP_AUTOMATION_TOKEN` harus sama persis dengan `AUTOMATION_TOKEN` backend. Jangan gunakan atau bagikan nilai rahasia pada screenshot/chat.

## 5. Import tiga workflow

Pada setup container lokal NusaERP saat ini, ketiga workflow sudah diimpor ke database n8n sebelum owner account dibuat. Setelah menyelesaikan onboarding, periksa daftar workflow untuk menemukan `WF1 Payment Success`, `WF2 Low Stock Alert`, dan `WF3 PO Approval`. Jangan import ulang bila ketiganya sudah terlihat, agar tidak membuat duplikat.

Jika workflow belum terlihat, ulangi langkah berikut untuk setiap file:

1. Di n8n, pilih **Overview** atau **Workflows** > **Import from File**.
2. Pilih file di folder project `n8n/workflows/`:
   - `wf1-payment-success.json`
   - `wf2-low-stock-alert.json`
   - `wf3-po-approval.json`
3. Simpan workflow.
4. Aktifkan workflow (toggle **Active/Publish**, label bisa berbeda menurut versi n8n).

Import dari CLI sengaja menonaktifkan workflow. Buka setiap workflow setelah onboarding, cek node-node tidak berstatus error, lalu aktifkan WF1, WF2, dan WF3 satu per satu.

Webhook production URL yang diharapkan:

```text
http://localhost:5678/webhook/payment-success
http://localhost:5678/webhook/po-submitted
```

Karena backend berjalan di Mac, `127.0.0.1:5678` dari proses Laravel adalah port host yang benar. Jangan menggantinya dengan `host.docker.internal` pada URL yang dipanggil Laravel di host.

> Jika n8n menolak akses `$env` di expressions pada versi yang Anda jalankan, periksa container log dan policy environment access. Panduan ini menetapkan `N8N_BLOCK_ENV_ACCESS_IN_NODE=false` dan `NUSAERP_AUTOMATION_TOKEN` sebelum container dibuat. Setelah mengubah environment container, recreate/re-run container agar setting diterapkan, kemudian import ulang workflow bila perlu.

## 6. Test koneksi container ke backend (WF2)

Cek backend automation endpoint dari Mac dahulu:

```bash
curl -i -H 'X-Automation-Token: nusaerp-secret' http://127.0.0.1:8000/api/automation/low-stock
```

HTTP `200` dan JSON berisi `count`/`items` berarti token backend benar.

Di n8n:

1. Buka WF2 Low Stock Alert.
2. Klik **Execute Workflow** untuk menjalankan trigger/polling secara manual, atau pilih node **Get Low Stock** lalu execute node.
3. Periksa output node `Get Low Stock`; output semestinya mempunyai `count` dan `items`.
4. Untuk pengujian terjadwal, aktifkan workflow; schedule berjalan tiap lima menit. Cabang ringkasan menghasilkan item hanya bila `count > 0`.

WF2 saat ini mencatat/menyusun ringkasan di execution output. Email/Telegram belum terpasang karena belum ada credential kanal.

## 7. Test WF1 dan WF3 lewat webhook production

Pastikan workflow sudah **Active** terlebih dahulu. Gunakan request uji dari terminal Mac. Token di bawah adalah nilai demo lokal; jika Anda mengubah token, gunakan nilai yang sama dengan kedua environment.

WF1:

```bash
curl -i -X POST http://127.0.0.1:5678/webhook/payment-success \
  -H 'Content-Type: application/json' \
  -H 'X-Automation-Token: nusaerp-secret' \
  -d '{"invoice_id":999,"invoice_number":"TEST-999","order_id":"NUSA-N8N-TEST","amount":1000,"customer":"Test Customer"}'
```

WF3:

```bash
curl -i -X POST http://127.0.0.1:5678/webhook/po-submitted \
  -H 'Content-Type: application/json' \
  -H 'X-Automation-Token: nusaerp-secret' \
  -d '{"purchase_order_id":999,"supplier":"Test Supplier","total":1000}'
```

Keduanya seharusnya membalas HTTP `200`. Buka tab **Executions** di workflow dan pastikan node-node selesai hijau. Tanpa/mismatch token, workflow harus menolak request. Request fixture ini hanya menguji n8n; tidak mengubah database ERP.

## 8. Test event nyata dari ERP

- **PO:** di halaman Purchasing, buat PO lalu kirim untuk approval. Laravel mengubah status menjadi `submitted` lalu memanggil WF3. Event ini tidak otomatis approve; admin yang berwenang melakukan approval di ERP, kemudian gudang/admin dapat receive.
- **Payment:** selesaikan transaksi Snap Sandbox yang belum dibayar. Midtrans POST notification ke URL Cloudflare HTTPS yang aktif; setelah Laravel memverifikasi signature dan amount, Laravel memanggil WF1 pada `localhost:5678`.
- Periksa tab Executions di n8n. `automation_logs` backend menyimpan status dispatch; jika n8n mati, event log berstatus failed walau perubahan bisnis yang sudah valid tetap tersimpan.

Cloudflare Tunnel hanya diperlukan untuk callback **Midtrans menuju Laravel**. Laravel dan n8n berjalan di komputer yang sama sehingga event Laravel menuju n8n memakai `127.0.0.1:5678`, tidak melalui URL Cloudflare.

## 9. Troubleshooting cepat

| Gejala | Tindakan |
| --- | --- |
| `localhost:5678` tidak terbuka | Pastikan Run sukses dan mapping host `5678` ke container `5678/tcp`; cek port conflict dan Docker logs. |
| WF2 `ECONNREFUSED` | Jalankan backend dengan `--host=0.0.0.0`; URL WF2 harus `host.docker.internal:8000`, bukan `localhost:8000`. |
| HTTP `401 Unauthorized` | Samakan header `X-Automation-Token`, backend `AUTOMATION_TOKEN`, dan n8n `NUSAERP_AUTOMATION_TOKEN`. |
| Webhook production `404` | Workflow belum Active/Published atau path URL berbeda. Test URL hanya berfungsi ketika node sedang listen; gunakan production URL setelah active. |
| WF1/WF3 dipanggil tapi tidak ada execution | Pastikan backend `.env` webhook URLs mengarah ke `http://127.0.0.1:5678/webhook/...`, lalu restart Laravel dan aktifkan workflow. |
| Docker Desktop tidak bisa publish port | Ganti host port, mis. `5679:5678`, lalu update backend webhook URLs menjadi port `5679`. |
| Workflow imported tetapi `$env` ditolak | Recreate container dengan `N8N_BLOCK_ENV_ACCESS_IN_NODE=false` dan token environment diset sebelum start. |

## Keamanan

Jangan publish credential Midtrans, n8n encryption key, atau automation token. Jangan expose port n8n editor ke internet melalui Cloudflare Tunnel. Quick webhook test hanya cocok untuk lokal; gunakan HTTPS publik hanya untuk callback Midtrans ke backend. Buat container ber-volume persisten sebelum menyimpan credential/workflow yang tidak ingin hilang saat container dihapus.

# Menjalankan Semua Service dalam Satu Container

Mode ini menggabungkan PostgreSQL, Laravel API, React, n8n, Nginx, dan Cloudflare Tunnel dalam satu container. Gunakan file Compose terpisah ini bila memang membutuhkan satu container; `docker-compose.yml` multi-service tetap tersedia untuk penggunaan biasa.

## Persiapan

Salin contoh environment lalu isi password/token sendiri:

```bash
cp .env.single.example .env.single
```

Atur setidaknya `POSTGRES_PASSWORD`, `AUTOMATION_TOKEN`, dan `N8N_ENCRYPTION_KEY`. Jangan commit `.env.single`. Untuk Midtrans isi pasangan Sandbox `MIDTRANS_SERVER_KEY` dan `MIDTRANS_CLIENT_KEY`; endpoint default sudah mengarah ke Sandbox.

Buat Named Tunnel di Cloudflare Zero Trust dan salin tunnel token langsung ke `.env.single` pada `CLOUDFLARE_TUNNEL_TOKEN`. Di bagian Public Hostnames, tambahkan:

| Hostname | Service |
| --- | --- |
| `erp.example.com` | `http://localhost:8080` |
| `n8n.example.com` | `http://localhost:5678` |

Gunakan hostname ERP sebagai `APP_URL` dan `FRONTEND_URL`, hostname n8n sebagai `N8N_PUBLIC_URL`, lalu set `N8N_PROTOCOL=https` dan `N8N_SECURE_COOKIE=true`. Untuk Midtrans Sandbox, isi Payment Notification URL dengan `https://erp.example.com/api/payments/midtrans/notification`.

## Menjalankan

```bash
docker compose -f docker-compose.single.yml --env-file .env.single up --build -d
```

Buka ERP pada `http://localhost:8080` dan n8n pada `http://localhost:5678`. Named volume `nusaerp_all_data` menyimpan database PostgreSQL, database/config n8n, dan Laravel APP_KEY. Jangan hapus volume tersebut saat memperbarui image.

`N8N_PORT` adalah port internal n8n (5678). Jika port host 5678 sudah digunakan, ubah `N8N_HOST_PORT` ke port kosong, misalnya 15681, dan set `N8N_PUBLIC_URL=http://localhost:15681/`; alamat akses lokal menjadi `http://localhost:15681`.

Jika `CLOUDFLARE_TUNNEL_TOKEN` kosong, ERP dan n8n tetap tersedia melalui localhost, tetapi proses tunnel tidak tersambung. Container menjalankan migrasi saat startup dan demo seeder hanya pada inisialisasi volume pertama. Workflow n8n JSON tersedia di `/opt/nusaerp/workflows` dalam container; impor dan aktifkan workflow dari editor n8n setelah first boot. Healthcheck memeriksa endpoint Laravel dan n8n.

Periksa status dan log:

```bash
docker compose -f docker-compose.single.yml --env-file .env.single ps
docker compose -f docker-compose.single.yml --env-file .env.single logs -f nusaerp
```

Untuk menghentikan tanpa menghapus data:

```bash
docker compose -f docker-compose.single.yml --env-file .env.single down
```

Semua service berbagi siklus hidup dan batas resource satu container. Untuk deployment produksi yang memerlukan isolasi proses, scaling, atau pemulihan independen, gunakan Compose multi-service.
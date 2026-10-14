# Cloudflare Tunnel untuk Midtrans Sandbox

Panduan ini menerbitkan Laravel API lokal melalui HTTPS agar notification webhook Midtrans dapat masuk ke komputer development. Tunnel meneruskan request ke `http://127.0.0.1:8000`; Midtrans tidak perlu mengakses port lokal secara langsung dan router tidak perlu membuka port inbound.

## Pilih Jenis Tunnel

- **Quick Tunnel**: tidak perlu domain atau login Cloudflare. Cocok untuk uji singkat. URL `trycloudflare.com` acak dan berubah setiap kali tunnel dimulai ulang.
- **Named Tunnel**: URL hostname tetap pada domain yang dikelola Cloudflare. Cocok untuk demo berulang dan integrasi yang perlu URL stabil. Perlu akun Cloudflare dan domain yang sudah aktif di Cloudflare DNS.

Untuk callback Midtrans, URL yang akan didaftarkan adalah:

```text
https://<hostname-publik>/api/payments/midtrans/notification
```

Endpoint ini memang publik agar server Midtrans dapat mengirim callback. Ia memverifikasi `signature_key`, status transaksi, dan jumlah pembayaran sebelum mengubah invoice atau stok. Jangan menaruh server key di URL.

Server key, client key, dan Snap URL harus berasal dari environment yang sama. Sandbox memakai prefix `SB-Mid-server-` / `SB-Mid-client-` dan host `app.sandbox.midtrans.com`; Production memakai prefix `Mid-server-` / `Mid-client-` dan host `app.midtrans.com`. Backend menolak checkout maupun callback bila konfigurasi silang atau key tidak dikenal. Jangan gunakan key Production untuk uji Sandbox. Jika key pernah dikirim melalui chat atau kanal publik, rotasi key tersebut di dashboard Midtrans.

## A. Quick Tunnel untuk Uji Cepat

### 1. Pastikan prasyarat hidup

Terminal backend:

```bash
cd /Users/mac/Downloads/NusaERPLite/backend
php artisan serve --host=127.0.0.1 --port=8000
```

Pastikan API sehat dari terminal lain:

```bash
curl -i http://127.0.0.1:8000/up
```

Respons yang diharapkan adalah HTTP `200`.

### 2. Jalankan tunnel

Di terminal baru, biarkan perintah ini berjalan:

```bash
cloudflared tunnel --url http://127.0.0.1:8000
```

Tunggu baris URL publik yang bentuknya seperti:

```text
https://random-words.trycloudflare.com
```

Jangan tutup terminal tunnel selama proses pengujian. Quick Tunnel hanya hidup selama proses `cloudflared` aktif.

### 3. Uji URL publik

Ganti hostname dengan URL yang dicetak `cloudflared`:

```bash
curl -i https://random-words.trycloudflare.com/up
```

Pastikan respons HTTP `200`. Jangan gunakan endpoint `/api/payments/midtrans/notification` untuk browser check GET; endpoint notifikasi hanya menerima POST.

### 4. Daftarkan callback Midtrans

Pada dashboard Midtrans, pilih environment **Sandbox**, buka pengaturan konfigurasi notifikasi/payment notification, lalu isi **Payment Notification URL** dengan:

```text
https://random-words.trycloudflare.com/api/payments/midtrans/notification
```

Nama menu bisa berbeda sedikit berdasarkan tampilan dashboard. Simpan perubahan. URL harus memakai HTTPS, hostname yang sama persis, path yang benar, dan tidak mempunyai trailing path tambahan.

### 5. Uji transaksi Sandbox end-to-end

1. Pastikan backend `.env` berisi kredensial **Sandbox** yang sesuai:
   ```dotenv
   MIDTRANS_SERVER_KEY=<sandbox-server-key>
   MIDTRANS_CLIENT_KEY=<sandbox-client-key>
   MIDTRANS_SNAP_URL=https://app.sandbox.midtrans.com/snap/v1/transactions
   ```
2. Bersihkan config cache dan restart backend setelah `.env` berubah:
   ```bash
   cd /Users/mac/Downloads/NusaERPLite/backend
   php artisan config:clear
   ```
3. Login ke NusaERP, buat sales order, lalu lanjut ke Snap Sandbox.
4. Selesaikan transaksi memakai metode/kredensial pembayaran uji yang disediakan pada dokumentasi resmi Midtrans Sandbox.
5. Pantau terminal `cloudflared` dan log Laravel. Midtrans seharusnya POST ke URL notifikasi.
6. Periksa sales/invoice di aplikasi atau database: hanya callback settlement yang signature dan gross amount-nya valid yang menandai invoice paid dan mengurangi stok.

Redirect browser kembali ke aplikasi bukan bukti pembayaran berhasil. Status pembayaran berasal dari notification callback yang tervalidasi.

### 6. Bila URL Quick Tunnel berubah

Jika `cloudflared` dihentikan lalu dijalankan kembali, URL Quick Tunnel dapat berubah. Perbarui Payment Notification URL pada dashboard Midtrans dengan hostname baru sebelum melakukan transaksi berikutnya. Ini alasan Quick Tunnel hanya cocok untuk uji sementara.

## B. Named Tunnel untuk URL Stabil

### Prasyarat

- Akun Cloudflare.
- Domain aktif di Cloudflare, dengan nameserver/domain setup selesai.
- `cloudflared` terpasang pada komputer development.
- Backend NusaERP berjalan di `127.0.0.1:8000`.

### 1. Login cloudflared

```bash
cloudflared tunnel login
```

Browser akan terbuka untuk memilih akun dan domain. Credential tunnel disimpan lokal oleh `cloudflared`; jangan commit file credential tersebut ke repository.

### 2. Buat named tunnel

```bash
cloudflared tunnel create nusaerp-midtrans
```

Simpan UUID tunnel yang ditampilkan. File JSON credential dibuat di direktori konfigurasi `cloudflared` pengguna.

### 3. Hubungkan hostname DNS

Pilih hostname khusus, misalnya `erp-api.example.com` (ganti dengan domain Anda):

```bash
cloudflared tunnel route dns nusaerp-midtrans erp-api.example.com
```

Perintah membuat record DNS yang diarahkan ke tunnel.

### 4. Buat konfigurasi ingress

Buat/edit `~/.cloudflared/config.yml` dengan UUID dan lokasi credential yang benar-benar dihasilkan pada langkah sebelumnya:

```yaml
tunnel: <TUNNEL-UUID>
credentials-file: /Users/<macOS-username>/.cloudflared/<TUNNEL-UUID>.json

ingress:
  - hostname: erp-api.example.com
    service: http://127.0.0.1:8000
  - service: http_status:404
```

Ganti `<TUNNEL-UUID>`, `<macOS-username>`, dan hostname contoh. Jangan menebak lokasi credential; gunakan path yang dicetak saat tunnel dibuat. Baris catch-all `404` wajib berada terakhir.

Validasi aturan ingress:

```bash
cloudflared tunnel ingress validate
```

### 5. Jalankan tunnel bernama

```bash
cloudflared tunnel run nusaerp-midtrans
```

Pastikan hostname dapat diakses:

```bash
curl -i https://erp-api.example.com/up
```

### 6. Atur URL Midtrans

Set **Payment Notification URL** pada dashboard Sandbox menjadi:

```text
https://erp-api.example.com/api/payments/midtrans/notification
```

Hostname stabil sehingga tidak perlu diganti setiap sesi. Tunnel hanya berfungsi ketika komputer menyala, backend berjalan, dan proses tunnel aktif. Untuk penggunaan permanen, deploy backend pada hosting yang selalu hidup; jangan mengandalkan laptop development sebagai production server.

## C. Uji Endpoint Notifikasi Tanpa Mengubah Data

Request tanpa payload yang valid hanya memastikan routing/method mencapai Laravel; server akan menolaknya karena payload/signature tidak lengkap. Contoh:

```bash
curl -i -X POST https://<hostname-publik>/api/payments/midtrans/notification \
  -H 'Content-Type: application/json' \
  -d '{}'
```

HTTP `400` dengan pesan notifikasi tidak lengkap berarti request sudah sampai ke route Laravel, tetapi **bukan** bukti callback Midtrans valid. Jangan menganggap callback simulasi tanpa signature sebagai pembayaran sukses.

Untuk validasi nyata, lakukan transaksi Sandbox dan gunakan notifikasi yang dikirim Midtrans. Periksa log aplikasi dan status transaksi dari dashboard Midtrans.

## D. Troubleshooting

### Cloudflare memberi 502 / 1033

- Pastikan `php artisan serve` masih hidup pada port `8000`.
- Pastikan service tunnel menunjuk ke `http://127.0.0.1:8000`.
- Coba lokal `curl -i http://127.0.0.1:8000/up` sebelum menguji URL publik.
- Pastikan Named Tunnel berjalan dan hostname DNS diarahkan ke tunnel yang sama.

### Midtrans tidak memanggil callback

- Periksa Notification URL, mode Sandbox, dan hostname aktual (Quick Tunnel sering berubah).
- Pastikan path tepat: `/api/payments/midtrans/notification`.
- Pastikan backend memakai Server Key Sandbox yang berpasangan dengan akun environment Sandbox.
- Lihat hasil/status transaksi di dashboard Midtrans dan log Laravel.
- Callback adalah POST server-to-server; pengujian dengan membuka URL di browser melakukan GET dan tidak meniru callback.

### Callback sampai tetapi mendapat respons 403 atau 422

- 403: signature tidak valid atau Server Key yang dipakai tidak cocok dengan environment/transaksi.
- 422: gross amount dari callback tidak sama dengan amount invoice.
- Jangan mengubah status database secara manual untuk melewati validasi.

### Tunnel tidak stabil

Gunakan Named Tunnel untuk hostname stabil. Quick Tunnel tidak menjamin hostname tetap dan tidak ditujukan untuk service production.

## E. Keamanan dan Kebersihan Repository

- Jangan mengirim atau memasukkan Midtrans Server Key ke URL, command yang disimpan di shell history, screenshot, README, atau GitHub.
- Simpan Sandbox key hanya di `backend/.env` untuk backend lokal; untuk Docker Compose, isi root `.env` berdasarkan `.env.example` sesuai variabel yang dipakai compose.
- Pastikan `backend/.env`, root `.env`, file credential `~/.cloudflared/*.json`, dan private key tidak di-commit.
- Rotasi key bila pernah ditempel di chat, log, atau repository. Hapus key dari file dan riwayat commit tidak membuat key lama aman; rotasi tetap diperlukan.
- Tunnel menerbitkan service lokal. Terus hidupkan middleware autentikasi pada endpoint bisnis; route notification memang publik dan harus mengandalkan signature Midtrans.
- Matikan tunnel setelah demo sementara selesai dengan `Ctrl+C`.

## Referensi Resmi

- Cloudflare Quick Tunnels: <https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/do-more-with-tunnels/trycloudflare/>
- Cloudflare Tunnel: <https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/>
- Midtrans Payment Notification: <https://docs.midtrans.com/docs/https-notification-webhooks>
- Midtrans Sandbox: <https://docs.midtrans.com/docs/sandbox-environment>

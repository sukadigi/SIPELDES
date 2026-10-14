# SISTEM INFORMASI PELAYANAN SURAT DIGITAL DESA SUKAKERTA

Aplikasi PHP Native untuk pelayanan surat Pemerintah Desa Sukakerta, Kecamatan Cilamaya Wetan, Kabupaten Karawang. Source ini menyediakan demo SQLite lokal dan skema MySQL/MariaDB untuk pemasangan produksi.

## Arsitektur

- `index.php`: front controller, clean URL, pemeriksaan sesi dan HTTP security headers.
- `includes/`: koneksi PDO, autentikasi/RBAC, CSRF, fungsi umum, layout bersama, dan workflow surat.
- `views/`: halaman publik, warga, petugas, verifikasi, serta cetak.
- `database/schema.mysql.sql` dan `database/schema.sqlite.sql`: skema relasional yang sama untuk lingkungan tujuan.
- `database/install.php`: pemasang skema dan data awal yang dapat dijalankan ulang dari CLI.
- `assets/`: stylesheet responsif dan JavaScript ringan.

Seluruh modul memakai satu koneksi PDO. Surat memakai snapshot isi dan data pada saat terbit; nomor register dibuat dalam transaksi dan tetap terikat pada pengajuan saat revisi. Status penerbitan mensyaratkan verifikasi warga secara langsung dan persetujuan pejabat tercatat.

## Persyaratan

- PHP 8.1 atau lebih baru dengan PDO; `pdo_mysql` untuk MySQL/MariaDB atau `pdo_sqlite` untuk demo lokal.
- Apache dengan `mod_rewrite` dan izin `.htaccess` untuk shared hosting.
- MySQL 5.7.8+/MariaDB yang mendukung tipe JSON dan InnoDB untuk skema produksi.
- HTTPS wajib sebelum memakai data warga. Bootstrap dan font saat ini dimuat dari CDN.

## Menjalankan Demo

```sh
php database/install.php
php -S 127.0.0.1:8000 index.php
```

Buka `http://127.0.0.1:8000`. Installer membuat database SQLite di `storage/sipeldes.sqlite`, role awal, lima jenis surat, dan template standar. Kata sandi administrator dibuat acak dan hanya dicetak sekali pada terminal saat akun pertama kali dibuat. Simpan dengan aman; akun tersebut wajib mengganti kata sandi pada login pertama. Jalankan ulang installer tidak mereset akun atau kata sandi. Jika kredensial awal hilang, rotasi dengan `php database/reset_admin.php admin@sukakerta.desa.id`; kata sandi baru hanya dicetak sekali.

## Shared Hosting MySQL

1. Buat database dan user MySQL dengan hak minimum yang dibutuhkan.
2. Salin `.env.example` ke `.env`, lalu atur `APP_ENV=production`, `APP_URL` memakai HTTPS, `DB_DRIVER=mysql`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, dan `DB_PASSWORD`.
3. Isi `APP_KEY` dengan nilai acak panjang. Jangan unggah `.env` ke repositori.
4. Pastikan `pdo_mysql` aktif dan direktori penyimpanan database/dokumen dibatasi. Jika memungkinkan, arahkan `DB_PATH` dan penyimpanan dokumen ke direktori di luar document root.
5. Dari terminal hosting, jalankan `php database/install.php`. Jika tidak tersedia SSH/CLI, jalankan installer di lingkungan lokal yang tersambung aman ke database atau minta administrator hosting menjalankan perintah tersebut. Installer memang sengaja tidak tersedia lewat web.
6. Arahkan document root ke folder aplikasi, aktifkan `mod_rewrite`, lalu pastikan `/`, `/login`, dan `/health` dapat dibuka. Konfigurasi `.htaccess` menolak akses web ke `.env`, Git, database, dan direktori internal.
7. Masuk memakai email administrator yang dicetak installer, ganti kata sandi, lalu buka `/admin/pengguna` untuk membuat akun petugas. Kata sandi sementara ditampilkan satu kali; kirimkan melalui kanal aman.
8. Pastikan `display_errors=Off`, `log_errors=On`, HTTPS, backup terenkripsi, izin berkas minimum, serta pemulihan backup telah diuji sebelum data nyata dimasukkan.

Konfigurasi database SQLite dapat menunjuk lokasi absolut melalui `DB_PATH`. Untuk MySQL, SQLite driver tidak diperlukan. Kredensial hanya dibaca dari environment atau `.env` di luar akses publik.

## Akun, Peran, dan Alur

Role yang di-seed: Super Admin, Admin Desa, Operator, Verifikator, Kepala Desa/Pejabat Berwenang, Warga, dan Auditor. Otorisasi tindakan diperiksa di server; menu yang tersembunyi bukan pengganti kontrol izin. Akun awal yang dibuat installer adalah Super Admin.

Pengajuan warga masuk sebagai `Menunggu pemeriksaan admin`. Petugas memeriksa dokumen, verifikator mencatat pemeriksaan identitas langsung, operator menyiapkan proses, kepala desa menyetujui, kemudian pejabat yang berwenang menerbitkan snapshot surat. Kode pelacakan dan token verifikasi adalah token acak; halaman publik hanya menunjukkan jenis/status/tanggal dan tidak membuka identitas pemohon.

## Route Tersedia

- `/`, `/layanan-surat`, `/daftar`, `/login`, `/cek-status`
- `/dashboard`, `/ajukan-surat`, `/pengajuan/{id}`
- `/admin`, `/admin/pengajuan`, `/admin/pengajuan/{id}`, `/admin/template`, `/admin/generator-surat`, `/admin/pengguna`, `/admin/laporan`, `/admin/audit`
- `/verifikasi-surat/{token}`, `/surat/{id}/cetak`, `/health`

## Pengujian

```sh
php tests/run.php
find . -path './.git' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Batas Implementasi Saat Ini

Ini fondasi fungsional yang dapat diuji, bukan sistem yang telah disertifikasi atau siap memproses data kependudukan produksi. Upload dokumen privat, CRUD pengguna/permission, pengaturan nomor register visual, tanda tangan/QR bitmap, PDF server-side, ekspor Excel, konfirmasi pengambilan, pembatalan pascapenerbitan, dashboard analitik lanjutan, integrasi AI, dan pengujian MySQL pada server tujuan belum diimplementasikan. Cetak browser menyediakan pilihan simpan ke PDF. Gambar tanda tangan tidak digunakan atau diklaim sebagai tanda tangan elektronik tersertifikasi.

Sebelum peluncuran resmi, tambahkan kontrol yang belum ada, lakukan uji penetrasi dan uji pemulihan backup, tinjau dasar pemrosesan data pribadi, periksa skema pada versi database hosting, dan lakukan UAT bersama petugas berwenang. Jangan memasukkan NIK atau dokumen warga ke demo ini.
# Contract Records — Phase 2

## Tujuan
Menyimpan kontrak yang dibuat dari template Contract Studio. Tidak ada katalog produk, item kontrak, input harga, atau editor isi kontrak pada form pembuatan.

## Urutan migration
1. `20261105_001_multi_company_foundation.sql`
2. `20261105_002_contract_studio.sql`
3. `20261105_003_contract_products_and_records.sql` (nama file dipertahankan agar tidak mengubah urutan migration yang sudah disiapkan pada branch).

Backup database sebelum menjalankan migration. Jalankan setiap migration satu kali.

## Alur Create Contract
Form hanya berisi:
- Template kontrak: pilih template aktif milik company yang sedang login. Template default ditampilkan sebagai pilihan awal bila tersedia.
- Company Name: nama perusahaan penerima.
- Email: alamat penerima PDF.

Isi kontrak dan harga berasal dari template yang dipilih. Placeholder `{{company_name}}` diganti dengan nama perusahaan penerima. Kontrak menyimpan snapshot nama template, nomor versi, dan seluruh `document_json` pada saat dibuat. Perubahan template setelah itu tidak boleh mengubah kontrak lama.

## Tabel
- `contracts`: nomor kontrak, template/version, snapshot, nama perusahaan, email, tanggal, status, dan lokasi PDF.
- `contract_email_logs`: riwayat percobaan pengiriman email (queued/sent/failed).
- `contract_history`: audit aktivitas kontrak.

## Isolasi dan integritas
1. Semua operasi wajib memakai `company_id = require_company_id()`; jangan menerima tenant ID dari browser.
2. Template dan versi harus diverifikasi berasal dari company aktif.
3. Nomor kontrak unik per company.
4. Simpan PDF di storage non-public dan layani melalui endpoint terproteksi.
5. Email menggunakan konfigurasi SMTP aplikasi yang sudah ada; jangan menyimpan kredensial SMTP pada record kontrak.
6. Email log harus mencatat setiap percobaan; status terakhir ditampilkan pada Contract Records.
7. Jangan mengubah atau menghapus data sertifikat maupun tabel email sertifikat.

## Implementasi pada branch ini
- PDF renderer membaca halaman A4 portrait/landscape dan elemen text, rectangle, serta image dari snapshot Canvas.
- Placeholder `{{company_name}}` dan `{company_name}` diganti saat PDF dirender.
- Preview dan download dilayani melalui endpoint admin yang memeriksa company context.
- Generate PDF dan Send Email menggunakan POST + CSRF.
- Email dikirim melalui PHPMailer memakai konfigurasi SMTP Settings/.env yang sudah ada; PDF menjadi attachment.
- Setiap percobaan email dicatat sebagai queued/sent/failed dan dapat dilihat pada halaman Email History.
- Contract Studio memiliki tombol untuk memasukkan variabel Company Name.
- Halaman Contract Studio Templates memungkinkan admin menetapkan Default Template.
- `storage/.htaccess` menolak akses HTTP langsung ke file privat.

## Batasan dan QA
- Belum dilakukan uji PHP syntax, migration pada database, browser QA, atau pengiriman SMTP nyata.
- Renderer saat ini mendukung teks, rectangle, dan image data URL dari Canvas; elemen lain yang mungkin ditambahkan di masa depan perlu dukungan renderer tersendiri.
- Font khusus dari browser mungkin tidak identik dengan hasil PDF Dompdf; gunakan font standar untuk hasil konsisten.
- Image WebP membutuhkan ekstensi GD untuk dikonversi ke PNG.
- Pastikan document root hosting diarahkan ke folder `public`; `.htaccess` pada storage merupakan perlindungan tambahan, bukan pengganti konfigurasi document root yang benar.

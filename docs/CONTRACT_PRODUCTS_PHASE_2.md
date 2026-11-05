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

## Batasan fase ini
Migration ini menyiapkan struktur data. PDF renderer untuk format Canvas multi-page dan alur email kontrak harus diimplementasikan serta diuji sebelum fitur dianggap siap produksi. Editor Canvas saat ini menyimpan gambar sebagai data URL di JSON; ukuran snapshot perlu dibatasi dan aset produksi sebaiknya dipindahkan ke storage terproteksi.

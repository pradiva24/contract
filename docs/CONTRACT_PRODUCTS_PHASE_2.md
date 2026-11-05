# Contract Products & Records — Phase 2

## Tujuan
Menambahkan katalog produk per perusahaan dan penyimpanan kontrak yang membawa snapshot data pada saat kontrak dibuat. Migration ini bersifat additive dan tidak mengubah tabel sertifikat yang sudah ada.

## Urutan migration
1. `20261105_001_multi_company_foundation.sql`
2. `20261105_002_contract_studio.sql`
3. `20261105_003_contract_products_and_records.sql`

Backup database sebelum menjalankan migration. Jalankan setiap migration satu kali pada database aplikasi yang benar.

## Katalog produk
- `contract_products`: nama, SKU, deskripsi, mata uang, publish price, contract/net price, status aktif.
- `contract_product_inclusions`: daftar fasilitas/benefit produk.
- `contract_product_gallery`: referensi file foto produk.
- Semua tabel memiliki `company_id`; endpoint aplikasi wajib mengambil company dari sesi, bukan dari input browser.

## Data kontrak
- `contracts`: nomor kontrak unik per perusahaan, pelanggan, tanggal, status, total, referensi template, dan snapshot template.
- `contract_items`: kuantitas, dua jenis harga, total baris, serta snapshot produk dalam JSON.
- `contract_versions`: snapshot historis kontrak bernomor versi.
- `contract_history`: jejak aktivitas.

## Aturan integritas yang harus dipertahankan oleh layer aplikasi
1. Seluruh operasi baca/tulis harus memfilter `company_id = require_company_id()`.
2. Jangan menerima `company_id` dari POST/GET untuk menentukan tenant.
3. Saat kontrak dibuat, salin nama, deskripsi, harga, inclusions, dan referensi galeri yang relevan ke `product_snapshot_json`. Perubahan katalog berikutnya tidak boleh mengubah kontrak lama.
4. Salin dokumen template aktif ke `template_snapshot_json`. Kontrak historis tidak boleh berubah ketika template diedit.
5. Harga publik dan harga kontrak disimpan terpisah. Total kontrak dihitung dari harga kontrak, bukan publish price.
6. File galeri disimpan melalui upload terproteksi dan company-scoped; jangan menyimpan upload sebagai base64 di JSON template untuk produksi.
7. Status `signed` hanya boleh dicapai melalui alur tanda tangan yang tervalidasi; perubahan status dan pembuatan versi harus dicatat pada history.

## Catatan
Migration ini menyiapkan skema database. Belum ada UI katalog, upload media, alur create contract, PDF final, atau e-signature dalam fase ini. Foreign key template menggunakan ID global, sehingga layer aplikasi tetap wajib memvalidasi bahwa template dan versi template berasal dari company yang sama sebelum menyimpan kontrak.

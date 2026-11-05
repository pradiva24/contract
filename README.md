# Bali Tarantula Certificate System — Full Starter

Standalone certificate management application for PHP 8.2 + MySQL + PDO.

## Included modules
- Secure admin login, setup wizard, CSRF protection and role field
- Dashboard
- Certificate create/edit/issue/revoke/list/search
- Certificate number sequence (`BTA-YYYYMMDD-0001`)
- Template upload and configurable text/QR positions
- PDF generation using Dompdf
- QR generation using Endroid QR Code
- Public verification by token or certificate number
- SMTP email delivery using PHPMailer and email logs
- Settings and audit log foundation

## Requirements
- PHP 8.2
- MySQL 5.7+/MariaDB 10.4+
- PHP extensions: PDO, pdo_mysql, mbstring, gd, fileinfo
- Composer 2

## Local MAMP installation
1. Extract this project into MAMP `htdocs`.
2. Create database `tarantula_certificate` in phpMyAdmin.
3. Import `database/schema.sql`.
4. Copy `.env.example` to `.env`, then set the DB port/password to match MAMP.
5. In Terminal, open this project folder and run `composer install`.
6. Make `storage/` writable by the PHP process.
7. Open `http://localhost:8888/Bali_Tarantula_Certificate_System_Full/public/setup.php`.
8. Create the first administrator. The setup page locks automatically once an admin exists.
9. Open `.../public/login.php`.

## Template setup
After login, open Templates. Upload the certificate artwork as a landscape PNG/JPG. The template designer uses percentage-based positions (0–100) for:
- Recipient name
- Activity date
- Certificate number
- QR code

The starter ships with sensible defaults; fine-tune coordinates using Preview and issue a test certificate before production.

## Important
- Generated PDFs and QR images are stored under `storage/`, outside the public directory.
- Verification tokens are random and unique.
- Revoked certificates remain in the database and verify as revoked.
- Do not expose `.env`, `storage/`, or `vendor/` publicly. Prefer setting the web server document root to this project's `public/` directory. If MAMP points to the project root, deny direct access to `app/`, `database/`, and `storage/` using server configuration.
- Configure SMTP and send a test certificate before using with customers.
- This is a production-oriented starter, but perform staging tests and backups before live deployment.

## Multi-business contract settings

After applying the multi-company migrations in order, Super Admin can select a business from **Company Context** and open **Company Settings**. A company admin can only open settings for their assigned business.

Each business can maintain its own:
- Display name, address, phone/WhatsApp, contact email and website
- SMTP host, port, encryption, username, sender name/address and reply-to
- Encrypted SMTP password
- Contract email subject and HTML body

Contract email placeholders: `{business_name}`, `{company_name}`, `{contract_number}`, and `{contract_date}`. Contract email delivery reads SMTP credentials from the contract's owning company, not from the currently selected browser context. Certificate email delivery continues using the existing global Certificate Settings.

### Required application encryption key

Before saving a company-specific SMTP password, add a private `APP_KEY` to the project root `.env` file. Generate a random key, for example:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Add the generated value as `APP_KEY=... `. Keep the same key backed up securely. If it is lost or changed, previously encrypted company SMTP passwords cannot be decrypted. Never commit `.env` to GitHub.

To configure a business SMTP account: select that business in Company Context (Super Admin), open Company Settings, save SMTP details and password, then use **Send test email**. Leave the password field blank to keep the existing encrypted password.

### Company PDF branding

Open **Company Settings → PDF branding** for the selected business to upload:
- Company logo (PNG/JPG)
- Company stamp (PNG/JPG)
- Authorized signature (PNG/JPG)
- Optional business contact footer

Branding assets are stored under the private `storage/company-branding/<company_id>/` directory. Each asset is limited to 5 MB. The logo, stamp and signature can be enabled independently and their positions can be adjusted in canvas pixels. Enabled assets are overlaid on each page of newly generated contract PDFs. The contact footer uses the business address, phone and website.

After changing branding, open Contract Records and click **Generate** for each contract whose PDF should be refreshed. Existing PDFs are not silently overwritten by a settings change. Company-specific branding and settings are isolated by company ID.


### Import templates into Contract Studio

Contract Studio supports importing Word `.docx` files and Google Docs links:
- **Import DOCX** reads a local Word file in the browser using Mammoth.js and converts paragraphs, headings, tables, and embedded images into editable canvas objects.
- **Import Google Docs** exports a Google document as DOCX on the server, then uses the same editable import pipeline. The document must be shared as **Anyone with the link → Viewer**. Private documents requiring Google sign-in are not supported by this link-based import.
- Import creates a new unsaved template and does not overwrite the template currently open. Review the imported pages and click **Save template** to create the new template.

DOCX import is a best-effort layout conversion. Complex page layouts, columns, headers/footers, text wrapping, advanced typography, and precise image positioning may need manual adjustment in Contract Studio. The importer is limited to 8 MB source DOCX files. Google Docs link import requires PHP cURL enabled on the hosting server. Mammoth.js is loaded from jsDelivr, so the editor browser needs internet access.

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

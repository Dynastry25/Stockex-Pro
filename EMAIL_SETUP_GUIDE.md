# Email Deliverability Improvements - Setup Guide

## What Was Changed

### 1. New File: `config/email.php`
Centralized email configuration that both `send_contract_notes.php` and `send_email.php` now use.

### 2. Changed: `trader/send_contract_notes.php`
- Added `require_once '../config/email.php'`
- Replaced inline SMTP credentials with a call to `configureMailer($mail)`
- Emails will now be DKIM-signed when the key file is in place

### 3. Changed: `trader/send_email.php`
- Added `require_once '../config/email.php'`
- Replaced inline SMTP credentials with a call to `configureMailer($mail)`
- Added bulk mail headers: `Precedence: bulk`, `List-Unsubscribe`, `List-Id`
- Emails will now be DKIM-signed when the key file is in place

---

## What You Need to Do When Ready

### Step 1: Generate DKIM Keys (One-Time)

Run these commands on your server (Linux/macOS, or use Git Bash on Windows):

```bash
# Create the keys directory
mkdir -p config/dkim

# Generate a 1024-bit private key (2048 is better but 1024 is more compatible)
openssl genrsa -out config/dkim/dkim_private.pem 1024

# Extract the public key
openssl rsa -in config/dkim/dkim_private.pem -pubout -out config/dkim/dkim_public.pem

# Secure the private key (Windows: set appropriate permissions)
chmod 600 config/dkim/dkim_private.pem
```

### Step 2: Add DNS Records

Add these three DNS records for your domain (`neovam.com`):

#### a) DKIM Record
```
default._domainkey.neovam.com  IN  TXT  "v=DKIM1; h=sha256; k=rsa; p=<YOUR_PUBLIC_KEY>"
```

To get the public key content (remove header lines and newlines):
```bash
grep -v '^-' config/dkim/dkim_public.pem | tr -d '\n'
```

#### b) SPF Record (already might exist)
```
neovam.com  IN  TXT  "v=spf1 mx a include:mail.neovam.com ~all"
```

#### c) DMARC Record
```
_dmarc.neovam.com  IN  TXT  "v=DMARC1; p=quarantine; rua=mailto:dmarc@neovam.com"
```

### Step 3: Test

1. Visit the C Notes page and send a test email
2. Check `logs/` for "DKIM signing enabled" log entries
3. Send a test email to a Gmail/Hotmail address and check the raw headers for `dkim=pass`

### Step 4 (Optional): Enable SSL Verification

In `config/email.php`, change:
```php
define('SMTP_VERIFY_PEER', false);
```
to:
```php
define('SMTP_VERIFY_PEER', true);
```

Only do this if your server's SSL certificates are properly configured.

---

## Configuration File Reference

All adjustable settings are in `config/email.php`:

| Constant | Purpose |
|----------|---------|
| `SMTP_HOST` | Mail server hostname |
| `SMTP_PORT` | SMTP port (587 for TLS, 465 for SSL) |
| `SMTP_ENCRYPTION` | `tls` or `ssl` |
| `SMTP_USERNAME` | SMTP login email |
| `SMTP_PASSWORD` | SMTP password |
| `SMTP_VERIFY_PEER` | SSL verification (false for dev, true for production) |
| `DKIM_DOMAIN` | Your domain name |
| `DKIM_SELECTOR` | DKIM selector (must match DNS) |
| `DKIM_IDENTITY` | Signing identity (your from-address) |
| `DKIM_PRIVATE_KEY_FILE` | Path to private key |
| `EMAIL_CHARSET` | Character encoding |

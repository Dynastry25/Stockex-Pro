# Server Deployment Fix — Portal API 301 Redirect

## The Problem

The `POST` to `/api/portal/index.php` is being redirected with `301 Moved Permanently` by nginx. This redirect strips `.php` from the URL and converts `POST` to `GET`, causing a `405 Method Not Allowed` error.

## Server Fix Instructions

Run these on the production server (`ubuntu@instance-20260708-1034`).

### Step 1: Find your nginx site config

```bash
# Find which config file is active for stockex.vfsl.co.tz
grep -rl "stockex.vfsl.co.tz" /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null
```

### Step 2: Find the redirect rule causing the 301

```bash
# Look for any rule that strips .php or does a 301 redirect
grep -n "301\|rewrite.*\.php\|location.*\.php" /etc/nginx/sites-enabled/stockex*
```

### Step 3: Add this location block BEFORE the catch-all `location /`

Open the nginx config file (usually `/etc/nginx/sites-enabled/stockex.vfsl.co.tz` or similar) and add this block **above** the `location /` block:

```nginx
    # ── API: pass .php directly to PHP-FPM (no redirect) ────────────────
    location ~ ^/api/.*\.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }
```

**IMPORTANT:** Replace `unix:/run/php/php-fpm.sock` with the actual PHP-FPM socket path on your server. To find it:

```bash
ls /run/php/php*-fpm.sock
# or
php -i | grep "php-fpm"
```

### Step 4: If you have a global `.php` redirect rule, exclude `/api/`

If there is a rule like this somewhere in your nginx config:

```nginx
# THIS CAUSES THE PROBLEM — look for it and comment it out or add an exception
rewrite ^(/.*)\.php$ $1 permanent;
```

You need to either **remove it** or **add a condition to skip `/api/` paths**:

```nginx
# Fixed version — skips /api/ paths
set $no_redirect 0;
if ($request_uri ~ "^/api/") {
    set $no_redirect 1;
}
rewrite ^(/.*)\.php$ $1 permanent if=$no_redirect;
```

Or better, just remove the `.php` stripping redirect entirely — it is not needed and causes bugs.

### Step 5: Test and reload

```bash
# Test nginx config for syntax errors
sudo nginx -t

# If the test passes, reload nginx
sudo systemctl reload nginx
```

### Step 6: Verify

```bash
# This should return JSON, NOT a 301 redirect
curl -s -o /dev/null -w "%{http_code}\n" -X POST \
  -H "Content-Type: application/json" \
  -d '{"action":"test"}' \
  https://stockex.vfsl.co.tz/api/portal/index.php

# Expected output: 400 (Bad Request = PHP is processing the request correctly)
# If you still see 301, the redirect rule is still active
```

---

## Also: Deploy Updated Code

Pull the latest code to the server:

```bash
cd /path/to/stockex
git pull origin master
```

Then hard-refresh the browser (`Ctrl+Shift+R` or `Cmd+Shift+R`).

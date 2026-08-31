# StockEx — Client Self-Service API

Secured API that lets a **client update their own contact and payment (bank) details** without touching the database directly. The page that the UI team builds must talk **only** to this API.

- **Base URL:** `/api/portal/index.php`
- **Format:** JSON only, HTTPS only
- **File uploads:** not supported (any request with files is rejected `415`)

```
Client's page (browser)  ──HTTPS──▶  /api/portal/index.php  ──▶  Database
        ▲                                   │
        └────── JSON responses (success/error) ◀──────────────┘
No direct DB access from the page. The page only ever holds a token.
```

---

## 1. Authentication

Two kinds of actors use this API:

| Actor | Auth | Used for |
|---|---|---|
| **Staff** (trader/officer/admin, logged into StockEx) | Server session + CSRF token | Minting links, bulk actions |
| **Client** | `Authorization: Bearer <token>` header | Viewing/updating their own profile |

### How the client token is delivered

1. **Staff-Minted Link:** Staff calls `mint_link` → gets a one-time link: `https://clients.vfsl.co.tz/client-update.html?t=<TOKEN>`.
2. **Self-Service Claim:** Client visits `https://clients.vfsl.co.tz/` (public portal), enters CDS account, verifies via SMS OTP, and receives a short-lived bearer token.
3. The page must, on load:
   - read `t` from the URL query string (if present),
   - or receive a bearer token from the self-service flow,
   - move it into the header `Authorization: Bearer <TOKEN>`,
   - **immediately strip it from the URL** (if present) via `history.replaceState(null, '', window.location.pathname)`.

> Never: log the token, keep it in the URL, store it in localStorage, or show it in the UI.
> The token is shown only once at mint time; if lost, staff mints a new link.

Token policy (configurable at mint): **expires after 72h** and can be **used up to 20 times**. Older links for the same client are automatically revoked when a new one is minted.

---

## 2. Response envelope

All responses use this shape:

```json
{
  "success": true,
  "status_code": 200,
  "timestamp": "2026-08-28 09:00:00",
  "message": "Human readable message",
  "data": { }
}
```

Errors:

```json
{
  "success": false,
  "status_code": 401,
  "error": "Invalid or expired token.",
  "timestamp": "2026-08-28 09:00:00",
  "details": { }
}
```

---

## 3. Endpoints

### 3.1 `GET ?action=csrf_token` — staff only

Used by a staff page **before** calling `mint_link` / `revoke_link`.

```
GET /api/portal/index.php?action=csrf_token
```

Response `data`:
```json
{ "csrf_token": "0def05d8d81eee821d87e380cdaf32ff124a2a750a8777473253dfbdaa66697a" }
```

### 3.2 `POST ?action=mint_link` — staff only

Creates an expiring self-service link for one CDS account.

```
POST /api/portal/index.php?action=mint_link
Authorization: (staff session cookie)
Content-Type: application/json
```

Body:
```json
{
  "csrf_token": "0def05d8d81eee821d87e380cdaf32ff124a2a750a8777473253dfbdaa66697a",
  "cds_account": "885064",
  "expires_hours": 72,
  "max_uses": 20
}
```

`csrf_token` is required. `expires_hours` (1–720, default 72) and `max_uses` (1–100, default 20) are optional.

Response `data`:
```json
{
  "client_id": 4744,
  "client_name": "CHRISTER JOACHIM MHINGO",
  "cds_account": "885064",
  "token": "ffcbe70216d9632f5740900dc35acaf89a21b5c72ba2e527c900d1e82f70a133",
  "expires_at": "2026-08-31 09:03:02",
  "max_uses": 20,
  "link": "https://clients.vfsl.co.tz/client-update.html?t=ffcbe70216d9632f5740900dc35acaf89a21b5c72ba2e527c900d1e82f70a133"
}
```

The `token`/`link` is shown **once**. 404 if the CDS account does not exist; 400 if the client is inactive.

### 3.3 `GET ?action=get_profile` — client (Bearer)

Reads the current profile **of the token's own client only**.

```
GET /api/portal/index.php?action=get_profile
Authorization: Bearer ffcbe70216d9632f5740900dc35acaf89a21b5c72ba2e527c900d1e82f70a133
```

Response `data`:
```json
{
  "client_id": 4744,
  "client_name": "CHRISTER JOACHIM MHINGO",
  "cds_account": "885064",
  "client_type": "individual",
  "phone": "+255755123456",
  "email": "christer@example.com",
  "phone_masked": "+25****56",
  "bank_name": "CRDB Bank PLC",
  "bank_account_number": "0150XXXXXXX",
  "bank_account_masked": "015****XX",
  "bank_branch": "City Centre",
  "currency": "TZS",
  "token": { "expires_at": "2026-08-31 09:03:02", "times_used": 2, "max_uses": 20 }
}
```

Use this to **pre-fill the edit form**. Full values are returned because it is the owner.

### 3.4 `POST ?action=update_profile` — client (Bearer)

Updates one or more editable fields. Empty fields are ignored (you cannot clear a value via this API).

```
POST /api/portal/index.php?action=update_profile
Authorization: Bearer ffcbe70216d9632f5740900dc35acaf89a21b5c72ba2e527c900d1e82f70a133
Content-Type: application/json
```

Body (send only the fields being changed):
```json
{
  "phone": "+255755123456",
  "email": "new.user@example.com",
  "bank_name": "NMB Bank PLC",
  "bank_account_number": "20707955670",
  "bank_branch": "Kinondoni",
  "currency": "TZS"
}
```

Response `data`:
```json
{
  "client_id": 4744,
  "cds_account": "885064",
  "updated": ["phone", "email", "bank_name"],
  "remaining_uses": 18
}
```

`updated` lists the fields that actually changed. If nothing changed: message `"No changes were necessary."` and `updated: []`.

### 3.5 `POST ?action=revoke_token` — client (Bearer)

Makes the current token permanently invalid (client taps “I'm done” / logout).

```
POST /api/portal/index.php?action=revoke_token
Authorization: Bearer <token>
```

### 3.6 `POST ?action=revoke_link` — staff only

Revokes **all** active links for a CDS account (e.g. link sent by mistake).

```
POST /api/portal/index.php?action=revoke_link
Content-Type: application/json
```

Body: `{ "csrf_token": "...", "cds_account": "885064" }`

### 3.7 `POST ?action=lookup_cds` — Public

Finds an account and returns masked hints and channel state.

```
POST /api/portal/index.php?action=lookup_cds
Content-Type: application/json
Body: {"cds_account": "885064"}
```

Response `data`:
```json
{
  "cds_account": "885064",
  "client_name": "CHRISTER JOACHIM MHINGO",
  "name_hint": "CH*****GO",
  "channel_state": "established",
  "phone_masked": "+25****56",
  "phone_verified": true
}
```
`channel_state` is `"established"` if a phone was already verified (OTP will go to that number), or `"first_claim"` if no phone exists yet (OTP will go to the number the client provides).

### 3.8 `POST ?action=send_otp` — Public

Sends a 6-digit SMS verification code.

```
POST /api/portal/index.php?action=send_otp
Content-Type: application/json
Body: {"cds_account": "885064", "phone": "0755123456"}
```
- **First claim (`first_claim` state):** `phone` is required. The code is sent to this number to bind it.
- **Established account:** `phone` is ignored. The code is sent ONLY to the number already bound (verified).

Response `data`:
```json
{
  "phone_masked": "255****56",
  "purpose": "bind_phone",
  "channel_state": "first_claim",
  "cooldown_sec": 60
}
```

### 3.9 `POST ?action=verify_otp` — Public

Confirms the 6-digit code.

```
POST /api/portal/index.php?action=verify_otp
Content-Type: application/json
Body: {"cds_account": "885064", "phone": "0755123456", "code": "123456", "name": "CHRISTER JOACHIM MHINGO"}
```
- **First claim:** `phone`, `code`, and the full `name` (must match the account) are required.
- **Established:** Only `code` is required. The system validates against the bound number.

On success, returns a short-lived bearer token to use for editing:
```json
{
  "token": "3d8a...",
  "expires_at": "2026-08-31 09:00:00",
  "channel_state": "first_claim"
}
```

### 3.10 `POST ?action=change_phone` — Bearer

Starts changing the verified phone (sends OTP to the NEW number).
```json
Body: {"phone": "0788888888"}
```

### 3.11 `POST ?action=confirm_change_phone` — Bearer

Confirms the new phone by verifying the OTP.
```json
Body: {"phone": "0788888888", "code": "123456"}
```

---

## 4. Field validation rules

| Field | Rule | Example |
|---|---|---|
| `phone` | Tanzanian number: `0` + 9 digits or `+255` + 9 digits | `0755123456`, `+255755123456` |
| `email` | Standard email | `name@domain.com` |
| `bank_name` | Max 100 chars | `CRDB Bank PLC` |
| `bank_account_number` | 5–50 alphanumeric (hyphens allowed) | `015012345678` |
| `bank_branch` | Max 100 chars | `City Centre` |
| `currency` | One of `TZS`, `USD`, `EUR`, `GBP` | `TZS` |

Validation failures return `400` with `details.errors` (an array of messages to show under each field).

---

## 5. HTTP status codes

| Code | Meaning |
|---|---|
| 200 | OK |
| 400 | Validation / bad request (see `details`) |
| 401 | Missing, invalid, expired, or revoked token |
| 403 | Missing/invalid CSRF, or insufficient role |
| 404 | CDS account not found |
| 405 | Wrong HTTP method |
| 415 | File upload attempted (not supported) |
| 426 | HTTPS required |
| 429 | Rate limit exceeded (see below) |
| 500 | Server error |

---

## 6. Rate limits (per IP, rolling 1-minute window)

- Reads (`get_profile`): 60/min
- Writes (`update_profile`): 20/min, plus 5 updates per token per 5 min
- Mints/revokes (staff): 10/min
- Public lookups/OTP: Heavily limited (e.g., `lookup_cds`: 20/min, `send_otp`: 15/min, `verify_otp`: 10/min, `change_phone`: 6/min)
- OTP cooldown: Number-specific cooldown between SMS sends (default 60 seconds).

**UX note for 429:** disable the submit button and show “Too many attempts — please retry shortly.” Do not treat it as a permanent error.

---

## 7. Security do's and don'ts (for the UI team)

- **Do** use the token only as a `Bearer` header, never in URLs after page load.
- **Do** strip `?t=` from the URL immediately.
- **Do** render all values as plain text / disabled-look inputs until edited.
- **Do** call `revoke_token` when the client finishes, and on a “Done”/logout button.
- **Do** use HTTPS for both the page and the API.
- **Don't** `fetch` any other endpoint (especially the open `/api/v1/*` read-only API).
- **Don't** send files (there is no upload input). Sending files returns 415.
- **Don't** cache GET profile responses (sensitive data).
- **Don't** log request bodies or tokens in browser console / analytics.

---

## 8. JavaScript example

```js
// 1) capture token from link, then strip it from the URL
const params = new URLSearchParams(window.location.search);
const token = params.get('t');
if (token) history.replaceState(null, '', window.location.pathname);

const API = '/api/portal/index.php';

async function loadProfile() {
  const r = await fetch(`${API}?action=get_profile`, {
    headers: { 'Authorization': `Bearer ${token}` }
  });
  const body = await r.json();
  if (!body.success) { showError(body.error, body.details); return null; }
  return body.data; // fill form with data.phone, data.email, data.bank_* ...
}

async function saveProfile(changes) {
  const r = await fetch(`${API}?action=update_profile`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify(changes)
  });
  const body = await r.json();
  if (r.status === 429) { alert('Too many attempts. Please retry shortly.'); return false; }
  if (!body.success) { showFieldErrors(body.details?.errors); return false; }
  showSuccess(body.message + ' Updated: ' + body.data.updated.join(', '));
  return true;
}
```

---

## 9. Integration checklist

- [ ] Page reads `?t=`, moves it to `Authorization`, strips it from the URL.
- [ ] On load: `get_profile` → pre-fill phone, email, bank name, account, branch, currency.
- [ ] Save: send **only changed fields** via `update_profile`.
- [ ] Show per-field validation messages from `details.errors`.
- [ ] Handle 401 (expired link) → show “link expired, contact your broker”.
- [ ] Handle 429 → block submit with retry message.
- [ ] Confirmation screen after update, then `revoke_token`.
- [ ] No upload inputs; all fetches go to `/api/portal/index.php` only.
# StockEx — Client Submission API

Open-form submission API. Clients submit their details via a public form. Staff reviews submissions before applying changes to client records.

- **Base URL:** `/api/portal/index.php`
- **Format:** JSON only, HTTPS only

## Actions

### `POST ?action=lookup_cds` — Public

Look up a CDS account and check name match.

```json
{"cds_account": "123456", "name": "JINA LA KATI"}
```

Response:
```json
{
  "success": true,
  "data": {
    "cds_account": "123456",
    "client_name": "JINA LA KATI",
    "name_hint": "JA*****TI",
    "match_pct": 75,
    "requires_name": false,
    "bank_current": {"phone": "...", "email": "...", "bank_account_number": "..."}
  }
}
```

If `requires_name` is `true`, the name did not match ≥60%.

### `POST ?action=submit_details` — Public

Submit client details (phone, email, bank info). Name must match ≥60%.

```json
{
  "cds_account": "123456",
  "name": "JINA LA KATI",
  "phone": "0755123456",
  "email": "jina@mfano.com",
  "bank_name": "CRDB Bank PLC",
  "bank_account_number": "0123456789",
  "bank_branch": "Kinondoni",
  "currency": "TZS"
}
```

Response (201):
```json
{
  "success": true,
  "data": {"submission_id": 1, "match_pct": 75, "client_id": 123, "cds_account": "123456"},
  "message": "Submission received. We will review your details shortly."
}
```

### `POST ?action=list_submissions` — Staff

List pending submissions.

```json
{"status": "pending", "limit": 50, "offset": 0}
```

### `POST ?action=approve_submission` — Staff

Approve a submission and copy bank details to the client record.

```json
{"submission_id": 1}
```

### `POST ?action=reject_submission` — Staff

Reject with a reason.

```json
{"submission_id": 1, "reason": "Name does not match client records."}
```

### `POST ?action=get_submission` — Staff

Get submission details.

```json
{"submission_id": 1}
```

### `GET ?action=csrf_token` — Staff

Get a CSRF token for forms.

### `POST ?action=mint_link` — Staff

Generate a secure link for a client (legacy fallback).

### `POST ?action=revoke_link` — Staff

Revoke all active links for a CDS account.

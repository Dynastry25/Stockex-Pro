# StockEx — Client Submission API

Open-form client update API. Clients verify a CDS account by name, submit contact/address/payment details, and staff reviews each submission before approved values are applied to the client record.

- **Base URL:** `/api/portal/index.php`
- **Format:** JSON only, HTTPS only
- **Name-match threshold:** 60% by default (`PORTAL_NAME_MATCH_MIN`)

## Client actions

### `POST ?action=lookup_cds`

Look up a CDS account and compare the submitted name with the account name.

```json
{
  "cds_account": "123456",
  "name": "DENZEL CHANGA"
}
```

The comparison is not tied to first/middle/last-name positions. Any two or more exact stored name parts should pass the default gate, including reordered names. A single name is capped below the default pass mark. String similarity also contributes a smaller allowance for ordinary spelling variations.

Successful match:

```json
{
  "success": true,
  "data": {
    "cds_account": "123456",
    "client_name": "DENZEL ENOCK CHANGA",
    "name_hint": "DE***************GA",
    "match_pct": 89,
    "requires_name": false,
    "bank_current": {
      "phone": "255712345678",
      "email": "client@example.com",
      "address": "Mikocheni, Dar es Salaam",
      "payment_methods": {
        "bank": {
          "bank_name": "CRDB Bank PLC",
          "account_number": "0123456789",
          "branch": "Kinondoni",
          "currency": "TZS"
        }
      }
    }
  }
}
```

If the name does not pass the gate, the API returns `requires_name: true` and does not expose the full client/contact/payment record.

Rate limits remain enforced by the portal helpers.

### `POST ?action=submit_details`

Submit client contact/address details and one or more payment methods for staff review.

```json
{
  "cds_account": "123456",
  "name": "DENZEL CHANGA",
  "phone": "0755123456",
  "email": "client@example.com",
  "address": "Mikocheni, Dar es Salaam",
  "payment_methods": {
    "bank": {
      "bank_name": "CRDB Bank PLC",
      "account_number": "0123456789",
      "branch": "Kinondoni",
      "currency": "TZS"
    },
    "phone": {
      "phone_number": "0755123456",
      "provider": "M-Pesa"
    },
    "selcom": {
      "account_number": "SEL-123456",
      "account_name": "DENZEL CHANGA"
    }
  }
}
```

Supported `payment_methods` keys:

- `bank` — requires `bank_name`, `account_number`, and `currency`; `branch` is optional.
- `phone` — requires a valid Tanzanian `phone_number` and `provider`/mobile-money service.
- `selcom` — requires `account_number` and `account_name`.

When `payment_methods` is explicitly supplied, at least one supported method is required. Legacy flat bank fields are still accepted during a rolling frontend/backend transition.

Response (201):

```json
{
  "success": true,
  "data": {
    "submission_id": 1,
    "match_pct": 89,
    "client_id": 123,
    "cds_account": "123456"
  },
  "message": "Submission received. We will review your details shortly."
}
```

A pending submission for the same CDS account is rejected with `409`.

## Staff actions

### `POST ?action=list_submissions`

```json
{"status": "pending", "limit": 50, "offset": 0}
```

The admin and CEO review screens display submitted address and the selected Bank / Mobile Phone / Selcom details.

### `POST ?action=approve_submission`

```json
{"submission_id": 1}
```

Approval applies submitted phone, email and address. Approved `payment_methods` are stored on the client record. Bank details are also mirrored into the legacy bank columns for compatibility with existing StockEx functionality.

### `POST ?action=reject_submission`

```json
{"submission_id": 1, "reason": "Submitted details could not be verified."}
```

### `POST ?action=get_submission`

```json
{"submission_id": 1}
```

### `GET ?action=csrf_token`

Returns a CSRF token for authenticated staff operations.

### Legacy link actions

`mint_link` and `revoke_link` remain available for older workflows where applicable.

## Database migration

Before enabling the updated client frontend in production, apply the migration on the StockEx server:

```bash
php database/run_client_payment_details_migration.php
```

The runner applies the same changes documented in `database/client_payment_details_schema.sql` using the application's configured database connection. The migration adds `address` and `payment_methods` to the submission queue and `payment_methods` to the approved client record. The statements are additive/idempotent (`ADD COLUMN IF NOT EXISTS`).

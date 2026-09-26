# Smart Emailing — Bulk Email Engine Design

**Date:** 2026-09-26
**Status:** Approved

## Overview

A Laravel bulk email system. An admin uploads an Excel/CSV list of recipients,
reviews the parsed row count, composes a subject and rich-text message, and
sends. Every row in the uploaded file appears in the resulting report —
including rows rejected for invalid email syntax — with per-recipient status,
attempt count, and failure reason.

## Stack

| Concern | Choice | Note |
|---|---|---|
| Framework | Laravel 11 | PHP 8.2.30 local |
| Database | MySQL 5.7 | MAMP local. **Not 8.x** as the source spec assumed; no window functions used |
| Queue | `database` driver | Redis available but not required; switching is an `.env` change |
| Excel | `maatwebsite/excel` | Import + export |
| CSS | Bootstrap 5 via CDN | No build step |
| Editor | TinyMCE, self-hosted via npm | No API key, no cloud dependency |
| Auth | Session-based, custom `AuthController` | `is_admin` boolean flag |

## Architecture

Thin controllers delegate to services; services own business logic; models own
persistence. Jobs are dispatched from services, never controllers.

### Services

**`DynamicMailerService`**
Builds a Symfony mailer transport at runtime from the active `smtp_settings`
row, decrypting the stored password with `Crypt`. Takes an `SmtpSetting`,
returns a configured mailer. Called per job.

```php
Config::set('mail.mailers.dynamic_smtp', [
    'transport'  => 'smtp',
    'host'       => $smtp->host,
    'port'       => $smtp->port,
    'encryption' => $smtp->encryption,
    'username'   => $smtp->username,
    'password'   => Crypt::decryptString($smtp->password),
    'timeout'    => null,
]);
Mail::mailer('dynamic_smtp')->to(...)->send(...);
```

**`BatchImportService`**
Takes an uploaded file, returns an `EmailBatch`. Parses with chunked reads,
validates each row, bulk-inserts all `email_logs`.

**`BatchDispatchService`**
Takes an `EmailBatch`, queues one `SendBulkEmailJob` per `pending` log row.

## Flow

### Step 1 — Upload & parse (synchronous)

`POST /batches/upload`

The file is parsed inline using chunked reads — not queued. A 10k-row file is
a few seconds of request time; only *sending* needs the queue. This keeps the
count trustworthy and the flow free of polling endpoints or intermediate
states.

Per row, validation requires:
- `name` present
- `email` present
- `email` passes `filter_var($email, FILTER_VALIDATE_EMAIL)`

Creates `email_batches` with `status = 'draft'`, then bulk-inserts an
`email_logs` row for **every** row in the file:

- valid → `status = 'pending'`
- invalid → `status = 'failed'`, `remarks = 'Invalid email syntax'`, no job dispatched

Counters: `total_emails` = all rows, `pending_count` = valid, `failed_count` =
invalid.

Nothing is dropped at the door — this is what makes the report complete.

### Step 2 — Compose

`GET /batches/{batch}/compose`

Shows `Matching Records: N` (valid count), the invalid count when non-zero, a
Subject text field, and a TinyMCE Message editor. `{{ name }}` in the body is
substituted per recipient at send time.

### Step 3 — Send

`POST /batches/{batch}/send`

Validates subject and body, persists both onto the batch, sets `status =
'processing'`, and dispatches jobs via `BatchDispatchService`.

Guarded: a batch may only be sent from `draft`. A double-submit or page
refresh cannot send twice.

A `draft` batch never sent remains listed with a Draft badge — resumable or
deletable.

## Sending, Retry & Counters

`SendBulkEmailJob` takes an `EmailLog` id.

- `$tries = 3`
- `$backoff = [10, 30, 60]`
- explicit `$timeout`
- Horizon-compatible tags for monitoring

Outcomes:

| Outcome | Effect |
|---|---|
| Success | log `status = 'sent'`, `sent_at = now()`; batch `increment('sent_count')` + `decrement('pending_count')` |
| Transient failure | `increment('attempts')`, exception message → `remarks`, rethrow so Laravel retries per backoff |
| `failed(Throwable)` after attempt 3 | log `status = 'failed'`, final SMTP error → `remarks`; batch `increment('failed_count')` + `decrement('pending_count')` |

Counters use atomic SQL increments, never read-modify-write — parallel workers
will otherwise race and lose updates.

After each terminal outcome the job re-reads `pending_count` and marks the
batch `completed` at zero.

**Known limitation:** if no queue worker is running, batches sit at
`processing` indefinitely. Inherent to queued sending; the README documents the
`php artisan queue:work` requirement rather than the app shipping a watchdog.

## Database

### `users` (modified)
Add `is_admin` boolean, default `false`.

### `smtp_settings`
`id`, `name`, `host`, `port`, `username`, `password` (encrypted via `Crypt`),
`encryption` (`tls`/`ssl`/null), `from_address`, `from_name`, `is_active`
(boolean — only one active at a time), timestamps.

### `email_batches`
`id`, `batch_uuid`, `file_name`, `subject` (nullable until compose), `body`
(nullable until compose), `total_emails`, `sent_count`, `failed_count`,
`pending_count`, `status` (`draft`/`processing`/`completed`),
timestamps.

### `email_logs`
`id`, `batch_id` (FK → `email_batches.id`, cascade on delete), `name`, `email`,
`status` (`pending`/`sent`/`failed`), `attempts` (tinyint, default 0),
`remarks` (text, nullable), `sent_at` (datetime, nullable), timestamps.

Indexes on `batch_id` and `status`.

## Views

### `batches/index`
Upload card with file input and "Download Sample Template" link. Table of all
batches: file name, uploaded date & time, total, sent, failed, status badge,
and actions — View Report, Export Report (.xlsx), Delete Batch.

Delete removes the batch, cascades its logs via FK, and deletes the stored
file.

### `batches/compose`
Parsed count, Subject, TinyMCE Message, Send Bulk Email button.

### `batches/show`
Summary card: total, sent, failed, success rate. Paginated table of **all**
rows: name, email, status badge, date, time, attempts, remarks. Status filter
(All / Sent / Failed / Pending). Download Excel Report button.

### `smtp/`
Admin CRUD for SMTP profiles. Password encrypted on write, never rendered back
to the form. Activating one profile deactivates the others in a transaction so
`is_active` stays single-valued. Includes a "send test email" action —
surfacing bad credentials before 10,000 failures rather than after.

### `auth/login`, `auth/register`
Standard forms. Registration always writes `is_admin = 0`.

### `layouts/app`
Auth navbar with user name, role badge (Admin / User), logout button, flash
messages.

## Access Control

`auth` + `admin` middleware on every batch and SMTP route.
`IsAdminMiddleware` returns 403 for non-admins.

Registration is public and always creates non-admin users. Promotion is manual:

```sql
UPDATE users SET is_admin = 1 WHERE email = '...';
```

This follows the source spec. It means a newly registered user logs in to a
dead end until promoted — accepted deliberately.

## Sample Template

`GET /batches/sample-download` generates an `.xlsx` with columns `name` and
`email` plus two dummy rows.

## Testing

**Feature tests**
- upload → compose → send happy path
- invalid rows land in the report as `failed` with the syntax remark
- admin route protection (403 for non-admins)
- delete cascades logs
- send is rejected on a non-`draft` batch

**Unit tests**
- `BatchImportService` validation engine
- `SendBulkEmailJob` success / retry / permanent-failure outcomes with `Mail::fake()`
- `DynamicMailerService` transport construction and password decryption

Factories for all test data. `RefreshDatabase` for feature tests.

## Out of Scope

- Reusable email templates table (per-batch subject/body only)
- Per-batch "from" override (identity comes from the active SMTP profile)
- Per-user batch ownership (admin-only tool)
- Queued parsing with a polling page (synchronous parse chosen)
- Open/click tracking, unsubscribe handling, bounce processing

# Smart Emailing

A Laravel 11 bulk email system. Upload an Excel/CSV recipient list, review the
parsed count, compose a rich-text message, and send — with a complete
per-recipient delivery report.

**Every row of the uploaded file appears in the report.** Rows rejected for a
malformed address are recorded as `failed` with the reason rather than silently
dropped, so the report always accounts for the whole file.

---

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Running the app](#running-the-app)
- [How to use it](#how-to-use-it)
- [The recipient file](#the-recipient-file)
- [Timezone and recipient names](#timezone-and-recipient-names-in-the-report)
- [How sending works](#how-sending-works)
- [Routes](#routes)
- [Architecture](#architecture)
- [Database schema](#database-schema)
- [Testing](#testing)
- [Deployment](#deployment)
- [Troubleshooting](#troubleshooting)
- [Known limitations](#known-limitations)

---

## Features

### Two-step upload

The file is parsed **synchronously** the moment it is uploaded, so the count
you review is the real one. Only the sending is queued.

- Accepts `.xlsx`, `.xls`, `.csv` (max 10 MB)
- Validates every row: both `name` and `email` present, address RFC-parseable,
  neither field over 191 characters
- Creates a `draft` batch with one log row per file row — valid rows `pending`,
  invalid rows `failed` with a remark
- Fully blank rows are skipped; duplicates are preserved
- Downloadable sample template so the expected format is never a guess
- A batch can only be sent once — a double-submit or refresh cannot send the
  list twice

### Rich-text compose

- Shows **Matching Records: N** (sendable rows) before the message fields
- Reports how many rows were rejected, and why, before anything is sent
- Warns clearly when zero rows matched — usually wrong column headings — and
  disables the send button
- TinyMCE editor, self-hosted (no API key, no CDN, works offline)
- `{{ name }}` in the subject or body is replaced with each recipient's name

### Queued sending with retries

- One queued job per recipient
- 3 attempts with a 10s / 30s / 60s backoff
- The real SMTP error is recorded against the recipient on each attempt
- Batch counters move via atomic SQL increments, so parallel workers cannot
  race and lose updates
- The batch closes itself when the last recipient settles

### Database-stored SMTP

Credentials live in the database, not `.env`, so they can be changed without a
redeploy.

- Multiple profiles, exactly one active at a time
- Passwords encrypted at rest (`Crypt`), never rendered back into the form
- **Send test email** — verify credentials before a 10,000-recipient run
  rather than after it
- Editing a profile and leaving the password blank keeps the stored one

### Reporting

- Summary: total, sent, failed, success rate
- Every row with status, date, time, attempt count, and a specific failure
  reason — which field was wrong, or what the mail server actually said
- Times shown in the application timezone (`APP_TIMEZONE`), not UTC
- Recipient names derived from the email address, so the report reads
  consistently regardless of how the uploaded name column was filled in
- Filter by All / Sent / Failed / Pending
- Excel export of the report, honouring the active filter
- Delete a batch — removes its logs and the stored upload

### Access control

- Session authentication
- Registration is public but **always** creates non-admin users
- Every batch and SMTP route is admin-gated; non-admins get a 403
- Admins are promoted manually via SQL

---

## Requirements

| | |
|---|---|
| PHP | 8.2+ (with `pdo_sqlite`, which ships enabled by default) |
| Database | **None to install** — SQLite by default. MySQL 5.7+ (8.x fine) also supported |
| Composer | 2.x |
| Node | Only to refresh the vendored TinyMCE — not needed to run the app |

---

## Installation

```bash
git clone https://github.com/raubinjake/smart-emailing.git
cd smart-emailing
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
```

That is the whole database setup. `.env.example` defaults to **SQLite**, so a
fresh clone runs with no database server to install, configure or pay for:

```dotenv
APP_TIMEZONE=Asia/Kolkata

DB_CONNECTION=sqlite
# DB_DATABASE is optional — unset, it uses database/database.sqlite.
# If you do set it, give an ABSOLUTE path.

QUEUE_CONNECTION=database
```

Set `APP_TIMEZONE` to your own zone — it controls every date and time the app
records and displays. Use any
[PHP timezone identifier](https://www.php.net/manual/en/timezones.php)
(`Asia/Kolkata`, `Europe/London`, `America/New_York`).

WAL journalling, a 5s busy timeout and `PRAGMA foreign_keys=ON` are enabled
automatically for SQLite (`AppServiceProvider`). WAL is what lets the queue
worker write while you browse; `foreign_keys=ON` is required because SQLite
does not enforce foreign keys by default, and the app relies on `email_logs`
cascading when a batch is deleted.

<details>
<summary><strong>Using MySQL instead</strong></summary>

MySQL is fully supported — the two driver-specific SQL expressions branch on
the connection driver at runtime. Create the databases:

```sql
CREATE DATABASE smart_emailing;
CREATE DATABASE smart_emailing_test;
```

and swap the commented MySQL block in `.env` for the SQLite lines:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=smart_emailing
DB_USERNAME=root
DB_PASSWORD=root
```

> **MAMP note:** MAMP ships two MySQL port configurations — `3306` and `8889`.
> This project was developed against `3306`. If the connection is refused,
> check which one your install uses on MAMP's start page and adjust `DB_PORT`.

</details>

Run the migrations and seed the first admin:

```bash
php artisan migrate --seed
```

This creates:

| Email | Password |
|---|---|
| `admin@smart-emailing.test` | `password` |

> ⚠️ **Change this password before deploying anywhere.**

---

## Running the app

You need **two terminals**. The app serves pages in one and sends mail in the
other.

**Terminal 1 — the web server:**

```bash
php artisan serve
```

Open <http://127.0.0.1:8000>.

**Terminal 2 — the queue worker:**

```bash
php artisan queue:work
```

> **The queue worker must be running for mail to actually send.** Without it,
> batches sit at `processing` and nothing is delivered. This is inherent to
> queued sending — the app does not ship a watchdog for it.

To watch a batch drain, leave the worker running and refresh the report page.

---

## How to use it

### 1. Configure SMTP

Go to **SMTP → Add Profile** and fill in your mail server details:

| Field | Example |
|---|---|
| Profile name | `Primary` |
| Host | `smtp.gmail.com` |
| Port | `587` |
| Username | `you@gmail.com` |
| Password | your SMTP password or app password |
| Encryption | `TLS` |
| From address | `noreply@yourdomain.com` |
| From name | `Your Company` |

The first profile you create becomes active automatically. Use the **Test**
button — enter your own address and send a probe. If credentials are wrong you
find out here, not after a thousand failures.

### 2. Prepare the recipient list

Click **Sample** on the Batches page to download a template, then fill in your
recipients. See [The recipient file](#the-recipient-file) for the format.

### 3. Upload

On **Batches**, choose your file and click **Upload & Continue**. The file is
parsed immediately and you land on the compose page.

### 4. Compose

The page shows **Matching Records: N** — how many rows will actually be sent —
plus a note about any rejected rows.

Write your subject and message. Use `{{ name }}` anywhere in either and it is
replaced per recipient:

```text
Subject:  Welcome {{ name }}!
Message:  Hi {{ name }}, thanks for signing up.
```

Ada Lovelace receives *"Welcome Ada Lovelace!"*.

### 5. Send

Click **SEND BULK EMAIL**. Jobs are queued and the batch flips to
`processing`. You are redirected to the report.

### 6. Read the report

Watch it fill in as the worker runs. Filter by status, or download the whole
thing as `.xlsx`.

---

## The recipient file

Two columns. **The heading row is required**, and the headings must be exactly
`name` and `email` — column order does not matter.

```csv
name,email
Ada Lovelace,ada@example.com
Alan Turing,alan@example.com
Grace Hopper,grace@example.com
```

| Problem | Remark in the report |
|---|---|
| Name cell blank | `Name is missing` |
| Email cell blank | `Email address is missing` |
| No `@` | `Not an email address (no @ sign)` |
| More than one `@` | `Address contains more than one @ sign` |
| Space inside the address | `Address contains a space` |
| Domain with no dot | `Domain "localhost" has no dot` |
| Otherwise malformed domain | `Malformed domain "gmailll.com..,"` |
| Value over 191 characters | `Name is longer than 191 characters` |
| Repeated address | `Duplicate address in this file` — **still sent** |
| Fully blank row | Skipped entirely, not counted |

Rejected rows are never sent but always appear in the report. A duplicate is
the exception — it is flagged but still delivered, since a file may contain one
deliberately.

Send failures are translated too, so the report reads
`Mailbox does not exist at that address` rather than a raw `550 5.1.1` dump.
An error the app does not recognise is kept verbatim so no detail is lost.

---

## Timezone and recipient names in the report

**Times follow `APP_TIMEZONE`.** Set it once in `.env` and the report page, the
Excel export, and the batch list all agree. Timestamps are stored as written by
the app, so changing the setting later affects new rows — a migration ships
with this project that shifted the rows written before the setting was
introduced.

**Names are derived from the email address**, not from the uploaded `name`
column. Uploaded names tend to be inconsistent (`robin`, `robin2`, `rbn 3`), so
the report builds a readable name from the address instead:

| Address | Shown as |
|---|---|
| `robin@yopmail.com` | Robin |
| `robinos36ty@gmail.com` | Robinos36ty |
| `ada.lovelace@example.com` | Ada Lovelace |
| `alan_turing@example.com` | Alan Turing |
| `robin+newsletter@gmail.com` | Robin |
| `RobinKumar@example.com` | RobinKumar |

Dots, underscores and hyphens become spaces, a `+tag` is dropped, and a word
already mixed-case is left as typed.

> The uploaded `name` is still stored and is what `{{ name }}` inserts into
> outgoing mail — recipients are greeted by the name you supplied, while the
> report uses the derived one.

---

## How sending works

```text
Upload  ──► parse synchronously ──► draft batch + one log row per file row
                                     (valid → pending, invalid → failed)
                                            │
Compose ──► subject + body ────────────────►│
                                            │
Send    ──► claim batch atomically ─────────► status = processing
            queue 1 job per pending row
                                            │
Worker  ──► per job: build transport from the active SMTP profile
            substitute {{ name }}, send
              ├─ success  → log sent,   sent_count++,   pending_count--
              ├─ failure  → attempts++, remark recorded, retry (10s/30s/60s)
              └─ 3 fails  → log failed, failed_count++, pending_count--
                                            │
                        pending_count hits 0 ──► batch completed
```

---

## Routes

All batch and SMTP routes require authentication **and** an admin account.

| Method | URI | Name | Purpose |
|---|---|---|---|
| GET | `/login` | `login` | Login form |
| POST | `/login` | — | Authenticate |
| GET | `/register` | `register` | Registration form |
| POST | `/register` | — | Create a non-admin account |
| POST | `/logout` | `logout` | Log out |
| GET | `/` | — | Redirects to the dashboard |
| GET | `/batches` | `batches.index` | Upload card + batch list |
| GET | `/batches/sample-download` | `batches.sample` | Download the sample template |
| POST | `/batches/upload` | `batches.upload` | Parse a file into a draft batch |
| GET | `/batches/{batch}/compose` | `batches.compose` | Count + message form |
| POST | `/batches/{batch}/send` | `batches.send` | Queue the send |
| GET | `/batches/{batch}` | `batches.show` | The report |
| GET | `/batches/{batch}/export` | `batches.export` | Report as `.xlsx` |
| DELETE | `/batches/{batch}` | `batches.destroy` | Delete batch, logs, upload |
| GET | `/smtp` | `smtp.index` | Profile list |
| GET | `/smtp/create` | `smtp.create` | New profile form |
| POST | `/smtp` | `smtp.store` | Save a profile |
| GET | `/smtp/{smtp}/edit` | `smtp.edit` | Edit form |
| PUT | `/smtp/{smtp}` | `smtp.update` | Update a profile |
| POST | `/smtp/{smtp}/activate` | `smtp.activate` | Make this the active profile |
| POST | `/smtp/{smtp}/test` | `smtp.test` | Send a test message |
| DELETE | `/smtp/{smtp}` | `smtp.destroy` | Delete a profile |

---

## Architecture

Thin controllers delegate to four services, each independently testable.

| Service | Responsibility |
|---|---|
| `BatchImportService` | Parses the upload synchronously, validates every row, creates the draft batch and all log rows in chunked bulk inserts |
| `BatchDispatchService` | Claims the batch atomically (`UPDATE … WHERE status = draft`), saves the message, queues one job per pending row |
| `DynamicMailerService` | Builds a Symfony mail transport at runtime from the active `smtp_settings` row |
| `SendBulkEmailJob` | Sends one recipient's message, records the outcome, advances the batch counters |

```text
app/
├── Exports/          BatchReportExport, SampleTemplateExport
├── Http/
│   ├── Controllers/  AuthController, EmailBatchController, SmtpSettingController
│   ├── Middleware/   IsAdminMiddleware
│   └── Requests/     UploadBatchRequest, SendBatchRequest, SmtpSettingRequest
├── Imports/          BulkEmailImport
├── Jobs/             SendBulkEmailJob
├── Mail/             BulkEmailMessage
├── Models/           User, SmtpSetting, EmailBatch, EmailLog
└── Services/         BatchImportService, BatchDispatchService, DynamicMailerService
```

Three decisions worth knowing:

- **Parsing is synchronous, sending is queued.** A 10,000-row file parses in
  about 1.6s using 21 bulk inserts rather than 10,000 individual ones. That
  keeps the reviewed count trustworthy without a polling page.
- **Counters move via SQL increments**, never read-modify-write, so parallel
  workers cannot lose updates.
- **The mailer is purged before each reconfigure.** Laravel caches resolved
  mailers for the life of the process; without purging, a long-running worker
  would keep using the first SMTP profile it ever saw.

---

## Database schema

**`users`** — Laravel's default plus `is_admin` (boolean, default false).

**`smtp_settings`** — `name`, `host`, `port`, `username`, `password`
(encrypted), `encryption`, `from_address`, `from_name`, `is_active`.

**`email_batches`** — `batch_uuid`, `file_name`, `stored_path`, `subject`,
`body`, `total_emails`, `sent_count`, `failed_count`, `pending_count`,
`status` (`draft` → `processing` → `completed`).

**`email_logs`** — `batch_id` (FK, cascade delete), `name`, `email`, `status`
(`pending` / `sent` / `failed`), `attempts`, `remarks`, `sent_at`.

---

## Testing

```bash
php artisan test
```

115 tests run against an **in-memory SQLite** database — no server, no setup,
and nothing to clean up afterwards.

To verify the MySQL driver as well, point the same suite at a MySQL database
(command-line variables override `phpunit.xml`):

```bash
DB_CONNECTION=mysql DB_DATABASE=smart_emailing_test php artisan test
```

Run a single file:

```bash
php artisan test --filter=EmailBatchFlowTest
```

Beyond the suite, the pipeline has been verified end to end against a real SMTP
server: messages delivered with correct per-recipient substitution, an SMTP
profile swapped mid-run taking effect immediately, and the 3-attempt retry
policy driven to permanent failure with the real error landing in the report.

---

## Deployment

Three routes, depending on budget and how fast you need sending to start:

| | Guide | Cost | Sending starts |
|---|---|---|---|
| **Vercel + Aiven MySQL** | [DEPLOY-FREE.md](DEPLOY-FREE.md) | **$0** | ~1 min (external pinger) |
| **Railway / Laravel Cloud / VPS** | [DEPLOYMENT.md](DEPLOYMENT.md) | ~$5/mo | Immediate (real worker) |
| **Vercel Pro** | [DEPLOY-VERCEL.md](DEPLOY-VERCEL.md) | $20/mo | ~1 min (native cron) |

The free route works because the queue is drained by hitting a secret-guarded
URL rather than by a worker process. Vercel's own cron only runs **once a day**
on the Hobby plan — a per-minute schedule fails at deploy time — so a free
external pinger (cron-job.org) drives it instead.

Note SQLite cannot be used on Vercel: the filesystem is read-only apart from
`/tmp`, which is per-instance. Use hosted MySQL there.

A `Dockerfile` ships for the first route; the same image serves both roles:

```bash
entrypoint web      # nginx + php-fpm, runs migrations on boot
entrypoint worker   # queue:work — without this, nothing is delivered
```

---

## Troubleshooting

**Nothing sends; the batch stays at "Processing".**
The queue worker is not running. Start `php artisan queue:work` in a second
terminal.

**"Matching Records: 0" after uploading a valid file.**
The column headings are not exactly `name` and `email`. Download the sample
template and compare.

**The test email fails.**
Check the host, port and encryption. Gmail requires an
[App Password](https://support.google.com/accounts/answer/185833) rather than
your account password. The full SMTP error is in `storage/logs/laravel.log` —
the on-screen message is deliberately generic, since SMTP errors can echo back
your username.

**"SQLSTATE[HY000] [2002] Connection refused".**
Only applies when using MySQL: it is not running, or is on a different port.
MAMP often uses `8889`. On the default SQLite setup there is no server to run.

**"Database file at path ... does not exist" (SQLite).**
Create it: `touch database/database.sqlite`, then `php artisan migrate`. If
`DB_DATABASE` is set, it must be an **absolute** path.

**A registered user sees 403 everywhere.**
That is by design — registration creates non-admin accounts. Promote them:

```sql
UPDATE users SET is_admin = 1 WHERE email = 'them@example.com';
```

**Report times are wrong / look shifted by several hours.**
`APP_TIMEZONE` in `.env` is not your zone. Set it, then
`php artisan config:clear`. New rows use it immediately; rows written under the
old setting keep their old values unless you shift them.

**Changed the SMTP profile but mail still uses the old one.**
Only if your worker predates this fix — restart `queue:work`. Current code
purges the cached mailer on every reconfigure.

---

## Known limitations

- `BatchReportExport` uses `FromCollection`, loading all rows into memory. Fine
  for typical batches; around 50,000 rows would want `FromQuery` with chunked
  reading.
- If a job dispatch throws partway through `BatchDispatchService::dispatch()`,
  the batch is left `processing` with only some jobs queued, and there is no
  resume path.
- Deleting the active SMTP profile mid-run fails every remaining job cleanly —
  the reason appears in the report, but the batch will not finish sending.
- There is no open/click tracking, unsubscribe handling, or bounce processing.
  This is a sender, not a full campaign platform.
- Sending is not rate-limited. If your provider caps throughput, control it
  with worker concurrency (`queue:work --sleep`) or provider-side limits.

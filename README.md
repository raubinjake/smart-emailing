# Smart Emailing

Bulk email sender. Upload an Excel/CSV recipient list, review the parsed count,
compose a message, and send — with a full per-recipient delivery report.

**Every row of the uploaded file appears in the report**, including rows
rejected for invalid email syntax. Those are recorded as `failed` with the
reason and are never sent.

## Requirements

- PHP 8.2+
- MySQL 5.7+
- Composer, Node (Node is only needed to refresh the vendored TinyMCE)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the databases, then set the connection in `.env`:

```sql
CREATE DATABASE smart_emailing;
CREATE DATABASE smart_emailing_test;
```

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=smart_emailing
DB_USERNAME=root
DB_PASSWORD=root
QUEUE_CONNECTION=database
```

Then:

```bash
php artisan migrate --seed
```

Default admin: `admin@smart-emailing.test` / `password`. **Change this before
deploying anywhere.**

Registration is public but always creates non-admin users, who get a 403 on
every page. Promote someone manually:

```sql
UPDATE users SET is_admin = 1 WHERE email = '...';
```

## Running

```bash
php artisan serve          # http://127.0.0.1:8000
php artisan queue:work     # in a second terminal
```

**The queue worker must be running for mail to send.** Without it, batches sit
at `processing` and nothing is delivered. The app does not ship a watchdog for
this — it is inherent to queued sending.

## Usage

1. **SMTP** — add a profile and activate it. Credentials live in the database
   (password encrypted at rest), not in `.env`. Use **Test** to send a probe
   first: bad credentials surface there rather than after 10,000 failures.
2. **Batches** — download the sample template, fill in the `name` and `email`
   columns, and upload it. Column headings must be exactly `name` and `email`;
   a file with different headings parses to zero rows and the compose page
   says so.
3. **Compose** — the page shows `Matching Records: N` (valid rows) plus any
   rejected count. Write the subject and message. `{{ name }}` in either is
   replaced with each recipient's name.
4. **Send** — one queued job per valid recipient, 3 attempts with 10s/30s/60s
   backoff. A batch can only be sent once.
5. **Report** — every row with its status, attempt count, and failure reason.
   Filter by status, or download the whole thing as `.xlsx`.

## Architecture

Thin controllers delegate to four services:

| Service | Responsibility |
|---------|---------------|
| `BatchImportService` | Parses the upload **synchronously**, validates every row, creates the draft batch and all log rows |
| `BatchDispatchService` | Claims the batch atomically (`WHERE status = draft`), saves the message, queues one job per pending row |
| `DynamicMailerService` | Builds a Symfony mail transport at runtime from the active `smtp_settings` row |
| `SendBulkEmailJob` | Sends one recipient's message, records the outcome, advances the batch counters atomically |

Two decisions worth knowing:

- **Parsing is synchronous, sending is queued.** A 10k-row file parses in
  ~1.6s using chunked bulk inserts (21 statements, not 10,000), which keeps the
  reviewed count trustworthy without a polling page.
- **Counters move via SQL increments**, never read-modify-write, so parallel
  workers cannot race and lose updates.

## Testing

```bash
php artisan test
```

76 tests run against the `smart_emailing_test` database.

Beyond the suite, the pipeline has been verified end to end against a real SMTP
server: messages delivered with correct per-recipient substitution, and the
3-attempt retry policy driven to permanent failure with the SMTP error landing
in the report.

## Known limitations

- `BatchReportExport` uses `FromCollection`, loading all rows into memory. Fine
  for typical batches; a batch of ~50k rows would want `FromQuery` with chunked
  reading.
- If a job dispatch throws partway through `BatchDispatchService::dispatch()`,
  the batch is left `processing` with only some jobs queued. There is no resume
  path.
- Deleting the active SMTP profile mid-run fails every remaining job cleanly —
  the reason appears in the report, but the batch will not finish sending.

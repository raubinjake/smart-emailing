# Deploying Smart Emailing on Vercel

This works, with one design change you need to understand before you start.

**Vercel has no long-running process**, so `php artisan queue:work` cannot be
left running. Instead, a **Vercel Cron hits a protected route every minute** and
drains whatever is queued. Jobs keep their retry policy, backoff and reporting —
the only difference is that a batch may wait up to a minute before it starts
sending.

If you would rather have a persistent worker and instant sending, see
[DEPLOYMENT.md](DEPLOYMENT.md) for the Railway/Render/Fly route, which runs the
app unchanged.

---

## What you need

| | |
|---|---|
| Vercel account | Hobby works; **Cron on Hobby runs once per day**, so Pro is needed for per-minute sending |
| A hosted MySQL database | Vercel does not host one, and SQLite does not work here — see below |
| An SMTP provider | Configured in-app after deploy, not in env vars |

### SQLite does NOT work on Vercel

The app defaults to SQLite for local development, and SQLite is the cheapest
option on a host with a persistent volume ([DEPLOYMENT.md](DEPLOYMENT.md)). **On
Vercel it is not an option at all**, and it is worth being precise about why:

- **The filesystem is read-only** apart from `/tmp`, so the database file cannot
  live anywhere in the deployed app directory.
- **`/tmp` is per-instance and ephemeral.** Each serverless invocation may run on
  a different instance, and instances are recycled freely. A database written to
  `/tmp` would be invisible to the next request and gone shortly after — you
  would see batches vanish between page loads.

There is no configuration that works around this; it is what serverless means.
**Vercel needs a hosted MySQL database.**

> *Aside:* [Turso](https://turso.tech) offers hosted SQLite over HTTP, which does
> suit serverless. It needs a separate driver package (`libsql`), which this repo
> does not install and this guide does not cover. Mentioned only so you know the
> option exists.

### Picking a database

The app runs on either MySQL or SQLite — the driver-specific SQL branches at
runtime — but since SQLite is unusable on Vercel, use a hosted **MySQL** here.
Postgres is not supported:

| Provider | Notes |
|---|---|
| **Aiven** | Free MySQL tier, good for trying this out |
| **Railway MySQL** | ~$5/mo, simple, reliable |
| **PlanetScale** | MySQL-compatible, no free tier since 2024 |
| **Clever Cloud** | Free MySQL tier |

Whichever you choose, you need: host, port, database name, username, password.
Most managed MySQL requires TLS — that is handled below.

---

## Step by step

### 1. Push the repo to GitHub

Vercel deploys from a Git repository.

### 2. Import the project

[vercel.com/new](https://vercel.com/new) → import `smart-emailing`.

Leave the framework preset as **Other**. `vercel.json` already declares the PHP
runtime, the routes, and the cron.

### 3. Generate an APP_KEY

```bash
php artisan key:generate --show
```

Copy the `base64:…` output.

### 4. Set environment variables

Project → **Settings** → **Environment Variables**. Add all of these to
**Production** (and Preview, if you use preview deploys):

```
APP_NAME=Smart Emailing
APP_ENV=production
APP_KEY=base64:…                 ← from step 3
APP_DEBUG=false
APP_URL=https://your-app.vercel.app
APP_TIMEZONE=Asia/Kolkata

DB_CONNECTION=mysql
DB_HOST=your-db-host
DB_PORT=3306
DB_DATABASE=your-db-name
DB_USERNAME=your-db-user
DB_PASSWORD=your-db-password
MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
FILESYSTEM_DISK=local

LOG_CHANNEL=stderr

CRON_SECRET=<a long random string>
```

Notes on three of these:

- **`MYSQL_ATTR_SSL_CA`** — most managed MySQL requires TLS. Omit this line if
  your provider does not.
- **`SESSION_DRIVER=database`** — a file-based session would live in `/tmp` on
  one instance and be missing on the next, so logins would randomly drop.
  Sessions must go in MySQL.
- **`CRON_SECRET`** — Vercel sends this as a bearer token on cron invocations.
  Without it the queue route rejects everything, including the real cron.
  Generate one with `openssl rand -hex 32`.

### 5. Deploy, then migrate

The first deploy will build but the database is still empty.

Run the migrations from your own machine, pointed at the remote database:

```bash
DB_HOST=your-db-host \
DB_PORT=3306 \
DB_DATABASE=your-db-name \
DB_USERNAME=your-db-user \
DB_PASSWORD=your-db-password \
php artisan migrate --force --seed
```

That creates the schema and the first admin:
`admin@smart-emailing.test` / `password`. **Change it immediately.**

Migrations are deliberately not run on each request — doing that on a
serverless platform means every cold start races the schema.

### 6. Verify the cron is registered

Project → **Settings** → **Cron Jobs**. You should see `/api/queue` scheduled
`* * * * *`.

**On the Hobby plan, cron runs once per day regardless of the schedule.** That
makes sending effectively unusable. Upgrade to Pro for per-minute runs, or use
the Railway route instead.

---

## Verifying the deploy

1. Open the URL — the login page should render with styles.
2. Log in, add an SMTP profile, and press **Test**. A delivered test message
   proves credentials and outbound connectivity.
3. Upload a small file, compose, send.
4. Within a minute the report should start filling in. If it does not, check
   **Logs** → filter to `/api/queue`.

To trigger the drain manually instead of waiting:

```bash
curl -H "Authorization: Bearer $CRON_SECRET" https://your-app.vercel.app/api/queue
```

A correct response is `{"ok":true,"ran_at":"…"}`. A `401` means `CRON_SECRET`
does not match.

---

## What is different from a normal deployment

**Sending is delayed by up to a minute.** The cron drains once per minute.

**Large batches span several cron runs.** Each run works for about 50 seconds
then exits cleanly, and the next picks up where it left off. A 5,000-recipient
batch takes several minutes. Nothing is lost — jobs stay in the queue table.

**Uploaded spreadsheets are not retained.** `/tmp` is per-instance and
short-lived, so the original file is usually gone by the time you delete a
batch. This affects nothing you can see: every recipient row is parsed into
MySQL at upload time, and the report reads from there. Delete Batch still
removes the batch and its rows.

If you need the files kept, set `FILESYSTEM_DISK=s3` and add the AWS variables —
the application code needs no change.

**Cold starts.** The first request after idle takes a second or two while PHP
boots.

---

## Troubleshooting

**500 on every page.**
Usually a missing `APP_KEY`, or `APP_DEBUG=false` hiding a config error. Check
**Logs**. Set `APP_DEBUG=true` briefly to see the real message, then turn it
back off — a debug stack trace exposes environment variables.

**"SQLSTATE[HY000] [2002] Connection refused" or a TLS error.**
The database is unreachable from Vercel, or it requires TLS and
`MYSQL_ATTR_SSL_CA` is unset. Some providers also need their IP allowlist
opening to `0.0.0.0/0`, since Vercel functions have no fixed egress IP.

**Logged in, then immediately logged out again.**
`SESSION_DRIVER` is not `database`. File sessions do not survive between
invocations.

**Pages render without CSS.**
`APP_URL` does not match the deployed domain, so `asset()` generates wrong
URLs.

**Batches stay at "Processing".**
The cron is not running, or `CRON_SECRET` is wrong. Trigger it manually with the
curl above — if that returns `401`, the secret does not match; if it returns
`{"ok":true}` and rows move, the schedule is the problem (check the Hobby-plan
limit).

**Excel export returns a 500.**
The PHP runtime is missing `gd`, `intl` or `zip`. `vercel-php` bundles them, so
this usually means the runtime version in `vercel.json` failed to resolve —
check the build log.

# Deploying Smart Emailing

This app needs three things its host must provide: **a persistent PHP process**,
**a database**, and **a second always-on process for the queue worker**. Without
the worker, uploads parse and batches queue but no mail is ever delivered.

That rules out serverless platforms — Vercel, Netlify, Lambda — which have no
long-running process and an ephemeral filesystem.

The database can be either:

- **SQLite** — a single file, no server, no hosting cost. It needs a
  **persistent volume**; see [Free hosting with SQLite](#free-hosting-with-sqlite-flyio-render).
  This is the cheapest setup and works well for one worker.
- **MySQL** — a managed service, e.g. Railway's. Better if you expect several
  workers writing hard in parallel, or want managed backups.

The app supports both with no code change: the only two driver-specific SQL
expressions branch on `DB_CONNECTION` at runtime. The Docker image ships both
`pdo_mysql` and `pdo_sqlite`.

The Railway instructions below use MySQL; the same image runs on Render, Fly.io,
or any VPS with Docker.

---

## What ships in this repo

| File | Purpose |
|---|---|
| `Dockerfile` | PHP 8.2 + nginx + the extensions PhpSpreadsheet needs (`gd`, `intl`, `zip`), plus `pdo_mysql` and `pdo_sqlite` |
| `docker/entrypoint.sh` | One image, two roles — `web` and `worker` |
| `docker/nginx.conf` | Serves `public/`, 16 MB body limit, 120s FastCGI timeout |
| `docker/supervisord.conf` | Runs nginx and php-fpm together in the web container |
| `railway.json` | Build and healthcheck config |
| `Procfile` | Documents both process roles |

---

## Railway, step by step

### 1. Push the repo

Railway deploys from GitHub, so the code needs to be pushed first.

### 2. Create the project and database

1. [railway.app](https://railway.app) → **New Project** → **Deploy from GitHub repo**
2. Pick `smart-emailing`. Railway detects the `Dockerfile` and starts building.
3. In the same project: **New** → **Database** → **Add MySQL**.

Railway injects `MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER` and
`MYSQLPASSWORD` into the project. The web service maps them to Laravel's names
in the next step.

### 3. Generate an APP_KEY

Run this locally and copy the output:

```bash
php artisan key:generate --show
```

It prints something like `base64:xxxxxxxx…`. The app refuses to boot without
it, and the entrypoint fails loudly rather than serving 500s.

### 4. Set the web service variables

On the **web service** → **Variables**:

```
APP_NAME=Smart Emailing
APP_ENV=production
APP_KEY=base64:…            ← from step 3
APP_DEBUG=false
APP_URL=https://your-app.up.railway.app
APP_TIMEZONE=Asia/Kolkata

DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
FILESYSTEM_DISK=local

LOG_CHANNEL=stderr
```

The `${{MySQL.*}}` syntax is Railway's reference substitution — it wires the
database service to this one without copying credentials by hand.

**`APP_DEBUG=false` matters.** With it on, a stack trace on an error page can
expose environment variables, including database credentials.

`LOG_CHANNEL=stderr` sends logs to Railway's log view instead of a file inside
an ephemeral container.

### 5. Add the worker service

This is the step that is easy to skip and breaks everything.

1. **New** → **GitHub Repo** → the same repository
2. Settings → **Start Command**: `entrypoint worker`
3. Settings → turn **off** any healthcheck (a worker serves no HTTP)
4. Variables: **the same set as the web service**

The worker shares the image and the database. It runs
`queue:work --tries=3 --timeout=90 --max-time=3600`, restarting hourly so it
picks up new deploys and does not accumulate memory.

### 6. Expose the web service

Web service → **Settings** → **Networking** → **Generate Domain**.

Then set `APP_URL` to that domain and redeploy, so generated links and redirects
use it.

### 7. First login

Migrations run automatically on each web deploy. Seed the first admin once,
from the web service's shell (Railway → service → **Command**):

```bash
php artisan db:seed --force
```

That creates `admin@smart-emailing.test` / `password`.

**Change it immediately** — log in, or run:

```sql
UPDATE users SET email = 'you@yourdomain.com' WHERE is_admin = 1;
```

and set a new password through a fresh registration plus a manual promote, or
via tinker.

---

## Verifying the deploy

1. Open the domain — the login page should render with styles.
2. Log in, go to **SMTP**, add a profile, and use **Test**. If the test sends,
   credentials and outbound connectivity are both good.
3. Upload a small recipient file, compose, send.
4. Watch the **worker service logs** — you should see
   `App\Jobs\SendBulkEmailJob ... DONE` lines. If nothing appears there, the
   worker is not running, which is the one failure that looks like the app is
   silently broken.

---

## Important: uploaded files are ephemeral

`BatchImportService` stores the uploaded spreadsheet on local disk. On Railway
that disk is wiped on every deploy and restart.

This does not affect sending or reporting — every recipient row is parsed into
MySQL at upload time, and the report reads from there. The only consequence is
that **Delete Batch** cannot remove the original file after a restart. It fails
silently and the batch is still deleted.

If you need the original spreadsheets retained, attach a Railway volume mounted
at `/var/www/html/storage/app`, or switch `FILESYSTEM_DISK` to `s3` and add the
AWS credentials — the code needs no change for either.

---

## Outbound SMTP

Some hosts block outbound port 25. Railway does not block 587 or 465, which is
what real SMTP providers use. If your provider only offers 25, use a transactional
service instead — SendGrid, Mailgun, Postmark, or Gmail with an
[App Password](https://support.google.com/accounts/answer/185833) on port 587.

The SMTP profile lives in the database, so you can change it from the UI without
redeploying.

---

## Cost

Railway bills by usage. A small deployment of this app — web service, worker,
and MySQL — typically runs around **$5–10/month**. The worker is the main cost
driver since it runs continuously; it is also what makes the app work.

Dropping the managed database and using **SQLite on a small persistent volume**
removes the database line item entirely — see
[Free hosting with SQLite](#free-hosting-with-sqlite-flyio-render). You still
need the always-on worker process.

---

## Free hosting with SQLite (Fly.io, Render)

Fly.io and Render both offer a small persistent volume. Mounting one and putting
the SQLite file on it gives you a real database and a real queue worker without
paying for a managed MySQL.

### The one rule that matters

> **The SQLite file MUST live on a persistent volume.**
>
> A container's own filesystem is ephemeral: it is rebuilt from the image on
> every deploy, restart and crash. A SQLite file written there is **wiped**,
> silently, taking every batch and log with it. `DB_DATABASE` must point at a
> path *inside the mounted volume* — never at `database/database.sqlite` in the
> image, and never at `/tmp`.

### Fly.io

Create the volume once (1 GB is plenty — this app stores rows, not media):

```bash
fly volumes create smart_emailing_data --size 1
```

Mount it and define both processes in `fly.toml`:

```toml
[processes]
  web = "entrypoint web"
  worker = "entrypoint worker"

[[mounts]]
  source = "smart_emailing_data"
  destination = "/data"

[env]
  DB_CONNECTION = "sqlite"
  DB_DATABASE = "/data/database.sqlite"
```

Set the rest of the config as secrets (`fly secrets set APP_KEY=base64:… APP_URL=…`).

**One caveat:** a Fly volume attaches to a single machine, so the web process
and the worker must run on the **same machine** to share the file — or the
worker must be the only process that writes. If you scale to multiple machines,
move to MySQL or a hosted-SQLite service such as Turso.

### Render

Add a **Disk** to the service (Settings → Disks), mount path `/data`, 1 GB. Then
set:

```
DB_CONNECTION=sqlite
DB_DATABASE=/data/database.sqlite
```

Render disks attach to one instance, so the same single-writer caveat applies:
run the web service and the worker as one service with both processes, or keep
writes on one instance.

### Creating the file and migrating

The entrypoint runs migrations on deploy. SQLite needs the file to exist first;
create it once from the service shell:

```bash
touch /data/database.sqlite
php artisan migrate --force
php artisan db:seed --force      # first admin, once only
```

### WAL is enabled automatically

`AppServiceProvider` turns on WAL journalling, a 5s busy timeout and
`PRAGMA foreign_keys=ON` whenever the driver is SQLite. WAL is what lets the
queue worker write while web requests read — without it the two block each other
and you get "database is locked". `foreign_keys=ON` is also required: SQLite
does **not** enforce foreign keys by default, and without it deleting a batch
would leave its `email_logs` rows orphaned instead of cascading.

### Backups

A SQLite database is one file, so a backup is a file copy — but copy it with the
SQLite CLI rather than `cp`, which can catch a half-written WAL:

```bash
sqlite3 /data/database.sqlite ".backup '/data/backup-$(date +%F).sqlite'"
```

Managed MySQL gives you automated backups; with SQLite this is yours to schedule.

---

## Other platforms

**Render** — same Dockerfile. Create a Web Service and a Background Worker from
the same repo, with the worker's start command set to `entrypoint worker`. Add
a managed MySQL or point at an external one.

**Fly.io** — `fly launch` picks up the Dockerfile. Define two processes in
`fly.toml`:

```toml
[processes]
  web = "entrypoint web"
  worker = "entrypoint worker"
```

**A VPS** — `docker compose` with two services from this image, plus MySQL, and
a reverse proxy for TLS.

---

## Troubleshooting

**"FATAL: APP_KEY is not set."**
Step 3 was skipped. The entrypoint deliberately fails here rather than serving
broken pages.

**Pages load but have no styling.**
`APP_URL` does not match the real domain, so `asset()` generates wrong URLs. Fix
it and redeploy.

**Batches stay at "Processing" and nothing sends.**
The worker service is not running, or it has different database variables from
the web service. Check its logs.

**Excel export or upload throws a 500.**
A missing PHP extension — the Dockerfile installs `gd`, `intl` and `zip` for
exactly this reason. Confirm the build used this Dockerfile and not a detected
buildpack.

**Migrations fail on first deploy.**
The database variables are wrong or the MySQL service is still provisioning.
Check them, then redeploy.

**On SQLite: "database is locked".**
WAL or the busy timeout is not in effect — both are set by `AppServiceProvider`
for the SQLite driver. Check that the file is on a volume the process can write
to, and that you are not running several machines against one volume.

**On SQLite: every batch disappeared after a deploy.**
`DB_DATABASE` points at a path inside the container image instead of the mounted
volume, so it is recreated empty on each deploy. Point it at the volume.

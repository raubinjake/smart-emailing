# Deploying Smart Emailing for free

**Vercel (free) + Aiven MySQL (free) + cron-ping (free) = $0/month.**

Everything below was checked in September 2026. Free tiers move, so the dates
matter.

| Piece | Provider | Cost | Credit card? |
|---|---|---|---|
| App hosting | Vercel Hobby | Free | No |
| Database | Aiven for MySQL | Free, no time limit | No |
| Queue trigger | cron-job.org | Free | No |
| Email sending | Your own SMTP (Gmail, Brevo, …) | Free tier | No |

---

## The one thing to understand first

Vercel has no always-on process, so the queue is drained by **hitting a URL**.
The app already ships that route (`/api/queue`, secret-guarded).

On the **Hobby plan, Vercel's own cron only runs once per day** — and a
per-minute schedule does not merely run slowly, it **fails at deploy time**:

> *Hobby accounts are limited to daily cron jobs. This cron expression would
> run more than once per day.*

So `vercel.json` ships a daily schedule as a safety net, and a **free external
pinger** hits the same route every minute to do the real work. That is the
whole trick, and it costs nothing.

---

## 1. Free MySQL on Aiven

[Aiven's free MySQL](https://aiven.io/free-mysql-database) is genuinely free —
no card, no clock, 1 GB storage, 1 GB RAM. Plenty here: this app stores rows,
not media.

1. Sign up at [console.aiven.io](https://console.aiven.io)
2. **Create service** → **MySQL** → pick the **Free** plan
3. Choose a region near you (`aws-ap-south-1` for India)
4. Wait for it to turn green, then open the **Connection information** tab

Keep these — you need them in step 3:

```
Host      mysql-xxxx-yyyy.aivencloud.com
Port      12345          ← not 3306
User      avnadmin
Password  ………
Database  defaultdb
SSL       REQUIRED
```

> **One free service per type per account.** Fine for this.

---

## 2. Push and import

Push the repo to GitHub, then [vercel.com/new](https://vercel.com/new) →
import it. Leave the framework preset as **Other** — `vercel.json` already
declares the PHP runtime, routes and cron.

The first build will succeed; the app will not work until the variables below
are set.

---

## 3. Environment variables

Generate a key locally:

```bash
php artisan key:generate --show
```

And a cron secret:

```bash
openssl rand -hex 32
```

Vercel → **Settings** → **Environment Variables** → add to **Production**:

```
APP_NAME=Smart Emailing
APP_ENV=production
APP_KEY=base64:…                    ← from key:generate
APP_DEBUG=false
APP_URL=https://your-app.vercel.app
APP_TIMEZONE=Asia/Kolkata

DB_CONNECTION=mysql
DB_HOST=mysql-xxxx-yyyy.aivencloud.com
DB_PORT=12345
DB_DATABASE=defaultdb
DB_USERNAME=avnadmin
DB_PASSWORD=…
MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
FILESYSTEM_DISK=local

LOG_CHANNEL=stderr
CRON_SECRET=…                       ← from openssl
```

Three that matter more than they look:

- **`MYSQL_ATTR_SSL_CA`** — Aiven requires TLS. Without this the connection is
  refused outright.
- **`SESSION_DRIVER=database`** — file sessions live in `/tmp`, which is
  per-instance, so logins would drop at random.
- **`DB_CONNECTION=mysql`** — the app defaults to SQLite locally, and **SQLite
  cannot work on Vercel** (read-only filesystem, ephemeral `/tmp`).

Redeploy after saving.

---

## 4. Create the schema

Run the migrations from your own machine against the Aiven database:

```bash
DB_CONNECTION=mysql \
DB_HOST=mysql-xxxx-yyyy.aivencloud.com \
DB_PORT=12345 \
DB_DATABASE=defaultdb \
DB_USERNAME=avnadmin \
DB_PASSWORD='…' \
MYSQL_ATTR_SSL_CA=/etc/ssl/cert.pem \
php artisan migrate --force --seed
```

(On macOS the CA bundle is `/etc/ssl/cert.pem`; on Linux it is usually
`/etc/ssl/certs/ca-certificates.crt`.)

That creates the schema and the first admin —
`admin@smart-emailing.test` / `password`. **Change it as soon as you log in.**

---

## 5. The per-minute pinger — this is what makes sending work

Without this, batches queue and sit there until the daily cron.

1. Sign up at [cron-job.org](https://cron-job.org) (free, no card)
2. **Create cronjob**:
   - **URL**: `https://your-app.vercel.app/api/queue`
   - **Schedule**: every 1 minute
   - **Advanced → Headers**: add
     `Authorization: Bearer YOUR_CRON_SECRET`
3. Save and enable.

Check it works:

```bash
curl -H "Authorization: Bearer YOUR_CRON_SECRET" \
     https://your-app.vercel.app/api/queue
```

`{"ok":true,"ran_at":"…"}` means the queue drained. `401` means the secret does
not match.

> Any pinger works — UptimeRobot, GitHub Actions on a schedule, or a `curl` in
> your own crontab. It just has to send that header.

---

## 6. SMTP

Sending needs a mail server; the app does not provide one.

| Provider | Free allowance |
|---|---|
| **Brevo** | 300 emails/day |
| **Gmail** | ~500/day, needs an [App Password](https://support.google.com/accounts/answer/185833) |
| **Mailgun / SendGrid** | Limited free tiers |

Add it in the app under **SMTP** → **Add Profile**, then press **Test**. A
delivered test message proves credentials and connectivity in one step.

Gmail settings: host `smtp.gmail.com`, port `587`, encryption `TLS`, username
your address, password the App Password (not your account password).

---

## 7. Check it end to end

1. Open the URL — the login page should render with styles
2. Log in, add and test an SMTP profile
3. Upload a small recipient file, compose, send
4. Within a minute the report should start filling in

If it does not: Vercel → **Logs**, filter to `/api/queue`.

---

## What "free" costs you

**Sending starts within about a minute**, not instantly. The pinger runs every
minute and each run works for ~50 seconds.

**Large batches span several runs.** A 5,000-recipient batch takes several
minutes. Nothing is lost — jobs stay in the queue table between runs.

**Uploaded spreadsheets are not kept.** `/tmp` is ephemeral. This affects
nothing visible: every recipient row is parsed into MySQL at upload, and the
report reads from there.

**Cold starts.** The first request after idle takes a second or two.

**1 GB database.** Roughly a million log rows. Delete old batches if you
approach it.

---

## If you outgrow free

| | Cost | What you gain |
|---|---|---|
| **Vercel Pro** | $20/mo | Native per-minute cron, no external pinger |
| **Laravel Cloud Starter** | $5/mo, first month free | Real queue worker, instant sending, scale-to-zero |
| **Railway** | ~$5/mo | Real worker, managed MySQL — see [DEPLOYMENT.md](DEPLOYMENT.md) |
| **Oracle Cloud Always Free** | $0 (card for ID only) | A full 2 OCPU / 12 GB ARM VM; runs everything, needs server admin |

---

## Troubleshooting

**Deploy fails: "Hobby accounts are limited to daily cron jobs."**
Something set the cron back to a sub-daily schedule. `vercel.json` must keep
`0 3 * * *` on Hobby; per-minute work is the external pinger's job.

**"SQLSTATE[HY000] [2002]" or a TLS error.**
`MYSQL_ATTR_SSL_CA` is missing, or the port is wrong — Aiven does not use 3306.

**Logged in, then logged straight out.**
`SESSION_DRIVER` is not `database`.

**Pages render without CSS.**
`APP_URL` does not match the deployed domain.

**Batches stay at "Processing".**
The pinger is not running or the secret is wrong. Test with the `curl` in
step 5.

**500 on every page.**
Usually a missing `APP_KEY`. Check **Logs**; set `APP_DEBUG=true` briefly to
see the real error, then turn it back off — a debug trace exposes your database
credentials.

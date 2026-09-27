# Production deployment with Docker Compose

The stack in `docker-compose.yml` runs the whole system on one server.

| Service | Built from | Role |
|---|---|---|
| `app` | `backend/Dockerfile` (PHP 8.3 FPM) | Laravel API. Runs migrations and the reference seeders on start. |
| `queue` | same image | `php artisan queue:work`: WhatsApp sending (3–5 s spacing), exports. |
| `scheduler` | same image | `php artisan schedule:work`: sessions, reminders, honor board, weekly report. |
| `web` | `frontend/Dockerfile` (nginx) | The React build. Passes `/api`, `/media`, `/docs` and `/up` to `app` and serves `/storage`. |
| `whatsapp` | `whatsapp/Dockerfile` (Node + open-wa) | Optional (`--profile whatsapp`). WhatsApp Web session; replies go to the inbound webhook. |
| `mysql` / `pgsql` | `mysql:8.0` / `postgres:15` | Optional (`--profile mysql` or `--profile pgsql`). With neither, SQLite in the storage volume. |

Volumes: `storage` (uploads, photos, certificates, backups, logs, the SQLite file), `mysql-data`, `pgsql-data`, `whatsapp-session`.

## 1. First install

```bash
git clone <repo> ahl-alquran && cd ahl-alquran
cp .env.production.example .env
# edit .env: APP_URL, one database block, passwords, OPENWA_API_KEY, WHATSAPP_INBOUND_SECRET
docker compose --profile mysql build
docker compose --profile mysql run --rm app php artisan key:generate --show   # paste the key into APP_KEY
docker compose --profile mysql --profile whatsapp up -d
docker compose logs -f app          # wait for the migrations and the reference seeders
docker compose exec app php artisan users:create-admin +9733XXXXXXX "مدير النظام" --email=admin@example.bh   # asks for the password
```

Use `--profile pgsql` instead of `--profile mysql` for PostgreSQL. For SQLite use no database profile and set
`DB_CONNECTION=sqlite` and `DB_DATABASE=/var/www/html/storage/database.sqlite`.

Production gets reference data only (roles, permissions, settings, templates, surahs, badges). Never run `DemoSeeder` there;
it refuses to run when `APP_ENV=production`.

## 2. HTTPS

`web` listens on `HTTP_PORT` (80 by default). Put a TLS proxy in front, for example Caddy on the host:

```
quran.example.bh {
    reverse_proxy 127.0.0.1:8080
}
```

with `HTTP_PORT=8080` in `.env`. `TRUSTED_PROXIES=*` makes Laravel honour `X-Forwarded-Proto`, so signed links use `https`.
Set `SESSION_SECURE_COOKIE=true` and `SANCTUM_STATEFUL_DOMAINS` to the public host name.

## 3. WhatsApp

- **open-wa** (default): start with `--profile whatsapp`, open Messages → WhatsApp and scan the QR code
  (WhatsApp → Linked devices). The session survives restarts in the `whatsapp-session` volume. The bridge forwards
  replies to `/api/public/whatsapp/inbound` with `WHATSAPP_INBOUND_SECRET`.
- **Cloud API**: set `WHATSAPP_PROVIDER=cloud`, `WHATSAPP_CLOUD_TOKEN`, `WHATSAPP_CLOUD_PHONE_NUMBER_ID` and `WHATSAPP_CLOUD_APP_SECRET`.
  In Meta, point the webhook to `https://<host>/api/public/whatsapp/inbound` with `WHATSAPP_INBOUND_SECRET` as the verify token.
- **log**: nothing is sent and messages appear in Messages → Log. Useful for a trial.

## 4. Honor board TV screen

Set Settings → Honor board → display key. Then open this on the centre screen in the browser's kiosk mode:
`https://<host>/display/honor?key=<key>&gender=male` (or `female`).

## 5. Updates

```bash
git pull
docker compose --profile mysql build
docker compose --profile mysql --profile whatsapp up -d     # app migrates on start
```

Set `RUN_MIGRATIONS=false` for `app` to run `docker compose exec app php artisan migrate --force` by hand instead.

## 6. Backups

```bash
docker compose exec app php artisan db:backup                       # into storage/app/backups (storage volume)
docker compose exec app php artisan db:restore storage/app/backups/<file> --force
docker run --rm -v ahl-alquran_storage:/s -v "$PWD":/b alpine tar czf /b/storage-$(date +%F).tgz -C /s .   # uploads and photos
```

Run the first command daily from the host's cron and copy the archive off the server.

## 7. Health and logs

- `https://<host>/up` answers 200 when Laravel is up.
- `docker compose ps` and `docker compose logs -f app queue scheduler whatsapp`.
- Failed WhatsApp messages: Messages → Log, filter Failed, then "Resend failed".

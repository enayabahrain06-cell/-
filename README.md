# نظام أهل القرآن — Ahl Al-Quran System

نظام إدارة حلقات تحفيظ القرآن الكريم لهيئة التعليم الديني — سار، البحرين.
Quran circles (halaqat) management system for the Religious Education Authority — Sar, Bahrain.

| Part | Path | Stack |
|------|------|-------|
| API | `backend/` | Laravel 12 (PHP 8.2+), Sanctum, spatie/permission, dompdf, maatwebsite/excel, Intervention Image, Scribe, Pest |
| Web app | `frontend/` | React 19, Vite, TypeScript, TanStack Query, RHF + Zod, Tailwind (RTL/LTR), Recharts, i18next |
| WhatsApp bridge | `whatsapp/` | Node + open-wa/wa-automate |
| Docs | `docs/` | ERD, migrations, work plan |

---

## العربية

### التشغيل بدون Docker (SQLite — للتطوير)

```bash
cd backend
cp .env.example .env            # DB_CONNECTION=sqlite جاهز افتراضياً
composer install
php artisan key:generate
touch database/database.sqlite  # على ويندوز: type nul > database\database.sqlite
php artisan migrate --seed
php artisan serve               # http://localhost:8000  — التوثيق على /docs
php artisan queue:work          # في نافذة أخرى
php artisan schedule:work       # في نافذة أخرى (بديل cron محلياً)

cd ../frontend
cp .env.example .env            # VITE_API_PROXY_TARGET = عنوان الخادم
npm install && npm run dev      # http://localhost:5173  ← صفحة الدخول
```

إذا كان المنفذ 8000 أو 5173 مستخدماً: `php artisan serve --port=8010` وعدّل `VITE_API_PROXY_TARGET`، و `npm run dev -- --port 5180`.

### التبديل إلى MySQL أو PostgreSQL

غيّر فقط قيم `DB_*` في `backend/.env` (الأمثلة موجودة في `.env.example`) ثم:

```bash
php artisan migrate --seed
```

لا يوجد أي تغيير في الكود. كل الجداول تُنشأ عبر Schema Builder فقط، ولا توجد أنواع أعمدة خاصة بمحرك معيّن.

### نقل البيانات من SQLite إلى MySQL / PostgreSQL

```bash
# 1) في .env اضبط TRANSFER_DB_* على قاعدة البيانات الجديدة (mysql أو pgsql)
# 2) أنشئ الجداول في الهدف
php artisan migrate --database=transfer_target --force
# 3) انسخ كل الجداول على دفعات
php artisan db:transfer --from=sqlite --to=transfer_target --truncate
# 4) بدّل DB_CONNECTION في .env إلى المحرك الجديد
```

### النسخ الاحتياطي والاستعادة

```bash
php artisan db:backup                        # mysqldump / pg_dump / نسخ ملف SQLite حسب المحرك
php artisan db:restore storage/app/backups/<file> --force
```

### الجدولة (cron واحد)

```
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

### الاختبارات

```bash
cd backend && php artisan test          # SQLite في الذاكرة
```
على MySQL و PostgreSQL تُشغَّل الاختبارات تلقائياً في GitHub Actions (`.github/workflows/ci.yml`) عبر حاويات خدمية. محلياً يمكن تشغيلها بضبط `DB_*` ثم `php artisan test`.

---

## English

### Run without Docker (SQLite — development)

```bash
cd backend
cp .env.example .env            # DB_CONNECTION=sqlite is the default
composer install
php artisan key:generate
touch database/database.sqlite  # Windows: type nul > database\database.sqlite
php artisan migrate --seed
php artisan serve               # http://localhost:8000 — API docs at /docs
php artisan queue:work          # second terminal
php artisan schedule:work       # third terminal (local stand-in for cron)

cd ../frontend
cp .env.example .env            # VITE_API_PROXY_TARGET = backend URL
npm install && npm run dev      # http://localhost:5173  (login page)
```

If port 8000 or 5173 is already taken: run `php artisan serve --port=8010`, set `VITE_API_PROXY_TARGET` to match, and run `npm run dev -- --port 5180`.

### Switch to MySQL 8 or PostgreSQL 15

Change only the `DB_*` values in `backend/.env` (commented examples are in `.env.example`), then:

```bash
php artisan migrate --seed
```

No code changes. All schema goes through the Schema builder, no driver-specific column types or SQL functions are used, and reports aggregate with COUNT/SUM/AVG/MIN/MAX and group dates in PHP.

### One-command Docker setup

```bash
docker compose --profile mysql up -d     # app + nginx + queue + scheduler + whatsapp + MySQL 8
docker compose --profile pgsql up -d     # same, with PostgreSQL 15
docker compose up -d                     # app only, SQLite inside the container
```
(docker-compose ships in Phase 4.)

### Migrate data from SQLite to MySQL / PostgreSQL

```bash
# 1) In .env, point TRANSFER_DB_* at the new database (mysql or pgsql)
# 2) Create the schema on the target
php artisan migrate --database=transfer_target --force
# 3) Copy every table in chunks through the Query Builder
php artisan db:transfer --from=sqlite --to=transfer_target --truncate
# 4) Switch DB_CONNECTION in .env to the new driver
```

### Backup and restore

```bash
php artisan db:backup            # mysqldump / pg_dump / SQLite file copy, chosen by driver
php artisan db:restore storage/app/backups/<file> --force
```

Binary paths can be overridden with `MYSQLDUMP_PATH`, `MYSQL_PATH`, `PG_DUMP_PATH`, `PSQL_PATH`.

### Scheduler (single cron)

```
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

### Tests

```bash
cd backend && php artisan test   # SQLite in memory
```

MySQL and PostgreSQL runs happen in GitHub Actions (`.github/workflows/ci.yml`) with service containers. To run locally against either, set `DB_*` in `.env` (or export them) and run `php artisan test`.

### Demo accounts (after `--seed`, from Phase 2.13)

| Role | Phone | Login |
|------|-------|-------|
| Super Admin | +973 3600 0001 | password `password` |
| Supervisor | +973 3600 0002 | password `password` |
| Teacher | +973 3600 0003 | password `password` |
| Student | +973 3600 0004 | WhatsApp OTP (logged when `WHATSAPP_PROVIDER=log`) |
| Guardian | +973 3600 0005 | WhatsApp OTP |

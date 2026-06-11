# Team Status

A small Laravel + Vue application for daily team work status tracking.

## Features

- Admin login with a seeded admin account.
- Admin member management with active/inactive accounts.
- Team member login.
- Team members can add unlimited tasks for the current day.
- Team members can edit/delete only current-day tasks.
- Admin dashboard grouped by member with filters for date, member, and status.
- SQLite persistence, designed to run in Docker behind an external nginx reverse proxy.

## Local Development

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

Default seeded admin:

```text
Email: admin@example.com
Password: password
```

Override these values in `.env`:

```text
ADMIN_NAME=Admin
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=password
APP_TIMEZONE=Asia/Kolkata
```

## Docker

Build and run:

```bash
docker compose up --build
```

The app listens on `APP_PORT`, defaulting to `8000`, and stores SQLite data in the `team-status-data` Docker volume at `/data/database.sqlite`.

For production, set at least:

```text
APP_URL=https://your-domain.example
APP_KEY=base64:...
ADMIN_EMAIL=admin@your-domain.example
ADMIN_PASSWORD=change-this-password
```

If `APP_KEY` is not provided, the container generates one for the running process. Providing a stable key is recommended for persistent sessions and encrypted values.

## Tests

```bash
php artisan test
npm run build
```

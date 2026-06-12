# Deployment Steps

## Production deploy

1. Pull the latest code on production.

```bash
git pull
```

2. Configure the shared key for third-party API access in the production `.env`.

```env
THIRD_PARTY_API_KEY=replace-with-a-long-random-secret
```

The third-party application must send this value in the `X-API-Key` header.

3. Build the app image.

```bash
docker compose build app
```

4. Start or recreate the stack.

```bash
docker compose up -d db
docker compose up -d app
```

5. Confirm containers are healthy.

```bash
docker compose ps
```

Expected:

- `db` is healthy.
- `app` is running.

6. Run migrations inside the app container.

```bash
docker compose exec app php artisan migrate --force
```

7. Import the Vityarthi CSV data.

```bash
docker compose exec app php artisan db:seed --class=VityarthiCsvImportSeeder --force
```

Expected output:

```text
Imported 8 Vityarthi users and 698 tasks.
```

8. Verify imported counts.

```bash
docker compose exec app php artisan tinker --execute='echo App\Models\User::where("role", "member")->count()." users, ".App\Models\DailyTask::count()." tasks".PHP_EOL;'
```

Expected output:

```text
8 users, 698 tasks
```

9. Verify task status distribution.

```bash
docker compose exec app php artisan tinker --execute='echo App\Models\DailyTask::query()->selectRaw("status, count(*) as total")->groupBy("status")->orderBy("status")->get()->map(fn($row) => $row->status.":".$row->total)->implode(", ").PHP_EOL;'
```

Expected output:

```text
blocked:1, done:328, in_progress:355, planned:14
```

10. Open the app and confirm login/dashboard behavior.

```text
http://your-production-domain-or-ip
```

## Seeder note

`VityarthiCsvImportSeeder` is rerunnable. It updates the CSV users and replaces tasks for those users, so it does not duplicate imported tasks.

## Third-party API

Authentication header:

```http
X-API-Key: replace-with-the-production-third-party-api-key
```

List active team members:

```bash
curl -H "X-API-Key: $THIRD_PARTY_API_KEY" \
  https://your-production-domain-or-ip/api/team-members
```

List tasks for a member and date:

```bash
curl -H "X-API-Key: $THIRD_PARTY_API_KEY" \
  "https://your-production-domain-or-ip/api/tasks?member_id=1&date=2026-06-11"
```

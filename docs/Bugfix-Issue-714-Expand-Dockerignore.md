# Bugfix Issue #714: Expand .dockerignore (critically incomplete)

## Summary
The `.dockerignore` only excluded 4 entries, causing ~184MB of unnecessary files to be sent to Docker context on every build.

## Root Cause
`.dockerignore:1-4` only excluded `.env`, `.env.example`, `docker-compose.yml`, `Dockerfile`. `Dockerfile:20` uses `COPY . .` which copies everything not excluded.

## Impact
- `.git/` (114M) — git history
- `vendor/` (28M) — PHP dependencies (reinstallable)
- `tmp/` (29M) — temporary files
- `docs/` (11M) — documentation
- `composer.phar` (2.2M) — PHP archive

Total: ~184MB sent to Docker context unnecessarily.

## Fix
Expanded `.dockerignore` to 17 entries:
```
.env
.env.example
docker-compose.yml
Dockerfile
vendor/
node_modules/
tl200/
.git/
.gitignore
logs/
upload_area/
*.phar
tmp/
composer.phar
php.ini
.squash.yml
docs/
```

## Verification
- All necessary application files (`api/`, `gui/`, `config.inc.php`) remain included
- Excluded paths verified present in repository
- `docker build` should complete successfully with smaller context

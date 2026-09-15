#!/bin/sh
set -eu
umask 077
cd /var/www/html/clinicaloreto
destination="/var/backups/clinicaloreto/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$destination"
docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$destination/database.dump"
docker compose exec -T app tar -czf - -C /var/www/html storage > "$destination/storage.tar.gz"
cp .env "$destination/environment"
git diff --binary > "$destination/deployment.patch"
tar -czf "$destination/deployment-files.tar.gz" DEPLOYMENT.md deploy frontend/src/vite-env.d.ts
docker compose exec -T db pg_restore --list < "$destination/database.dump" > "$destination/database-contents.txt"
tar -tzf "$destination/storage.tar.gz" > "$destination/storage-contents.txt"
printf 'Backup created: %s\n' "$destination"

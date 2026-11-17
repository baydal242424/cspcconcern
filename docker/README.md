# Running the system in Docker

Two containers: `cspcconcern-app` (nginx + PHP-FPM) and `cspcconcern-db`
(MySQL). They run beside XAMPP rather than instead of it — different port,
different database, different env file — so starting them changes nothing
about the local setup.

Docker Desktop has to be open first. Every command below is run from the
project folder.

## Setting up, once

```sh
cp .env.docker.example .env.docker
```

Then fill in `.env.docker`: `APP_KEY`, the mail settings and the Google
client id and secret. That file is gitignored and must stay that way — it
holds the same secrets as `.env`.

Google sign-in is the only way into the application, so the address the
containers run on has to be registered too. In the Google Cloud console,
add this to the OAuth client's **Authorized redirect URIs**:

```
http://localhost:8002/auth/google/callback
```

Without it, the sign-in page loads and the sign-in fails.

## Every day

```sh
# start
docker compose --env-file .env.docker up -d

# open
# http://localhost:8002

# stop (the data stays)
docker compose --env-file .env.docker stop

# stop and remove the containers (the data still stays: it is in volumes)
docker compose --env-file .env.docker down
```

`--env-file .env.docker` matters on every command. Without it Compose reads
`.env` instead, and the database container would be built with the local
database name while the application looks for the other one.

## After changing code

The application is copied into the image at build time, so an edit on the
host is not live in the container.

```sh
docker compose --env-file .env.docker up -d --build
```

Migrations run on every boot, so a new migration needs nothing else.

## Looking inside

```sh
# what is running
docker compose --env-file .env.docker ps

# the application log, as it happens
docker logs -f cspcconcern-app

# an artisan command
docker exec cspcconcern-app php artisan migrate:status

# a shell in the container
docker exec -it cspcconcern-app sh

# the database
docker exec -it cspcconcern-db mysql -ucspcconcern -psecret cspcconcern
```

A database client on the host (Workbench, phpMyAdmin) reaches it at
`127.0.0.1:3307` — not 3306, which XAMPP holds.

## Copying the local database in

The containers start with an empty database. To work with the real data:

```sh
# out of XAMPP
"C:/xampp/mysql/bin/mysqldump.exe" -h 127.0.0.1 -u root \
    --single-transaction --default-character-set=utf8mb4 \
    --add-drop-table --no-tablespaces \
    mockup_design12 > dump.sql

# into the container
docker exec -i cspcconcern-db \
    mysql -uroot -proot --default-character-set=utf8mb4 cspcconcern < dump.sql

# the uploaded evidence as well, or attachments 404
docker cp storage/app/. cspcconcern-app:/var/www/html/storage/app/
docker exec cspcconcern-app chown -R www-data:www-data storage/app
```

Nothing here writes to the XAMPP database; it is read and copied.

## Starting the data over

```sh
docker compose --env-file .env.docker down -v
```

`-v` removes the volumes, which is the uploaded evidence and the whole
database. There is no undo, and it does not touch XAMPP.

## When something is wrong

**The app container restarts over and over.** `docker logs cspcconcern-app`
prints why; it is usually the last line before the stack trace.

**"Docker Desktop is unable to start."** Usually WSL. `wsl --update`, then
quit Docker Desktop from the tray and open it again.

**Port 8002 is taken.** Change the left-hand number in `docker-compose.yml`
under `ports`, and `APP_URL` with it.

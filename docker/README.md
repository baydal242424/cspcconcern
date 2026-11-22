# Running the system in Docker

Two containers: `cspcconcern-app` (nginx + PHP-FPM) and `cspcconcern-db`
(MySQL). They run beside XAMPP rather than instead of it — different port,
different database, different env file — so starting them changes nothing
about the local setup.

Docker Desktop has to be open first. Every command below is run from the
project folder.

## Setting up, once

Nothing to copy, and nothing to edit but `.env`.

The containers read the same `.env` the rest of the project does — the
key, the mail password, the Google client — and everything they do
differently is in the `DOCKER_` block at the bottom of that same file:
the port, the database name and password, the address. `docker-compose.yml`
reads those and should not need opening.

```sh
# change the port the containers answer on
DOCKER_APP_PORT=8080
DOCKER_APP_URL=http://localhost:8080
```

That is the whole reason it works: `.env` says `DB_HOST=127.0.0.1` for
XAMPP, the compose file says `db`, and the container gets `db` because
`environment:` overrides `env_file:`.

The `DOCKER_` prefix is not decoration. Named `DB_DATABASE`, those
settings would collide with the XAMPP ones in the same file — Compose
substitutes from `.env` too — and the container would be handed the
local database name, which does not exist inside it.

There used to be a second file, `.env.docker`, and keeping the two in step
was a job nobody was doing: a Google secret rotated in one and not the
other meant sign-in worked on 8001 and failed on 8002, for no visible
reason.

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
docker compose up -d

# open
# http://localhost:8002

# stop (the data stays)
docker compose stop

# stop and remove the containers (the data still stays: it is in volumes)
docker compose down
```

No `--env-file` on any of them any more. Compose reads `.env` by itself,
and `docker-compose.yml` overrides the handful of values the containers
need differently.

## After changing code

The application is copied into the image at build time, so an edit on the
host is not live in the container.

```sh
docker compose up -d --build
```

Migrations run on every boot, so a new migration needs nothing else.

## Looking inside

```sh
# what is running
docker compose ps

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
docker compose down -v
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

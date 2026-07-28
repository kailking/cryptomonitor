# Permission database SQL rehearsal

## Result

The permission schema SQL rehearsal passed in a disposable local MySQL 8.0.24
container on 2026-07-22. The production database was read-only throughout and
remained at 23 tables, 91 users, and zero permission tables before and after
the rehearsal. This record contains no SSH, database, proxy, or provider
credential values and no production user rows.

This result verifies the SQL against the immutable Task 1 snapshot. It does
not authorize a production push or activation.

## Bound inputs

- Release ID: `20260720134425`
- Backend commit: `aff84b2e8664f3262e63dd144406670a60fea522`
- Backup: the approved legacy `database-full.sql.gz` from Task 1
- Backup SHA-256:
  `b81571a03f589feb9d958de609ef7730a79710ef8db6dde8c28b41a95f937150`
- `2026-07-20-01-create-user-permissions.sql` SHA-256:
  `fb8ec071c88646efcf52a243905d10419d952fd505695df1057a83fd147bf062`
- `2026-07-20-02-seed-user-permissions.sql` SHA-256:
  `d75ca454ab50ab06b18164be1a6c70d133481466dbe4cf3860750b2dfc019e35`
- `2026-07-20-99-drop-user-permissions.sql` SHA-256:
  `85c5de2b230347fe42f6dd102aecc0c548832effa69ad954c6b41b9f75bf4aac`

The backend worktree was clean at the bound commit before execution. The three
local SQL hashes were rechecked immediately before use. Their remote copies
and three-line checksum manifest also passed `sha256sum -c`. The remote
`sql-rehearsal` directory is `root:root 0700`; its SQL files, checksum manifest,
and credential-free result log are `root:root 0600`.

The older backup format was accepted explicitly: its independent checksum had
a single safe path to the archive, `sha256sum -c` and `gzip -t` passed, its
unique dump header identified `tool`, and the archive contained the expected
`users` schema. The existing backup and checksum were not modified or
repackaged.

## Production administration gate and approved substitution

The original production-host path stopped before schema creation. The
application database account received MySQL error 1044 when asked to create
the isolated rehearsal schema. The root-only aaPanel administration value
failed both TCP and socket authentication with MySQL error 1045; no usable
root defaults or login path was present. No production dump restore or
permission SQL ran during those attempts, and the proposed temporary schema
never existed.

The user then authorized a local isolated rehearsal followed by a later,
separately gated server push. Controller correction fixed the two distinct
baselines:

- immutable Task 1 snapshot: 23 tables, 87 users, zero permission tables;
- live production: 23 tables, 91 users, zero permission tables.

The four users created after the snapshot were not synthesized into the
snapshot.

## Isolation and sanitized procedure

Only the already cached image `mysql:8.0.24` was used. Its image ID was
`sha256:04ee7141256e83797ea4a84a4d31b1f1bc10111c8d1bc1879d52729ccd19e20a`.
The successful container was named
`tool-permission-rehearsal-20260720134425` and had ID
`3fb2242ab1179e94483696f131dc089798cbf96d5d9f90a49931e89bf30322d0`.

Before every container database command, the controller verified the exact
container ID and name, image tag and ID, running state, `--network none`,
automatic removal, zero published port bindings, a tmpfs MySQL data directory,
and zero bind or volume mounts. The container used an empty root credential
only inside that unnetworked disposable instance.

Sanitized command templates were:

```text
docker image inspect mysql:8.0.24
docker run --rm --pull=never --network none --tmpfs /var/lib/mysql:... \
  --name tool-permission-rehearsal-20260720134425 ... mysql:8.0.24
ssh <approved-root-target> "gzip -dc -- <verified-root-backup>" \
  | docker exec -i <verified-container> mysql -uroot tool
docker exec -i <verified-container> mysql -uroot tool < <committed-SQL>
docker rm -f <identity-verified-container>
```

The server decompressed the verified archive and streamed SQL directly over
SSH to the container. No local dump file, named volume, persistent database
directory, published port, or external container network was created. No SQL
content or credentials were printed.

Initial readiness testing exposed a race: `mysqladmin ping` could succeed
before the image finished creating `tool`. That preflight stream failed with
“unknown database” before importing data. The container was identity-checked
and removed. Subsequent orchestration-only readiness retries also removed
their containers in `finally`. The successful run required a read-only query
proving that `tool` existed before starting the stream. All retry and final
containers were removed, and no persistent mount was used.

## Verified counts

The SSH stream restore completed at `2026-07-22T01:03:38Z` with this pre-SQL
state:

- tables: `23`
- users: `87`
- permission tables: `0`
- `is_admin = 1` users: `3`
- user ID `31` rows: `1`
- expected `users` columns (`id`, `account`, `is_admin`): `3`

After the create SQL, the table count was `25`.

After the first seed:

- grants: `37`
- audit rows: `37`
- `quotation.profit.view` grants: `0`
- ID `31` `permissions.manage` grants: `1`
- per-user grant counts: `1:12`, `3:12`, `31:13`

After the second seed, every value was unchanged, including the exact per-user
counts. The second seed was therefore idempotent.

Immediately before reverse SQL there were 25 tables and 87 users. After the
reviewed `99` SQL there were 23 tables, 87 users, and zero permission tables,
matching the immutable snapshot baseline.

## Cleanup and production proof

The successful container reported:

- network mode: `none`
- published port bindings: `0`
- persistent mounts: `0`
- MySQL datadir on tmpfs: yes
- automatic removal: yes

After cleanup, the matching container count was `0` and the Docker volume
inventory was unchanged. No local database dump exists. All ephemeral local
MySQL defaults and SSH AskPass helpers were removed after verification.

The live production checks were:

| Check | Before | After |
| --- | ---: | ---: |
| tables | 23 | 23 |
| users | 91 | 91 |
| permission tables | 0 | 0 |
| matching rehearsal schema | 0 | 0 |

The post-check completed at `2026-07-22T01:04:22Z`. The remote checksum still
passed, its credential-free log remained `root:root 0600`, and no ephemeral
MySQL defaults file remained.

## Open release blockers

- Production push and activation have not been authorized by this rehearsal.
- Production schema DDL still needs a separately approved, working database
  administration mechanism and the normal release gates.
- Provider-side OKX credential rotation and coordinated Git-history cleanup
  remain open release blockers. This rehearsal did not access OKX.

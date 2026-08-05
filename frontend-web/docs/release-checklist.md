# Frontend release checklist

## Build and artifact identity

- [ ] Confirm `git status --porcelain` is empty and record the release commit SHA.
- [ ] Back up the current `/nweweb/` and `/web/` directories and record their
      hashes before replacing files.
- [ ] Clean only `dist/web`; do not remove or modify `dist/web89` or any legacy
      artifact directory.
- [ ] Run `npm run test:ci` successfully.
- [ ] Run `npm run build:web` exactly once. The only new frontend artifact is
      `dist/web`.
- [ ] Confirm `dist/web/build-meta.json` contains variant `web`, the recorded
      release commit SHA, and a parseable ISO build time.
- [ ] Generate and retain a SHA/hash manifest for every file in `dist/web`.

## Staged release

- [ ] Deploy the hashed `dist/web` artifact to `/nweweb/` first.
- [ ] Complete functional acceptance on `/nweweb/`, including login, route and
      action permissions for representative users, quotation profit/address
      visibility, polling, navigation, K-line dialog cleanup, and browser console
      checks.
- [ ] After acceptance, copy the exact same artifact bytes covered by the saved
      hash manifest to `/web/`. Do not rebuild between `/nweweb/` and `/web/`.
- [ ] Recompute the deployed hashes for `/web/` and verify they match both the
      manifest and the accepted `/nweweb/` bytes.
- [ ] Leave `/web89/` and `/nweweb89/` unchanged throughout this release.

## Rollback and eventual legacy cleanup

- [ ] If acceptance or production verification fails, restore `/nweweb/` or
      `/web/` from its recorded backup, verify the restored hashes, and rerun the
      affected checks.
- [ ] Do not redirect `/web89/` or `/nweweb89/`, and do not assign a fixed deletion
      date to either directory.
- [ ] Only after every user has been migrated may an operator perform a separate
      legacy cleanup: back up `/web89/` and `/nweweb89/`, verify the backups and
      hashes, then manually delete the legacy directories. That cleanup requires
      its own authorization and rollback from the verified backups.

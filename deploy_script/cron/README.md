# DB backup cadence (prod `alotr15q_prod`)

## One-time setup (FileZilla + panel, 5 min)
1. Upload `db-backup.php` to `/home/alotr15q/cron/db-backup.php` — OUTSIDE `api/` so deploys never wipe it. No secrets inside: it reads DB creds from `api/.env` at runtime.
2. Panel → Cron Jobs → add: `php /home/alotr15q/cron/db-backup.php >> /home/alotr15q/cron/backup.log 2>&1`
   - Weekly off-season; daily during exams/finals. Dumps land in `/home/alotr15q/backups/` (also outside `api/`).
3. Run once manually (panel "Run now" or SSH `php ...`), then check `backup.log` ends with `OK quran_prod-<ts>.sql (... tables, 11 views)` and the file exists.

## What it does
- Pure-PHP dump via PDO (no `exec`/`mysqldump` dependency — often disabled on shared). Tables with `DROP+CREATE+INSERTs` (500-row batches), views as `DROP+CREATE`, `utf8mb4`.
- Verifies before keeping (size + `CREATE TABLE` count); deletes the dump on failure.
- Retention: keeps newest 4, prunes older. One dump ≈ tens of KB at current data size.
- CLI-only guard: returns 403 if ever hit via web (it's above docroot anyway).

## Restore
1. Panel → phpMyAdmin → `alotr15q_prod` → Import → choose newest `quran_prod-*.sql` (download via FileZilla first), charset `utf8mb4`.
2. Verify: `SELECT COUNT(*) FROM session_scores;` → expect 168+ (grows with use); spot-check Arabic: `SELECT HEX(name_ar) FROM surahs WHERE id=2;` → must start `D8A7...`, not `3F`.

## Panel checks done 2026-10-01 (you, in cPanel)
- [ ] MultiPHP Manager → `api.alotrojah.ma` = PHP 8.4 (Laravel 12 + sodium/JWT requirement; remote probes can't see the version — only panel shows it)
- [ ] `mod_rewrite` on for subdomain — verified remotely: `/api/v1/health` → 200 with envelope (extensionless routing works)
- [ ] App boots (134ms `/up`), docs gated (`/docs/api.json` → 403)

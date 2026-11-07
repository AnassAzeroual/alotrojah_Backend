# SQL migrations

This backend uses a minimal **up-only** migrator.

## Naming

```text
YYYYMMDDHHMMSS_short_description.sql
```

Example:

```text
20261105120000_add_appearance_pattern_tint.sql
```

Files run in filename order. Never rename or edit a migration after it has been applied: the runner stores its SHA-256 checksum and refuses a changed file.

## Rules

Automatic migrations are **additive only**. The safety policy rejects the whole file before executing anything if it contains data deletion or destructive schema changes.

Allowed automatically:

- `CREATE TABLE ...`
- `CREATE [UNIQUE] INDEX ...`
- `ALTER TABLE ... ADD COLUMN|INDEX|KEY|CONSTRAINT|PRIMARY KEY|UNIQUE|FOREIGN KEY ...`

Rejected automatically:

- `DROP`, `TRUNCATE`, `DELETE`, `UPDATE`, `INSERT`, `REPLACE`, `RENAME`
- `ALTER TABLE ... DROP|MODIFY|CHANGE ...`
- Comma-separated `ALTER TABLE ... ADD ..., DROP ...`

Destructive changes must be reviewed and applied manually through phpMyAdmin after a database backup.

Other rules:

- Keep each migration focused; simple MySQL statements separated by `;` are supported.
- Do not use `DELIMITER` blocks (stored procedures/triggers).
- DDL is not transactional in MySQL. A failed file is left unapplied, but earlier statements in that file may already have run.
- Back up the database before any non-trivial migration; the deploy backup covers files, not the database.
- Prefer idempotent SQL where possible.

## Run

Local/server CLI:

```bash
php deploy_script/migrate.php
php deploy_script/migrate.php --status
```

Deploy workflow uses:

```text
deploy.php?action=migrate
```

which applies pending migrations, then the idempotent first-admin seed, then self-deletes.

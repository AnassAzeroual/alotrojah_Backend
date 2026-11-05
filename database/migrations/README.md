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

- Keep each migration focused; simple MySQL statements separated by `;` are supported.
- Do not use `DELIMITER` blocks (stored procedures/triggers).
- DDL is not transactional in MySQL. A failed file is left unapplied, but earlier statements in that file may already have run.
- Back up the database before destructive changes; the deploy backup covers files, not the database.
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

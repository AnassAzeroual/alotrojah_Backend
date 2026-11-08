# Review workspace — production migration checklist

Do not import the demo SQL dump into production.

## 1. Backup

- Take a full MySQL backup of the production database before applying anything.
- Keep the pre-deploy file backup produced by `deploy_script/deploy.php`.

## 2. Production patch SQL

Production is FTP + phpMyAdmin only. Do **not** run the local migrate runner,
SSH, or artisan against production.

Open and execute, in phpMyAdmin:

```text
docs/production-sql/20261108_review_workspace.sql
```

The patch is idempotent: it creates `session_notes` and `group_teachers` only
if missing, adds `groups.schedule_days` through an `information_schema` guard,
backfills the teacher pivot, copies legacy Murajaa reviews, and recomputes
term/season totals. It is safe to run twice.

Before running it, verify there is **one canonical Murajaa module**:

```sql
SELECT id, code, center_id FROM scoring_modules WHERE code = 'murajaa';
```

The patch uses the lowest `id`. If production has duplicate Murajaa module
rows, clean the duplicate module/scores manually first rather than allowing
double-counting.

## 3. Existing teacher memberships

`groups.teacher_id` remains the lead teacher. Backfill the pivot once so the
current lead teacher keeps access:

```sql
INSERT IGNORE INTO group_teachers (group_id, user_id, role)
SELECT g.id, g.teacher_id,
       CASE WHEN u.teacher_type IN ('hifz','murajaa','both')
            THEN u.teacher_type ELSE 'both' END
FROM groups g
JOIN users u ON u.id = g.teacher_id
WHERE g.teacher_id IS NOT NULL;
```

Then assign Murajaa co-teachers from the Group form or with explicit SQL.

## 4. Legacy Murajaa backfill

Sections 5–7 of the production patch perform this automatically:

- copy each legacy cycle score to its existing anchor session
- skip scores that already exist
- recompute `term_results.murajaa_total`
- recompute `season_results.murajaa_total` as the average of scored term averages

The local PHP script remains available for dev/shell environments with
`deploy_script/backfill-murajaa-scores.php`; it is not the production path.

## 5. Verification queries

```sql
SELECT COUNT(*) FROM session_notes;
SELECT COUNT(*) FROM group_teachers;
SELECT COUNT(*) FROM session_scores sc
JOIN scoring_modules m ON m.id = sc.module_id
WHERE m.code = 'murajaa';
```

Expected demo values:

```text
session_notes      0 initially (notes are user data)
group_teachers     16 (8 lead + 8 Murajaa co-teachers in demo)
murajaa scores     384 canonical module scores after backfill
```

Verify the patch is idempotent by running it a second time: the totals above
must not change.

## 6. Deploy together

Deploy backend and frontend together:

- `/reviews` redirects to `/entry`
- old review APIs return 404
- timeline route `/students/:id/timeline`
- workspace `/entry`

## 7. Rollback

- Do not drop `session_notes` or `group_teachers`; they may contain new data.
- `murajaa_reviews` remains read-only and intact.
- If the frontend must roll back, redeploy the previous frontend build; the
  additive schema remains backward-compatible.

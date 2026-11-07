# Agent.md — BACKEND

This file mirrors dashboard/API facts with `alotrojah_Frontend/docs/Agent.md`. Production remains FTP + phpMyAdmin only.

## §LOG

- **Dashboard statistics API** (2026-11-07, user-directed dashboard rebuild; uncommitted). Added `GET /v1/dashboard/overview?center_id=&season_id=` in `DashboardController::overview`, returning role-scoped KPIs, weekly score/attendance trends, attendance/score/honor/gender/level distributions, group and center comparisons, and top students. Scope rules: admin = global or one `center_id`; supervisor = forced own center; teacher = own groups; student = 403 (student keeps `GET /v1/dashboard/me`). The endpoint uses direct aggregate SQL, resolves the current season, and caches payloads per role/teacher/center/season for 30 s under `storage/cache/dashboard`. Removed the superseded `GET /v1/dashboard/center` endpoint/controller method and the unused `GET /v1/registration-requests/count` endpoint. Reference scope returns `center_name` for supervisor/teacher. Verified against `alotrojah_demo`: admin students 64/groups 8/teachers 4/attendance 94.9%, supervisor 32/4/2, teacher 16/2/1, student 403; warm overview server `X-Response-Time` ≈15–27 ms, first uncached ≈500–750 ms (30 s per-role/center/season cache). PHP lint green.

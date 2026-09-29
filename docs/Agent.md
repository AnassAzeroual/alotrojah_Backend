# Agent.md — Project Reference (Quran Memorization App)

> Read this file first in any new session. It holds every decision made so far.
> Details of the meeting live in `questions.md` (same folder). SQL files live on Desktop.

## 1. What this is
- App for a **non-profit Quran memorization association in Morocco** (one association, currently one center; DB supports many).
- Digitizes a paper **memorization logbook** ("البرنامج المقترح لحفظ القرآن الكريم — فئة غير المتفرغ").
- Reference inspiration: `https://ahlquran.com/`. Domain bought: **`https://alotrojah.ma/`**.
- Users: association **manager** (PC), **3+ teachers** (smartphones only, 3G/4G + *6 social pack), **students + guardians share ONE account** (same interface).
- Maintainer after delivery: the developer (me). MVP deadline: **1 month**.

## 2. Tech decisions (locked)
- **Backend: Laravel** (API). **Frontend: Angular**, mobile-first UI (teachers on phones).
- **DB: MySQL 8**, `utf8mb4 / utf8mb4_unicode_ci`. Import: `quran_memorization_db.sql` then `quran_seed_data.sql`, verify with `quran_check_queries.sql`.
- **i18n: ngx-translate**, 3 JSON files `ar` (default first), `fr`, `en`.
- **No paid WhatsApp API.** Use `wa.me/<phone>` deep links with guardian/student numbers from DB (WhatsApp is effectively free for them).
- Future Laravel work goes in **Laravel migrations** (the SQL migration chain v1→v5 was consolidated away; do NOT recreate/delete those files).

## 3. Pedagogy model (from manager + teachers — do not re-ask)
- **Two student types:** children (+4) and adults (+18) → `students.student_type`.
- **Two memorization modes per student:** by **thumn** (1 hizb = 8 thumn; also rubu/nis f variants) or by **surah+ayah range** (e.g. Baqarah 1–5, full Nas) → `students.memorization_mode`, `memorization_logs.log_mode`.
- **Weekly goal, not day counting:** 3 sessions/week default (Mon/Wed/Fri, editable per group → `groups.schedule_days`); teacher checks goal completion → `weekly_goals.is_completed`.
- **Two teacher types:** `hifz` (enters hifz/tajwid/mowathaba/sarraj per session) and `murajaa` (enters ONE official review score /20 per cycle covering **1–3 weeks**, depending on memorized amount) → `users.teacher_type`, `murajaa_reviews` (span enforced by CHECK). Same page, inputs enabled/disabled by type.
- **Review week:** default template = **6 terms × 7 weeks (6 study + 1 review/quiz) = 42 weeks / 126 sessions**. Fully editable rows (`weeks.week_type`, `sessions.session_type`); manager may do e.g. 4+1. Template = Laravel "new season" button, NOT database constants. Any term count allowed (7+).
- **Delegation:** responsible teacher generates time-limited token link (15/30/60/120 min) sent via WhatsApp so another teacher can enter marks → `delegation_tokens`.
- **Announcements page** with audience `all / teachers / manager / my_students` → `announcements`.
- **Honor board** (`tashji3/انتبه`): decided by teacher AND manager; gifts + guardian called; final notes sent via WhatsApp.

## 4. Scoring rules (locked — do not re-ask)
- Weekly total **always /20** = SUM of **active** weekly-total modules. Default: **hifz 14 + mowathaba 4 + tajwid 2**. Manager adjusts per-module maxes on a manager-only page; guardian view `v_scoring_check` must show weekly_total = 20.0.
- **Modular system:** new books (e.g. **السراج في بيان غريب القرآن**) = new `scoring_modules` rows. **R2 adopted:** sarraj is scored per session like hifz but has its **own separate /20 total** (`is_in_weekly_total=0`); weekly average = simple mean of its per-session /20 scores. Inactive modules are grayed/disabled in Angular AND excluded from every view (`is_active=1` filters).
- **Mowathaba /4 is MANUAL per-session input** by the hifz teacher. Never auto-derive from `attendance` (that table is an independent presence register).
- **Murajaa official score /20** comes from `murajaa_reviews` cycles (numeric only — the old جيد جدا/جيد scale is dead). `revision_logs` = optional per-session practice only.
- **Final average formula:** `(avg_murajaa + avg_weekly + SUM(term quiz avgs incl. final)) / (2 + n_terms)` → default 6 terms = ÷8. Computed in Laravel from `v_student_season_avgs` + `v_term_quiz_avgs`. Never hardcode term count.
- Exams: flexible question count; questions generatable as starting ayat from a thumn/hizb, teacher/manager reorders (`sort_order`).

## 5. Database map (25 tables, 11 views)
- **People/org:** `users` (roles: admin/supervisor/teacher/guardian/student/board — examiner merged into teacher, any teacher examines via `exams.examiner_id`; id=1 global admin, `center_id` NULL), `centers` (exactly 3 in seed, isolated), `levels` (L1/L2/L3 from book), `groups`, `guardians`, `students`.
- **Calendar:** `academic_seasons`, `terms`, `weeks`, `sessions`.
- **Planning:** `term_plans` (thumn range OR surah range via `plan_mode`), `weekly_goals`.
- **Scoring core:** `scoring_modules`, `session_scores` (UNIQUE student×session×module), `memorization_logs` (amounts + ranges only, NO score columns), `revision_logs`, `murajaa_reviews`, `attendance`.
- **Exams/reports:** `exams`, `exam_questions`, `term_results`, `season_results`.
- **Features:** `delegation_tokens`, `announcements`, `notifications_log`.
- **Reference:** `surahs` (114 seeded), `quran_hizb_reference` (60).
- **Views (Chart.js):** `v_session_totals`, `v_weekly_progress`, `v_weekly_murajaa`, `v_attendance_rate`, `v_term_quiz_avgs`, `v_student_season_avgs`, `v_separate_module_avgs`, `v_murajaa_cycles`, `v_scoring_check`, `v_season_dashboard`, `v_announcements_feed`.
- **Isolation invariant:** every operational row belongs to exactly ONE center (`users.center_id` scalar; students/groups linked). Audit queries A2/A3/A4 in check file must return 0 rows.

## 6. Seed demo data (what's in the DB now)
- 3 centers (Nour/Algiers, Forqan/Oran, Ihsan/Constantine), 19 users, 5 groups, 7 guardians, 7 students (3+2+2).
- Students 3 (Baqarah) and 6 (Nas) demo **surah mode**; rest thumn mode.
- Sessions 1–6 logged (42 logs, **168** session_scores incl. sarraj), 3 murajaa cycles by review teacher id=19, mixed attendance (1 absent, 1 late, 1 excused in center 3), 10 exams / 44 questions, 7 term + 7 season results, 1 token, 3 announcements, 4 goals, 2 notifications.
- Center tiers for chart testing: center1 high (~18), center2 medium (~14), center3 low (~12).

## 7. Conventions for code work
- Amounts: hizb `DECIMAL(4,1)` (1.0–60.0), thumn amounts `DECIMAL(5,2)`; 1 hizb = 8 thumn.
- Scores `DECIMAL(4,1)`; exam question scores `DECIMAL(4,2)`; all bounded 0–20 (or module max).
- Every fact row carries `student_id, season_id, term_id, week_id, session_id, (log_)date` — keep this chain on any new fact table.
- Qualitative book checkboxes are `ENUM`s; measurable things are numeric. Never store plannable quantities as free text.
- Status/type changes by UPDATE, never DELETE (students, users, groups).
- New schema changes → **Laravel migration files**, keep SQL dump files untouched unless explicitly asked to regenerate.

## 8. Open / pending (not blockers)
- Real student Excel from association (pending) → import script; live season already started (fasl 1 ends ~3 weeks after meeting).
- Old paper دفتر totals: import only if manager provides them, else fresh start.
- Cross-center comparison pages: allowed (used to motivate students via WhatsApp) with PDF/image export + WhatsApp share button.
- Reports printable like book pages, but freedom to improve layout.

## 9. Backend build log (S1 → …)
- **S1 (done):** PHP 8.4.25 (winget) + Composer 2.10.3. Gotchas: winget PHP `extension_dir` pointed at `C:\php\ext` (fixed in php.ini); enabled mbstring/pdo_mysql/openssl/fileinfo/tokenizer/sodium/curl/zip. Shell sessions load stale PATH → prepend real dirs every call. User terminal is fine after restart (registry PATH correct).
- **S2 (done):** `alotrojah_dev` on XAMPP MariaDB 10.4.32 (root/no-password). Dedicated user `alotrojah` (see S3 summary for password — stored in backend `.env`, never root). **Encoding lesson:** importing SQL via PowerShell `Get-Content | mysql` DESTROYS Arabic (stored as `?`, HEX `3F…`). Always import via .NET UTF-8 read → no-BOM temp file → mysql `SOURCE` with `--default-character-set=utf8mb4`. Verify with `SELECT HEX(name_ar) FROM surahs WHERE id=2;` → must be `D8A7D984D8A8D982D8B1D8A9`. If the main HeidiSQL DB shows `3F…`, re-import it the same way.
- **S3 (done):** Laravel 12.69.2 in `alotrojah_Backend` (temp-dir + move, `.git`/docs kept). `.env`: `alotrojah_dev`, timezone `Africa/Casablanca`, `CACHE_STORE=file` + `QUEUE_CONNECTION=sync` (L12 DB defaults need framework tables that don't exist). PowerShell `-replace` needs `(?m)` + `#\s?` for commented `.env` lines.
- **S4 (done):** 18 enums (DB values + Arabic `label()`), 27 schema-generated models (fillable/casts/relations/scopes; hand-fixed: relation-class bug, missing `center()` relations where no FK exists). Base API controller, `ForceJsonResponse`, real `ScoringService` / `SeasonTemplateService` (42-week) / `DelegationService` / `DashboardService`, `CenterScoped` + Student/Group policies, Student request/resource exemplars, `SessionScoreObserver`, `/api/v1` skeleton.
- **S5 (done, live-tested):** JWT via `php-open-source-saver/jwt-auth` (needed `ext-sodium`). Password column is `password_hash` → `User::getAuthPassword()` overridden; claims = role/center_id/teacher_type. login/me/refresh/logout + 401s verified. L12 `redirectGuestsTo` default calls `route('login')` → overridden to `null` for JSON 401s.
- **S6 (done, live-tested):** users/centers/groups/students/guardians CRUD, center auto-forcing, cross-center 403s, validation 422s. Fixed: `center_id` must be nullable in store rules (controller forces it); added missing relations.
- **S7 (done, live-tested):** bulk attendance + module scores (max from DB rows, murajaa-teacher 403, all-or-nothing), follow-up page endpoint, weekly-goal upsert. Sarraj correctly excluded from /20 total (18.0 for 13+3+2+17).
- **S8 (done, live-tested):** season template generation, 42-week default verified (mini-template counts exact), activate switching, module bulk rebalance guarded to sum 20 (422 + rollback), term-plan hizb/ayah validation, reference feeds. **Rebuilt dev DB once** due to the encoding lesson above; dev passwords re-set (admin/supervisor/teachers/murajaa = `password123`, dev only).
- **S9 (done, live-tested):** murajaa cycles (span 1-3 enforced, hifz-teacher 403) + revision practice rows; exams (conducted by teachers — examiner role later merged, see post-S13 note) + flexible bulk questions with dup/ayah validation and auto-recomputed `overall_avg`; term/season result upserts (honor teacher+manager); delegation full cycle — generate (responsible only) → redeem (binds first teacher) → cross-center score allowed → revoke → blocked again. `DelegationService::canActAs` integrated into `ScoreEntryService`. Fixed: `Exam::questions()` alias (generator named it `examQuestions`), generated-column `.fresh()` for `weeks_covered`, GroupPolicy `generate` alias (Gate resolves policy by model class). Test-only: login throttle is 6/min (hit it during rapid tests).
- **S10 (done, live-tested):** announcements with visibility matrix (`Announcement::scopeVisibleTo`: all/staff-same-center/manager-admin+supervisor/my_students-group; admin-authored = global); notifications with `wa.me` links + sent/failed marking; dashboard endpoints (season/weekly/center/final with transparent components+divisor — hizb_completion excluded from final by design); composite term/season report endpoints for print pages. Fixed: `stdClass::toArray` in centerCards, final divisor filter, global-author visibility. Verified final math by hand: (18+18.25+17.6+17.43)/4 = 17.82 ✓.
- **S11 (done, live-tested):** security pass. No hardcoded secrets (grep clean). `config/cors.php` created, locked to `FRONTEND_URL`. `CenterScope` global scope on Student/Group/User/Guardian/Center (skips console/admin/board; guardian via students). Caught infinite recursion (scope → auth()->user() → scoped User query → scope…) — fixed with re-entrancy guard. Delegation bypasses scope explicitly in `ScoreEntryService` (verified cross-center entry still works post-scope). All dashboard/report inputs moved to FormRequests. Examiner role forced as own `examiner_id`. Throttle 30/min on bulk + redeem. Note: scoped route-model binding turns cross-center direct reads into 404 (safe). Console/tinker unaffected (7 students).
- **S12 (done, tested):** performance pass. Bulk paths preloaded, 8 composite `idx_s12_*` indexes (dev + dump), dashboard cached 300s with bust observers, PHPUnit 5/19 green, 130–160ms dev timings, queues stay `sync`, opcache on host (S14).
- **S13 (done, tested):** 24 tests / 57 assertions green (`PerformanceTest` + `PolicyTest` + `DelegationScoringTest`, transaction-wrapped). Tests caught 4 REAL issues: (1) guardians locked out of own list (`viewAny` staff-only — fixed, same for new `student` role); (2) `users.role` ENUM lacked `student` though code+meeting assume it (added: dev + dump + `Role` enum); (3) sarraj averages used `COALESCE→0` instead of NULL when inactive (fixed in 2 views, dev + dump); (4) duplicate policy imports (fatal only at runtime). Test-harness lessons: JWT guard caches its user per app instance → multi-user tests MUST use `actingAs()`, never two tokens; in-test console skips `CenterScope` so cross-center reads 403 via policy (live HTTP gives 404 via scope) — both safe, expectations annotated.
- Dev passwords: admin id=1, supervisor id=2, teachers id=3,5,7, murajaa id=19 → all `password123` (DEV ONLY, rotate before prod).
- **Pre-Angular gate (passed):** full PHPUnit suite 24/58 green + 24 live end-to-end checks green (auth→S11, incl. delegation cycle, final math, CORS, cross-center). Cleanup lesson: upserts on seed rows must be RESTORED afterwards (term_results/term_plans), created rows deleted — canonical counts: scores 168, attendance 42, goals 4, plans 14, reviews 3, tokens 1.
- **Post-S13 change — examiner role REMOVED (examiner = teacher):** `users.role` ENUM, `Role` enum, all policies/scopes/requests cleaned; `exams.examiner_id` column stays (holds the conducting teacher); seed examiners converted to teachers; 24 tests / 58 assertions green.

## 10. Frontend build log (F1 → …)
- **F1 (done):** Node 25.9 default too new (CLI warns/refuses odd versions) → installed Node **24.21.0 LTS** via existing nvm-windows, CLI **22.2.0** global under the 24 install. Project commands use the v24 paths explicitly; user default stays 25.
- **F2 (done):** `ng new` (SCSS, routing, zoneless, strict) moved into `alotrojah_Frontend` preserving `.git`/`docs`. Fixes: CLI did not write `strict`/`strictTemplates`, added explicitly; renamed project `alotrojah_new` to `alotrojah`. Verified: no `zone.js` dep, dev build green.
- **F3 (done, verified live):** `@ngx-translate/core@18` + http-loader + `chart.js` (no ng2-charts wrapper — version risk, own thin chart component later). `ar`/`fr`/`en` JSON under `public/assets/i18n` (shell/auth/nav/roles/status keys). Design system: `_tokens.scss` (green/gold, spacing, radius, mobile-first `min-width` breakpoints + `up()` mixin), `_rtl.scss`, `_print.scss` (book-faithful reports). `index.html` ar/rtl + Cairo font. `environments.{ts,prod.ts}` + prod fileReplacements. Gotchas: `provideTranslateLoader` is exported from CORE not the loader package; loader needs `() => new TranslateHttpLoader()` factory; launch dev-server via `cmd /c` wrapper (direct ng.cmd Start-Process fails silently). Verified: build green, serve 200, ar.json served with title.
- **F4 (done, build green):** core `ApiClient` (envelope unwrap, typed), `api-models.ts` (no `any` past it), `AuthService` (signals, single-flight refresh, APP_INITIALIZER session restore), `auth.interceptor` (Bearer + silent refresh, auth routes excluded), `role.guard` (roles + teacherTypes route data), `LanguageService` (ar/fr/en + dir flip + persist). Shared dumb OnPush primitives: spinner, empty-state, status-badge, score-input (model I/O). Standards: standalone only, `inject()`, `input()`/`output()`/`model()`, `@if/@for`, strict templates clean.
- **F5 (done, verified live):** login page (typed reactive form, 401 flag, spinner), shell (header + user chip + language switcher + logout, responsive bottom-nav/sidebar), home placeholder, lazy routes with `roleGuard` shell. Fixed: stale `TranslatePipe` import broke the build (strict catches it). Verified: build green, `/` + `/login` serve 200.
- **F6 (done, verified live):** daily entry — group/week/session pickers, attendance grid (4-state segmented), score sheet generated from `scoring-modules` API (inactive grayed, sarraj separate section, live /20 totals, murajaa sees disabled + notice), per-row goal check. Patterns: `resource({params})` (ng22 renamed `request`), computed edit-maps (no effects), `viewChild`-free parent refresh via `savedTick`. Backend gap fixed: teachers can now READ weeks/sessions (`viewCalendar`). Gotchas: missing `)` on `computed(` cascades weird errors — read tsc output head; stale `.angular` cache can lie (clean it). Verified: strict build green; live combos groups=2, group-students=2, weeks=42, week-sessions=3, modules=5; `/entry` serves 200.
- **F7 (done, verified live):** lists + detail — students (search + group/level/status/mode filters, pagination, detail with season summary + final + honor badge), groups cards, guardians list; reference + dashboard API services; `withComponentInputBinding` for `:id`; nav extended per role. Gotchas: feature files are 2 levels deep (`../../core`, not `../../../`); hand-edited i18n JSON breaks easily (validate with node before build). Verified: strict build; live search=1, surah-filter respects center scope, all 4 routes serve 200.
- **F8 (done, verified live):** planning + manager scoring — seasons list/create (dynamic terms FormArray prefilled 6x7, 42-week default), activate, term detail (weeks list, study/review toggle, sessions inline date/status/type edit), plans page (student search, term select, thumn/surah toggle, ayah-max from surahs API, client range checks mirroring backend), scoring page (live sum-to-20 indicator, atomic bulk rebalance, add-book form). Shared badge extended with `week` kind. Verified: strict build; live terms=6, term weeks=7/sessions=3; all 5 routes serve 200.
- **F9 (done, verified live):** exams (list + new with student search, detail with inline score edit, add-question, auto average), reviews (cycles list + 1-3-week form, murajaa-only entry surfaced), results (term/season upserts with honor), printable term/season reports (`print-sheet` + print button, typed composites). Gotchas: `resource({params})` again; TS forbids direct `as` from `Promise<Record>` (typed the service instead); duplicate local interfaces removed. Verified: strict build; live exams=3, cycles=1, term result 18.47; all 8 routes serve 200.
- **F10 (done, verified live):** finale — news (audience targeting + group picker), delegation (generate with one-time link + wa.me share + copy, redeem page via `?token=`, revoke list), notifications outbox (queue, tap-to-send wa.me link, sent/failed, delete), dashboard (center cards + honors bar + attendance doughnut, per-student weekly bars) with thin `app-chart` wrapper (RTL tooltips, create/update/destroy). Home placeholder replaced by real dashboard. Verified: strict build; live news=3, center cards + honors + attendance, weekly=2 weeks; all 5 routes serve 200. Angular standards (signals, standalone, zoneless, @if/@for, OnPush, lazy routes, typed forms) from this file § developments — treat as law.

---

# Modern Angular Development Standards & AI Coding Guidelines

This document serves as both a human-readable best practices guide and an AI prompt instruction set (`.cursorrules` / `copilot-instructions.md`) for Angular development.

---

## 1. Modern Reactivity & Signals

### Guidelines & Rules
* **Default State to Signals:** Use `signal()`, `computed()`, and `effect()` for managing local and synchronous UI state instead of manual RxJS `BehaviorSubject` instances or mutable properties.
* **Functional Signal Inputs & Queries:** Never use legacy decorator syntax (`@Input()`, `@Output()`, `@ViewChild()`). Always use functional signal primitives.
* **Limit `effect()` Usage:** Do not use `effect()` to update other signals to prevent infinite loops and race conditions. Reserve `effect()` solely for external side effects (e.g., logging, manual DOM manipulation, local storage).
* **RxJS Integration:** Use RxJS strictly for asynchronous events, HTTP calls, and complex stream transformations. Convert Observables to Signals using `toSignal()` for template consumption.

### Code Examples

```typescript
import { Component, computed, inject, input, output, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { UserService } from './user.service';

@Component({
  selector: 'app-user-profile',
  standalone: true,
  template: `
    <h2>{{ upperName() }}</h2>
    <p>Status: {{ status() }}</p>
    <button (click)="select.emit(userId())">Select User</button>
  `
})
export class UserProfileComponent {
  private userService = inject(UserService);

  // Modern Signal Inputs & Outputs
  readonly userId = input.required<string>();
  readonly select = output<string>();

  // Computed State
  readonly user = toSignal(this.userService.getUser(this.userId()));
  readonly upperName = computed(() => this.user()?.name.toUpperCase() ?? '');
  readonly status = signal<'active' | 'idle'>('active');
}

2. Component & Application Architecture
Guidelines & Rules
100% Standalone Architecture: Do not create or use NgModule. All components, directives, and pipes must be standalone (standalone: true).

Root Providers: Bootstrap applications using bootstrapApplication() in main.ts and configure providers in app.config.ts.

Functional Dependency Injection: Use inject(Service) instead of constructor injection for improved type inference, easier inheritance, and cleaner class headers.

Domain Driven Organization: Group files by feature/domain (src/app/features/auth, src/app/features/dashboard) rather than technical role (components/, services/). Shared presentational UI elements belong in src/app/shared/ui/.

Zoneless app no zone.js or ngzone

3. Control Flow & Template Syntax
Guidelines & Rules
Built-in Control Flow: Always use native @if, @for, and @switch syntax. Never use structural directives (*ngIf, *ngFor, *ngSwitch).

Mandatory Loop Tracking: Every @for loop must specify a unique tracking expression (track item.id). Tracking by array index (track $index) is strictly forbidden unless the dataset is static and immutable.

<!-- User List Template -->
@if (isLoading()) {
  <app-spinner />
} @else {
  <ul class="user-list">
    @for (user of users(); track user.id) {
      <li>
        <span>{{ user.name }}</span>
        <button (click)="deleteUser(user.id)">Delete</button>
      </li>
    } @empty {
      <li class="empty-state">No users found in the system.</li>
    }
  </ul>
}


4. Performance & Rendering Optimization
Guidelines & Rules
OnPush Change Detection: Set changeDetection: ChangeDetectionStrategy.OnPush on every component.

Deferrable Views (@defer): Lazy-load heavy or below-the-fold components using @defer blocks to optimize initial bundle size and First Contentful Paint (FCP).

Image Optimization: Use NgOptimizedImage (ngSrc) for all images. Apply the priority attribute to Above-the-Fold images (Largest Contentful Paint).

SSR & Hydration: Avoid direct DOM references (window, document, ElementRef.nativeElement). Use Renderer2 or condition code using isPlatformBrowser.

<!-- Deferrable View with Viewport Trigger -->
@defer (on viewport) {
  <app-analytics-chart [data]="chartData()" />
} @placeholder {
  <div class="chart-skeleton">Loading Chart...</div>
} @error {
  <p>Failed to load analytics module.</p>
}

<!-- Optimized Image -->
<img ngSrc="/assets/hero.webp" width="800" height="400" priority alt="Application Banner" />

5. Routing & HTTP Network Layer
Guidelines & Rules
Lazy-Loaded Routes: Always load route components using dynamic import() via loadComponent or loadChildren.

Component Input Binding: Enable withComponentInputBinding() so route parameters and query parameters bind directly into component input() signals.

Functional Interceptors and Guards: Define HTTP interceptors (HttpInterceptorFn) and route guards (CanActivateFn) as standalone functions rather than injectable class services.

// Functional HTTP Interceptor
import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { AuthService } from './auth.service';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const token = inject(AuthService).token();
  if (token) {
    req = req.clone({
      setHeaders: { Authorization: `Bearer ${token}` }
    });
  }
  return next(req);
};

// Route Definition
import { Routes } from '@angular/router';

export const routes: Routes = [
  {
    path: 'dashboard/:id',
    loadComponent: () => import('./features/dashboard/dashboard.component').then(m => m.DashboardComponent)
  }
];

6. Typed Forms & State Management
Guidelines & Rules
Strongly Typed Reactive Forms: Do not use untyped reactive forms or template-driven forms for complex UI logic. Explicitly type all FormGroup and FormControl instances.

Signal-Based Local State: Use @ngrx/signals (SignalStore) or lightweight custom Signal services for local/global state management instead of verbose Redux boilerplate.

import { Component, inject } from '@angular/core';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';

interface LoginForm {
  email: FormControl<string>;
  password: FormControl<string>;
}

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [ReactiveFormsModule],
  template: `
    <form [formGroup]="loginForm" (ngSubmit)="onSubmit()">
      <input formControlName="email" type="email" placeholder="Email" />
      <input formControlName="password" type="password" placeholder="Password" />
      <button type="submit" [disabled]="loginForm.invalid">Log In</button>
    </form>
  `
})
export class LoginComponent {
  readonly loginForm = new FormGroup<LoginForm>({
    email: new FormControl('', { nonNullable: true, validators: [Validators.required, Validators.email] }),
    password: new FormControl('', { nonNullable: true, validators: [Validators.required] })
  });

  onSubmit(): void {
    if (this.loginForm.valid) {
      const credentials = this.loginForm.getRawValue();
      console.log('Submitting credentials:', credentials);
    }
  }
}

7. Tooling, TypeScript & Code Quality
Guidelines & Rules
Strict Typing: Set "strict": true and "strictTemplates": true in configuration files. Avoid the any type under all circumstances.

Auto-Unsubscribe: Avoid manual .subscribe(). When subscriptions are required outside templates, manage memory lifecycles using takeUntilDestroyed() within an injection context.

// Section 7: Tooling, TypeScript & Code Quality Example
import { Component, DestroyRef, inject, OnInit } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { interval } from 'rxjs';

@Component({
  selector: 'app-clean-subscription',
  standalone: true,
  template: `<p>Check console for clean timer logs.</p>`
})
export class CleanSubscriptionComponent implements OnInit {
  private destroyRef = inject(DestroyRef);

  ngOnInit(): void {
    // Automatically unsubscribes when the component is destroyed
    interval(1000)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((val) => {
        console.log('Timer tick:', val);
      });
  }
}
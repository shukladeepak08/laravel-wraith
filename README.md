# Laravel Wraith

**Point-in-time diagnostic analysis for Laravel applications.**

`sdpayhub/laravel-wraith` inspects your app **as it stands right now** — configuration, database schema, routes, Eloquent models, security posture, and codebase tooling — and reports severity-ranked, actionable findings.

It is **not** a profiler, APM, or continuous health monitor. It does not watch requests over time. One command run, one snapshot.

```bash
composer require sdpayhub/laravel-wraith --dev
php artisan wraith
```

Requires **PHP ^7.3|^8.0+** and **Laravel 8–12**.

---

## What this is / what this isn't

| This is | This is not |
|---|---|
| A static (plus opt-in dynamic) audit you run on demand or in CI | Telescope / Pulse / continuous APM |
| A maintained successor-shaped tool for the gap left by archived [Enlightn](https://github.com/enlightn/enlightn) | A fork of Enlightn (MIT, clean-room) |
| Schema-depth + scoring + safe `--fix` | Spatie Laravel Health (runtime checks) |
| Wrapper around `composer audit`, `npm audit`, PHPStan, Pint | A custom vulnerability DB or secret scanner |

**Explicitly out of scope:** query plans / `EXPLAIN`, lock contention, continuous latency metrics, “smart” business-logic auto-fixes (eager loading, inventing indexes), homegrown secret scanning.

**Dynamic Mode (`--dynamic`)** is opt-in and makes **real HTTP requests**. Default is GET-only. Use on disposable environments when unsure.

---

## Why “Wraith”?

`laravel-vitals` collides with an existing Lighthouse/RUM package. Wraith is a silent inspector: it walks your app once and reports what looks wrong. Packagist name `sdpayhub/laravel-wraith` and Artisan command `wraith` are unused.

Community Enlightn forks (`exin/enlightn`, `ivqonsanada/enlightn`) are compatibility patches. Wraith’s pitch is **Laravel 8–12 support, schema depth, transparent scoring, enumerated `--fix`, and opt-in dynamic query analysis** — not “Enlightn with a bump.”

---

## Installation

```bash
composer require sdpayhub/laravel-wraith --dev
php artisan vendor:publish --tag=wraith-config
```

---

## Command surface

```bash
php artisan wraith                         # all static analyzers
php artisan wraith --only=security,database
php artisan wraith --except=routes
php artisan wraith --json
php artisan wraith --html                  # also writes storage/wraith/report-*.html
php artisan wraith --markdown
php artisan wraith --score                 # score only
php artisan wraith --ci --fail-on=high     # non-zero exit for CI
php artisan wraith --fix --dry-run         # preview safe fixes
php artisan wraith --fix                   # apply safe fixes (with backup)
php artisan wraith --restore               # restore last --fix backup
php artisan wraith --dynamic               # opt-in route replay + query patterns
php artisan wraith --dynamic --routes=api/*
```

Categories for `--only` / `--except`: `application`, `security`, `configuration`, `database`, `eloquent`, `routes`, `performance`, `code_quality`, `dynamic`.

---

## Analyzers

### Application & Environment
`APP_DEBUG` in production, missing `APP_KEY`, maintenance mode, timezone/locale, storage symlink, config/route cache presence in production.

### Security
Production session/cookie hardening, HTTPS `APP_URL`, `.env` gitignore / public exposure, **`composer audit`**, **`npm`/`pnpm audit`** when `package.json` exists, gitleaks suggestion (integration point only).

### Configuration
Missing core env keys, non-boolean-like bool env values, version-sensitive deprecated key hints.

### Database schema
Pending migrations, missing/empty `down()` methods, missing primary keys, FK columns without indexes, likely missing FK constraints on `*_id` columns, duplicate indexes, collation mismatches. (MySQL gets the deepest checks; SQLite/Postgres get a subset.)

### Eloquent
Missing `$fillable`/`$guarded`, fully unguarded models, `deleted_at` without SoftDeletes, weak missing-casts signals.

### Routes
Duplicates, unnamed routes, closure routes in production (blocks route cache), API routes without throttle.

### Performance & Assets
`sync`/`file`/`array` drivers in production, OPcache, asset manifest / minification heuristic, Horizon/Octane config presence.

### Code quality
Delegates to PHPStan and Pint when installed; does not reimplement cyclomatic complexity or dead-code detection.

### Dynamic (opt-in)
Attaches `DB::listen`, replays GET routes (no required params by default), flags duplicate queries, N+1-shaped patterns, and slow queries.

---

## Scoring

Not a black box. Documented in `config/wraith.php`:

```
category_score = max(0, 100 - sum(severity_penalties))
overall_score  = weighted average of category scores
```

Default severity penalties: critical 25, high 15, medium 8, low 3, info 0.  
Default weights: security 2.0, database 1.5, dynamic 1.5, others 1.0.  
Override freely — expect disagreement; make the formula yours.

---

## Safe auto-fix (enumerated)

Only these fix codes are supported:

| Fix code | What it does |
|---|---|
| `gitignore_env` | Ensures `.env` is listed in `.gitignore` |
| `env_bool_normalize` | Sets an env key to an explicit `true`/`false` |
| `pint` | Runs `vendor/bin/pint` |

`--fix` writes a backup under `storage/wraith/backups`. `--restore` reverts tracked files from the latest backup. Nothing “smart” (no eager-load inventing, no index creation).

---

## CI example

```yaml
- run: php artisan wraith --ci --fail-on=high --json
```

---

## Testing note

Analyzer tests use **fixture / in-memory** apps and schemas, not production-scale databases. That is intentional.

---

## Compatibility

| Laravel | PHP (typical) |
|---|---|
| 8 | 7.3–8.1 (package uses PHPDoc property types for 7.3) |
| 9 | 8.0–8.2 |
| 10 | 8.1+ |
| 11–12 | 8.2+ |

Version-sensitive analyzers are marked in source comments.

---

## License

MIT. See [LICENSE.md](LICENSE.md), [CHANGELOG.md](CHANGELOG.md), [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md).

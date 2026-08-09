# Laravel Wraith

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sdpayhub/laravel-wraith.svg?style=flat-square)](https://packagist.org/packages/sdpayhub/laravel-wraith)
[![Total Downloads](https://img.shields.io/packagist/dt/sdpayhub/laravel-wraith.svg?style=flat-square)](https://packagist.org/packages/sdpayhub/laravel-wraith)
[![License](https://img.shields.io/packagist/l/sdpayhub/laravel-wraith.svg?style=flat-square)](https://packagist.org/packages/sdpayhub/laravel-wraith)
[![GitHub Actions](https://img.shields.io/github/actions/workflow/status/shukladeepak08/laravel-wraith/tests.yml?branch=main&style=flat-square&label=tests)](https://github.com/shukladeepak08/laravel-wraith/actions)

Inspect your Laravel app **right now** — config, security, database schema, routes, models — and get a scored list of problems with suggested fixes.

```bash
composer require sdpayhub/laravel-wraith --dev
php artisan wraith
```

Works with **Laravel 8–12** (PHP 7.3+).

### Before / after

```text
# Before — first run on a typical app
Overall score: 62 / 100
[CRITICAL] APP_DEBUG is enabled in production
[HIGH]     Session cookies are not marked secure
[HIGH]     Telescope is present in a production environment

# After — fix critical/high (or baseline accepted debt)
php artisan wraith:baseline   # optional: accept remaining low noise
php artisan wraith --ci --fail-on=high
# exit 0 — CI only fails on *new* findings
```

---

## Quick start (3 steps)

### 1. Install

```bash
composer require sdpayhub/laravel-wraith --dev
```

Optional (only if you want to change weights / thresholds):

```bash
php artisan vendor:publish --tag=wraith-config
```

### 2. Run

```bash
php artisan wraith
```

That’s it. Wraith scans your app once and prints a report in the terminal.

### 3. Read the report

You’ll see something like:

```text
  Wraith — Laravel diagnostic audit
  ─────────────────────────────────
  Overall score: 77 / 100          ← higher is healthier (100 = clean)

  Category scores:
    application      100
    security          70           ← this category lost points
    database          85

  How to read this
  • Score starts at 100 per category; each issue subtracts points
  • critical −25 · high −15 · medium −8 · low −3 · info −0
  • Fix critical/high first

  [CRITICAL] (1)
    • [app.debug_in_production] APP_DEBUG is enabled in production.
      Why: Exposes stack traces and secrets to users.
      Fix: Set APP_DEBUG=false in .env
      Auto-fixable: yes (--fix)

  [HIGH] (2)
    • [security.session_insecure] Session cookies are not marked secure...
      Why: ...
      Fix: ...

  3 finding(s) in 412 ms

  Next steps
  • Fix critical/high issues first
  • Preview safe fixes:  php artisan wraith --fix --dry-run
  • Shareable HTML:      php artisan wraith --html
  • CI gate:             php artisan wraith --ci --fail-on=high
```

| What you see | What it means |
|---|---|
| **Overall score** | Weighted health score for this run (0–100) |
| **Category scores** | Score per area (security, database, …) |
| **`[CRITICAL]` / `[HIGH]` …** | How urgent the issue is |
| **Why** | Why it matters |
| **Fix** | What to do |
| **Auto-fixable: yes** | Wraith can apply a safe mechanical fix with `--fix` |

---

## Common commands

| Goal | Command |
|---|---|
| Full audit (default) | `php artisan wraith` |
| Only security + database | `php artisan wraith --only=security,database` |
| Skip routes | `php artisan wraith --except=routes` |
| Score numbers only | `php artisan wraith --score` |
| JSON (for scripts) | `php artisan wraith --json` |
| HTML file you can open/share | `php artisan wraith --html` |
| Markdown | `php artisan wraith --markdown` |
| Fail CI on high+ issues | `php artisan wraith --ci --fail-on=high` |
| Preview safe auto-fixes | `php artisan wraith --fix --dry-run` |
| Apply safe auto-fixes | `php artisan wraith --fix` |
| Undo last `--fix` | `php artisan wraith --restore` |
| Live query patterns (opt-in) | `php artisan wraith --dynamic` |
| Force dynamic outside local | `php artisan wraith --dynamic --force-dynamic` |
| Write baseline (accepted debt) | `php artisan wraith:baseline` |
| Update baseline | `php artisan wraith --update-baseline` |
| Show everything (skip baseline) | `php artisan wraith --no-baseline` |
| CI: fail only on new issues | `php artisan wraith --ci --fail-on=high --diff` |

Categories: `application`, `security`, `configuration`, `database`, `eloquent`, `routes`, `performance`, `code_quality`, `dynamic`.

---

## Baseline & ignore list

Two ways to silence known findings without losing the score for *new* problems:

### 1. Permanent ignore (config)

```bash
php artisan vendor:publish --tag=wraith-config
```

```php
// config/wraith.php
'ignore' => [
    'app.schedule_requires_cron',
],
```

### 2. Baseline file (accepted debt)

```bash
php artisan wraith:baseline
# writes storage/wraith/baseline.json
```

When that file exists, matching findings are hidden from reports and do not fail `--ci`. Commit it (team-shared) or keep it local.

```bash
php artisan wraith --update-baseline   # refresh after a cleanup
php artisan wraith --no-baseline       # see the full list again
```

---

## What this is / isn’t

| This is | This is not |
|---|---|
| A one-shot audit of config, schema, and code | Telescope / Pulse / continuous APM |
| Something you run locally or in CI | A runtime health ping every minute |
| Actionable findings with suggested fixes | A replacement for PHPStan (it wraps it) |

**Out of scope:** `EXPLAIN` / query plans, lock contention, continuous latency, inventing indexes/eager loads, custom vulnerability databases.

**`--dynamic` warning:** makes real GET requests to your app. Blocked outside `local`/`testing` unless you pass `--force-dynamic`. Skips Telescope/Horizon/Livewire/etc. by default. Use on a disposable environment if unsure.

---

## Scoring (simple version)

1. Every category starts at **100**.
2. Each finding subtracts points by severity:

   | Severity | Points lost |
   |---|---|
   | critical | 25 |
   | high | 15 |
   | medium | 8 |
   | low | 3 |
   | info | 0 |

3. **Overall score** = weighted average of category scores.  
   Security counts more (weight 2.0), database/dynamic 1.5, others 1.0.

Change weights anytime in `config/wraith.php` after publishing the config.

**CI note:** `--ci --fail-on=high` fails the build if any finding is high or worse — it does **not** use the 0–100 score as the fail gate.

---

## Safe auto-fix

Only these mechanical fixes are supported (nothing “smart”):

| Fix | What it does |
|---|---|
| `gitignore_env` | Ensure `.env` is in `.gitignore` |
| `env_bool_normalize` | Set an env key to `true`/`false` |
| `pint` | Run Laravel Pint |

Always preview first:

```bash
php artisan wraith --fix --dry-run
php artisan wraith --fix
php artisan wraith --restore   # if you need to undo
```

---

## What Wraith checks

### Application
- Debug mode, app key, timezone, storage link, config/route cache in production  
- Writable `storage/` + `bootstrap/cache`  
- Scheduled tasks defined (cron reminder for production; info-level)  

### Security
- Session cookies, HTTPS `APP_URL`, `.env` exposure  
- `composer audit` + npm/pnpm audit; gitleaks suggestion  
- **Telescope / Horizon / Pulse / Debugbar** present in production  
- **Trusted proxies** empty/missing (Cloudflare/ALB footgun)  
- **CORS** `*` (especially with credentials)  
- **Sanctum ↔ SESSION_DOMAIN** alignment for SPAs  
- **Executable uploads** under `storage/app/public`  
- **Abandoned Composer packages**  

### Configuration
- Missing env keys, bad bool values  
- **`.env.example` drift** vs `config/*.php`  
- **Composer PHP platform / constraint** vs runtime PHP  

### Database
- Pending migrations, missing `down()`, PKs/FKs/indexes, collation  
- **Secondary DB connections** using localhost / empty password in production  

### Eloquent
- Mass assignment, soft deletes mismatches, weak missing-casts signals  

### Routes
- Duplicates, unnamed routes, closures in production, API throttling  
- **Login / password-reset / OTP routes without throttle**  

### Performance
- `sync`/`file`/`array` drivers, OPcache, assets, Horizon/Octane config  
- **Mail = log/array**, **filesystem = local**, noisy single-file logs in production  
- **Redis prefix** collisions / empty prefixes  
- **Queue retry_after vs timeout**, missing **failed_jobs** table  

### Code quality
- Wraps PHPStan & Pint when installed  

### Dynamic (opt-in `--dynamic`)
- N+1 / duplicate / slow query patterns via route replay  

---

## CI example

```yaml
- name: Wraith audit
  run: php artisan wraith --ci --fail-on=high --diff --json
```

With a committed `storage/wraith/baseline.json` (or a path set in config), only **new** findings fail the job.

---

## Compatibility

| Laravel | PHP |
|---|---|
| 8 | 7.3–8.1 |
| 9 | 8.0–8.2 |
| 10 | 8.1+ |
| 11–12 | 8.2+ |

---

## License

MIT — [LICENSE.md](LICENSE.md) · [CHANGELOG.md](CHANGELOG.md) · [CONTRIBUTING.md](CONTRIBUTING.md) · [SECURITY.md](SECURITY.md)

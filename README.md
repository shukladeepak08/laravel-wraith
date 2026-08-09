# Laravel Wraith

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sdpayhub/laravel-wraith.svg?style=flat-square)](https://packagist.org/packages/sdpayhub/laravel-wraith)
[![Total Downloads](https://img.shields.io/packagist/dt/sdpayhub/laravel-wraith.svg?style=flat-square)](https://packagist.org/packages/sdpayhub/laravel-wraith)
[![License](https://img.shields.io/packagist/l/sdpayhub/laravel-wraith.svg?style=flat-square)](https://packagist.org/packages/sdpayhub/laravel-wraith)
[![Tests](https://img.shields.io/github/actions/workflow/status/shukladeepak08/laravel-wraith/tests.yml?branch=main&style=flat-square&label=tests)](https://github.com/shukladeepak08/laravel-wraith/actions)

**Find production risks in your Laravel app before they find your users.**

Wraith is a point-in-time audit tool. One Artisan command reviews configuration, security, database schema, routes, and related settings — then returns a clear score and a prioritized list of what to fix.

It is not APM. It does not run in the background. You run it when you want answers — locally, before deploy, or in CI.

```bash
composer require sdpayhub/laravel-wraith --dev
php artisan wraith
```

Compatible with **Laravel 8–12** and **PHP 7.3+**.

---

## Why teams use it

Laravel apps accumulate silent risk: debug left on in production, Telescope shipped to live, missing trusted proxies behind Cloudflare, unthrottled login routes, sync queues, or config that drifted from `.env.example`.

Most of that never shows up as a failing test. Wraith surfaces it in minutes, with:

- A **0–100 health score** (higher is better)
- Findings grouped by **severity**, with **why it matters** and a **suggested fix**
- Optional **safe auto-fixes** for a few mechanical items
- A **baseline** so CI fails only on *new* issues

---

## Install & run

```bash
composer require sdpayhub/laravel-wraith --dev
php artisan wraith
```

Optional — publish config only if you need custom weights, ignores, or paths:

```bash
php artisan vendor:publish --tag=wraith-config
```

---

## What the report looks like

```text
  Wraith — Laravel diagnostic audit
  ─────────────────────────────────
  Overall score: 77 / 100

  Category scores:
    application      100
    security          70
    database          85

  [CRITICAL] (1)
    • [app.debug_in_production] APP_DEBUG is enabled in production.
      Why: Exposes stack traces and secrets to end users.
      Fix: Set APP_DEBUG=false in production .env

  [HIGH] (2)
    • [security.session_cookie_not_secure] Session cookies are not marked Secure.
      Why: Cookies can leak on plain HTTP.
      Fix: Set SESSION_SECURE_COOKIE=true behind HTTPS
```

| In the report | Meaning |
|---|---|
| Overall score | Weighted health for this run (100 = clean) |
| Category scores | Health per area (security weighs more than others) |
| Severity | `critical` → `high` → `medium` → `low` → `info` |
| Why / Fix | Impact and concrete next step |
| Auto-fixable | Can be applied with `--fix` after a dry-run |

**Rule of thumb:** fix critical and high first. Treat medium/low as backlog or baseline them for CI.

---

## Everyday commands

| Goal | Command |
|---|---|
| Full audit | `php artisan wraith` |
| Security + database only | `php artisan wraith --only=security,database` |
| Skip a category | `php artisan wraith --except=routes` |
| Score summary | `php artisan wraith --score` |
| JSON / HTML / Markdown | `php artisan wraith --json` · `--html` · `--markdown` |
| Fail CI on high+ | `php artisan wraith --ci --fail-on=high` |
| Preview / apply safe fixes | `php artisan wraith --fix --dry-run` then `--fix` |
| Undo last fix | `php artisan wraith --restore` |

Categories: `application`, `security`, `configuration`, `database`, `eloquent`, `routes`, `performance`, `code_quality`, `dynamic`.

---

## Use in CI

Gate pull requests on severity — not on the numeric score:

```yaml
- name: Wraith
  run: php artisan wraith --ci --fail-on=high --diff --json
```

### Baseline (recommended for existing apps)

On a mature codebase you rarely want day-one CI noise. Capture today’s findings once:

```bash
php artisan wraith:baseline
```

That writes `storage/wraith/baseline.json`. Matching findings are hidden afterward and do not fail `--ci`. New findings still fail.

| Action | Command |
|---|---|
| Create baseline | `php artisan wraith:baseline` |
| Refresh after cleanup | `php artisan wraith --update-baseline` |
| See everything again | `php artisan wraith --no-baseline` |

Permanently drop a code via config instead:

```php
// config/wraith.php
'ignore' => [
    'app.schedule_requires_cron',
],
```

---

## Scoring (transparent)

1. Each category starts at **100**.
2. Each finding subtracts points by severity:

   | Severity | Penalty |
   |---|---|
   | critical | −25 |
   | high | −15 |
   | medium | −8 |
   | low | −3 |
   | info | −0 |

3. **Overall score** = weighted average of category scores.  
   Default weights: security **2.0**, database & dynamic **1.5**, everything else **1.0**.

Publish `config/wraith.php` to change weights or penalties.  
`--ci --fail-on=` uses **severity**, never the 0–100 score.

---

## Safe auto-fix

Only enumerated, mechanical fixes — never heuristic “smart” rewrites:

| Fix id | Behavior |
|---|---|
| `gitignore_env` | Ensure `.env` is listed in `.gitignore` |
| `env_bool_normalize` | Normalize a known env boolean |
| `pint` | Run Laravel Pint when installed |

```bash
php artisan wraith --fix --dry-run   # preview
php artisan wraith --fix             # apply (writes a backup)
php artisan wraith --restore          # undo last apply
```

---

## What Wraith checks

| Area | Examples |
|---|---|
| **Application** | Debug in production, missing `APP_KEY`, storage symlink, writable `storage/`, config/route caches, schedule/cron reminder |
| **Security** | Session cookies, HTTPS URL, `.env` exposure, composer/npm audit, Telescope/Horizon/Pulse/Debugbar in prod, trusted proxies, CORS `*`, Sanctum/session domains, public executables, abandoned packages |
| **Configuration** | Missing env keys, bad bools, `.env.example` drift, Composer PHP platform vs runtime |
| **Database** | Pending migrations, missing `down()`, PKs/FKs/indexes, secondary connections with localhost/empty password in prod |
| **Eloquent** | Mass assignment, soft-delete mismatches, cast gaps |
| **Routes** | Duplicates, unnamed routes, closures in prod, API throttle, login/password/OTP without throttle |
| **Performance** | sync/file/array drivers, mail/log filesystem choices, Redis prefixes, queue `retry_after` / `failed_jobs` |
| **Code quality** | Runs PHPStan & Pint when present |
| **Dynamic** (opt-in) | N+1 / duplicate / slow queries via GET route replay |

### Dynamic mode

```bash
php artisan wraith --dynamic
```

Makes **real GET requests** to your app. Allowed by default only in `local` / `testing`. Outside those environments, pass `--force-dynamic`. Framework tooling routes (Telescope, Horizon, Livewire, etc.) are excluded by default. Prefer a disposable environment.

---

## What Wraith is not

| Wraith | Not Wraith |
|---|---|
| One-shot diagnostic audit | Continuous APM (Telescope, Pulse, New Relic) |
| Local or CI gate | Always-on health ping |
| Actionable findings + suggested fixes | Full substitute for PHPStan (it can wrap it) |
| Config / schema / security focus | Query planners, lock analysis, invented indexes |

---

## Compatibility

| Laravel | PHP |
|---|---|
| 8 | 7.3–8.1 |
| 9 | 8.0–8.2 |
| 10 | 8.1+ |
| 11–12 | 8.2+ |

---

## License & docs

MIT — [LICENSE.md](LICENSE.md) · [CHANGELOG.md](CHANGELOG.md) · [CONTRIBUTING.md](CONTRIBUTING.md) · [SECURITY.md](SECURITY.md)

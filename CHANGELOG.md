# Changelog

All notable changes to `sdpayhub/laravel-wraith` will be documented in this file.

## [0.2.0] — 2026-07-31

### Added
- Real-world production checks: exposed Telescope/Horizon/Pulse/Debugbar, trusted proxies, CORS wildcards, Sanctum/session domain alignment, public-disk executables, abandoned packages
- Mail/filesystem/log driver sanity, Redis prefix warnings, queue retry_after/timeout + failed_jobs table
- `.env.example` drift, Composer PHP platform/runtime mismatch
- Auth route throttling (login/password/OTP), storage permissions, schedule/cron reminder, secondary DB localhost/empty password

## [0.1.1] — 2026-07-31

### Changed
- README rewritten for first-time users: quick start, sample report, how to read scores
- Terminal output now includes a short legend and next-step tips

## [0.1.0] — 2026-07-27

### Added
- Initial release: analyzer pipeline, Finding/Report DTOs, `wraith` Artisan command
- Analyzers: application, security (composer + npm/pnpm audit), configuration, database schema, eloquent, routes, performance/assets, code quality wrappers
- Reporters: terminal, JSON, HTML, Markdown
- Configurable transparent scoring
- Enumerated `--fix` / `--dry-run` / `--restore`
- Opt-in `--dynamic` query pattern analyzer
- CI matrix support for Laravel 8–12

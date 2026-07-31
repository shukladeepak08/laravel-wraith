# Changelog

All notable changes to `sdpayhub/laravel-wraith` will be documented in this file.

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

# Contributing

Thanks for helping maintain Wraith.

## Principles

1. **Static by default.** Anything with side effects belongs behind `--dynamic` or an explicit opt-in.
2. **No scope creep into APM.** Continuous metrics belong in Pulse/Telescope/Health.
3. **Wrap, don’t reimplement** PHPStan, Pint, `composer audit`, `npm audit`, secret scanners.
4. **Every `--fix` must be enumerable** in the README before it ships.
5. **PHP 7.3-compatible** package source (no enums/readonly/union types as the default style).

## Setup

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

## Adding an analyzer

1. Implement `SdPayHub\Wraith\Contracts\Analyzer` (or `DynamicAnalyzer`).
2. Register the class in `config/wraith.php`.
3. Add unit tests covering decision branches.
4. Document the category/checks in the README.
5. Mark version-sensitive framework touches with a `Version-sensitive:` comment.

## Pull requests

- Keep PRs focused (one analyzer or one subsystem).
- Include tests.
- Update CHANGELOG under Unreleased / next version.

# Security Policy

## Reporting a vulnerability in Wraith itself

If you discover a security issue **in this package** (not in an application Wraith audits), please report it privately via one of:

1. **GitHub Security Advisories** (preferred): [Report a vulnerability](https://github.com/shukladeepak08/laravel-wraith/security/advisories/new)
2. Open a private report through the repository Security tab if the form is available

Include:

- Description of the issue
- Steps to reproduce
- Affected versions if known
- Any suggested fix

Do not open a public GitHub issue for vulnerabilities until a fix is available.

We aim to acknowledge reports within 5 business days.

## What Wraith does not cover

Wraith wraps `composer audit` / `npm audit` and suggests tools like gitleaks. It does **not** maintain its own vulnerability database or secret-scanning engine. Report dependency CVEs upstream; report secret-scanner gaps to those projects.

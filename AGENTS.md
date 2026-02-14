# AGENTS.md

## Dependency-aware verification for entrypoint audits (2-γ-1)

### 0) Scope (entrypoint only)

This audit covers ONLY the HTTP entrypoints (routes + middleware + controller validation/response).
Do NOT deep-audit internal scheduling/dispatch logic in 2-γ-1.

Expected minimal behaviors:

- Unauthenticated requests MUST return **401 JSON** (no redirect to /login).
- Authenticated requests MUST return **success** (200/201) and enforce **validation**.

---

### 1) Vendor assumption + install gate

- Assume `vendor/` is **NOT** committed to the repo.
- Before any Laravel CLI proof, check whether dependencies exist:
    - `test -f vendor/autoload.php && echo HAS_VENDOR || echo NO_VENDOR`

If `vendor/autoload.php` is missing:

- Try installing dependencies **if allowed by the environment**.

---

### 2) Environment preflight (avoid dead ends)

Run these first and paste outputs:

- `php -v`
- `composer -V`
- `test -f vendor/autoload.php && echo HAS_VENDOR || echo NO_VENDOR`

If composer is missing or broken:

- State clearly that CLI-based proofs cannot be produced, then proceed to **static evidence fallback** (Section 4).

---

### 3) Test / verification commands (preferred order)

#### A) Install dependencies (when allowed)

- `composer install --no-interaction --prefer-dist --no-progress`

If install succeeds, confirm:

- `php -r "require 'vendor/autoload.php'; echo 'autoload_ok'.PHP_EOL;"`

#### B) Route/middleware proof (strong evidence)

- `php artisan --version`
- `php artisan route:list --path=api/v1 --columns=method,uri,name,action,middleware`

#### C) Minimal tests (entrypoint-only)

Prefer `php artisan test` over calling phpunit directly.

If a targeted test exists:

- `php artisan test --testsuite=Feature --filter=PushSubscription`

Otherwise (only if time/resources allow):

- `php artisan test`

If tests cannot run due to environment constraints, explain why and provide the best available CLI proof (route:list + static checks).

---

### 4) Network-restricted environments (explicit branching)

If the environment has network restrictions:

- First, you MAY attempt `composer install` once, because it could succeed using a cache.
- If it fails with download/network errors:
    - DO NOT keep retrying.
    - Explicitly state that dependencies cannot be downloaded in this environment.
    - Then proceed with **static evidence fallback**.

If vendor is missing and cannot be installed:

- You MUST explicitly state that `artisan` commands and tests cannot be executed.
- Provide static evidence only:
    - Routes declared in `routes/api.php`
    - Controller/service/model file presence
    - Middleware configuration (`bootstrap/app.php` or `app/Http/Kernel.php` / middleware aliases)
    - Presence of tests (even if not runnable)

---

### 5) Why this matters (auditor reasoning)

- With vendor available, `route:list` is strong proof of actual registered endpoints and effective middleware.
- With tests runnable, we can confirm minimal behaviors (401 JSON / 200+ / validation) even for entrypoint-only scope.

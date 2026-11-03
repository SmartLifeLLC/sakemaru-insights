# AGENTS.md

## Critical Database Safety

Never run commands that reset, wipe, or recreate the database:

```bash
php artisan migrate:fresh
php artisan migrate:refresh
php artisan migrate:reset
php artisan db:wipe
```

These commands are disabled via `app/Console/Commands/Prevent*.php` command overrides. Do not execute them for verification.

Tests must not use `RefreshDatabase`. `phpunit.xml` and `.env.testing` must point to dedicated test databases. Use `DatabaseTransactions`, or narrowly scoped `delete` / `truncate` cleanup for test data.

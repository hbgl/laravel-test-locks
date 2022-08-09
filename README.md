# Laravel test locks

This repo has a single command to verify that [atomic locks](https://laravel.com/docs/9.x/cache#atomic-locks) in Laravel provide mutual exclusion.

```bash
php artisan app:test-locks {--workers=2} {--iterations=1000} {--cache=} {--seconds=0}
```

This command starts worker processes that each increment a shared variable in redis $iteration number of times while holding an atomic lock from the given $cache provider. The program verifies that the final value of the shared variable equals $workers times $iterations. If the value is less than that, then it indicates a faulty lock implementation.

Requirements:
- You need to have a working redis connection.
- Having as many physical CPU-cores as workers.

Examples:

```bash
php artisan app:test-locks --workers=2 --iterations=1000 --cache-driver=redis
php artisan app:test-locks --workers=2 --iterations=1000 --cache-driver=file
php artisan app:test-locks --workers=2 --iterations=1000 --cache-driver=file --seconds 999999
```
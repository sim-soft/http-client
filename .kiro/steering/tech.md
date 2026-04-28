# Tech Stack & Build System

## Language & Runtime

- PHP 8.1+
- ext-curl (required)

## Package Manager

- Composer

## Dependencies (Runtime)

- `psr/http-message` ^1.1|^2.0
- `psr/http-client` ^1.0
- `psr/http-factory` ^1
- `league/oauth2-client` ^2

## Dependencies (Dev)

- `phpunit/phpunit` ^11 — Unit testing
- `phpmd/phpmd` ^2 — Mess detector (code quality rules)
- `phpstan/phpstan` ^1 — Static analysis (level 8)
- `squizlabs/php_codesniffer` ^4 — Code style checking
- `steos/quickcheck` ^2.0 — Property-based testing

## Common Commands

| Task                    | Command                                                            |
|-------------------------|--------------------------------------------------------------------|
| Run tests               | `composer test` (runs `phpunit --use-baseline baseline.xml tests`) |
| Static analysis + PHPMD | `composer qc`                                                      |
| PHPStan only            | `vendor/bin/phpstan analyse --memory-limit=512M`                   |
| PHPMD only              | `vendor/bin/phpmd src text phpmd.xml`                              |
| Code sniffer            | `vendor/bin/phpcs`                                                 |

## Static Analysis

- PHPStan level 8 (strictest useful level), scans both `src/` and `tests/`
- PHPMD with custom ruleset in `phpmd.xml`

## Testing

- PHPUnit 11 with a baseline file (`baseline.xml`) for suppressing known
  deprecations
- Tests use `#[Test]` attributes (not `@test` annotations)
- Property-based tests via `steos/quickcheck`

## Autoloading (PSR-4)

- `Simsoft\HttpClient\` → `src/`
- `Simsoft\HttpClient\Tests\` → `tests/`

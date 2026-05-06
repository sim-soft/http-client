# Coding Standards & Conventions

## PSR Compliance (Mandatory)

- **PSR-1**: Basic coding standard
- **PSR-12**: Extended coding style (replaces PSR-2)
- **PSR-3**: Logger interface (when logging is involved)
- **PSR-11**: Container interface (when DI containers are involved)
- **PSR-13**: Hypermedia links (when link relations are involved)

## Design Principles

- **SOLID**: All classes must follow Single Responsibility, Open/Closed, Liskov
  Substitution, Interface Segregation, and Dependency Inversion.
- **GRASP**: Apply General Responsibility Assignment Software Patterns (
  Information Expert, Creator, Controller, Low Coupling, High Cohesion).
- **Framework-independent**: All solutions must be standalone — no framework
  coupling.

## Naming Rules (Strict)

- Variable names: minimum 3 characters
- Method names: minimum 5 characters
- Classes: PascalCase
- Methods/properties/variables: camelCase
- Constants: UPPER_SNAKE_CASE

## PHPDoc Requirements

- All classes MUST have a class-level docblock describing purpose
- All public/protected methods MUST have full PHPDoc blocks:
    - `@param` with type and description
    - `@return` with type
    - `@throws` when applicable
- Use latest PHPDoc standard (typed properties still get `@var` annotations for
  complex types)

## Code Quality Rules

- Must pass PHPMD (`phpmd.xml` ruleset)
- Must pass PHPStan level 8
- No `else` expressions — use early returns/guard clauses
- No boolean argument flags (except whitelisted methods: `__construct`, `parse`,
  `formal`, `make`, `lookup`)
- No unused code (private fields, methods, variables, parameters)

## Model/Data Encapsulation

- Model attributes must only be accessed within the model itself
- Direct external access to model attributes is prohibited
- Query builders must be implemented inside the model to avoid exposing
  attributes
- If MVC structure exists: fat model, thin controller — logic decisions in
  controller only

## Documentation Requirements

- All new classes, libraries, or helper functions MUST include a usage guide
- Documentation saved as markdown in `docs/`
- Must include example usage demonstrating common scenarios

## Unit Testing Policy

- **Always ask permission** before creating unit tests
- When tests are approved, provide as many scenarios as possible
- Tests use PHPUnit 11 with `#[Test]` attributes
- Test class names mirror source structure (e.g., `src/Foo.php` →
  `tests/FooTest.php`)
- Use `@SuppressWarnings` annotations when PHPMD limits are intentionally
  exceeded in test classes

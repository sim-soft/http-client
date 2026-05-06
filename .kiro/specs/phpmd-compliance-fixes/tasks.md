# Implementation Plan: PHPMD Compliance Fixes

## Overview

Refactor the `simsoft/http-client` library to eliminate all PHPMD violations
while preserving full backward compatibility (186 tests, PHPStan level 8,
identical public API). The approach follows trait extraction, complexity
reduction, and targeted annotation fixes.

## Tasks

- [x]
    1. Create RequestBodyTrait and extract request body methods

    - [x] 1.1 Create `src/Traits/RequestBodyTrait.php` with fields
      `$postFields`, `$postFieldsOwned`, `$contentType` and methods:
      `withBody()`, `withBodyStream()`, `withJson()`, `withForm()`, `withRaw()`,
      `withMultipart()`, `withGraphQL()`, `asJson()`, `asForm()`,
      `asMultipart()`, `asRaw()`
        - Move all request body preparation logic from `HttpClient` into the new
          trait
        - Content type constants (`TYPE_JSON`, `TYPE_FORM`, `TYPE_MULTIPART`,
          `TYPE_RAW`) remain on `HttpClient`
        - Include proper PHPDoc blocks and `@var` annotations for all moved
          fields
        - _Requirements: 1.2, 1.3, 2.1, 2.2, 3.1, 3.2, 3.3, 4.1_

    - [x] 1.2 Add `use RequestBodyTrait` to `HttpClient` and remove the
      extracted methods and fields from the class body
        - Remove `$postFields`, `$postFieldsOwned`, `$contentType` field
          declarations from `HttpClient`
        - Remove all 11 method bodies that were moved to the trait
        - Verify the `use` statement is added alongside existing trait imports
        - _Requirements: 1.2, 1.3, 2.1, 3.1, 3.2_

    - [x] 1.3 Write unit tests to verify RequestBodyTrait extraction
        - Run `composer test` to confirm all 186 tests still pass after
          extraction
        - Run `vendor/bin/phpstan analyse --memory-limit=512M` to confirm zero
          errors
        - _Requirements: 16.1, 16.2_

- [x]
    2. Create AttachmentTrait and extract attachment methods

    - [x] 2.1 Create `src/Traits/AttachmentTrait.php` with fields
      `$hasAttachments`, `$tmpFiles` and methods: `attach()`,
      `normalizeAttachment()`, `createTempFile()`
        - Move `attach()`, `normalizeAttachment()`, and `createTempFile()` from
          `HttpClient`
        - Move `$hasAttachments` and `$tmpFiles` field declarations
        - _Requirements: 1.2, 2.1, 2.2, 3.1_

    - [x] 2.2 Decompose `normalizeAttachment()` to reduce cyclomatic complexity
        - Extract `normalizeResourceAttachment()` — handles `is_resource()` case
        - Extract `normalizeFilePathAttachment()` — handles file path string
          case
        - Extract `normalizeRawStringAttachment()` — handles raw string case
        - Main `normalizeAttachment()` becomes a dispatcher: CURLFile
          passthrough, then delegates to helpers
        - Each helper returns `CURLFile` or throws on error
        - Target: cyclomatic complexity ≤ 10, NPath complexity ≤ 200
        - _Requirements: 7.1, 7.2, 7.3_

    - [x] 2.3 Add `use AttachmentTrait` to `HttpClient` and remove extracted
      methods/fields
        - Remove `$hasAttachments`, `$tmpFiles` from `HttpClient`
        - Remove `attach()`, `normalizeAttachment()`, `createTempFile()` from
          `HttpClient`
        - _Requirements: 1.2, 2.1, 3.1_

    - [x] 2.4 Write property test for attachment normalization
        - **Property 1: Attachment normalization preserves file content**
        - **Validates: Requirements 7.3**
        - Use `steos/quickcheck` to generate random file content (strings of
          varying length)
        - For each generated content: write to temp file, normalize via each
          input type (file path, resource, raw string), verify CURLFile
          references a file with identical bytes
        - Tag:
          `Feature: phpmd-compliance-fixes, Property 1: Attachment normalization preserves file content`
        - Minimum 100 iterations

- [x]
    3. Create SinkTrait and split sink() to remove boolean flag

    - [x] 3.1 Create `src/Traits/SinkTrait.php` with fields `$sink`,
      `$sinkPath`, `$sinkOwned` and methods: `sink()`, `sinkStream()`,
      `writeToSink()`
        - `sink(mixed $destination)` — file-based download (CURLOPT_FILE), no
          boolean parameter
        - `sinkStream(mixed $destination)` — stream-based download (
          CURLOPT_WRITEFUNCTION)
        - Both methods share the destination validation logic (resource or
          string path)
        - `writeToSink()` moved as-is
        - Eliminate the `$streamOnly` boolean flag argument from `sink()`
        - _Requirements: 8.1, 8.2, 8.3_

    - [x] 3.2 Add `use SinkTrait` to `HttpClient` and remove extracted
      methods/fields
        - Remove `$sink`, `$sinkPath`, `$sinkOwned` from `HttpClient`
        - Remove `sink()`, `writeToSink()` from `HttpClient`
        - Update any internal calls from `sink($dest, true)` to
          `sinkStream($dest)` if present
        - _Requirements: 1.2, 2.1, 3.1, 8.1_

    - [x] 3.3 Write unit tests to verify SinkTrait extraction and
      sink/sinkStream split
        - Run `composer test` to confirm all 186 tests still pass
        - Verify `sinkStream()` produces identical behavior to old
          `sink($dest, true)`
        - _Requirements: 8.2, 8.3, 16.1_

- [x]
    4. Checkpoint — Verify trait extractions

    - Ensure all tests pass, ask the user if questions arise.
    - Run `composer test` (186 tests, 358 assertions)
    - Run `vendor/bin/phpstan analyse --memory-limit=512M` (zero errors)

- [x]
    5. Activate existing traits (RetryTrait, PrepareHandleTrait)

    - [x] 5.1 Add `use RetryTrait` to `HttpClient` and remove duplicate
      retry/wait/shouldRetry methods and fields
        - Remove `$retry`, `$retryAfter`, `$retryCallback` field declarations
          from `HttpClient`
        - Remove `retry()`, `retryWhen()`, `shouldRetry()`, `wait()` method
          bodies from `HttpClient`
        - Add `use RetryTrait` to the trait import list
        - _Requirements: 1.2, 2.1, 3.1, 3.2_

    - [x] 5.2 Add `use PrepareHandleTrait` to `HttpClient` and remove the
      monolithic `prepareHandle()` method
        - Remove the entire `prepareHandle()` method from `HttpClient` (the
          trait version replaces it)
        - Add `use PrepareHandleTrait` to the trait import list
        - Verify `buildFormattedHeaders()` and `flattenMultipartData()` remain
          accessible (they stay on `HttpClient`)
        - _Requirements: 1.1, 1.2, 3.1, 10.1, 10.2, 10.3, 10.4_

    - [x] 5.3 Write unit tests to verify RetryTrait and PrepareHandleTrait
      activation
        - Run `composer test` to confirm all 186 tests still pass
        - Run `vendor/bin/phpstan analyse --memory-limit=512M` to confirm zero
          errors
        - _Requirements: 16.1, 16.2_

- [x]
    6. Fix naming violations and add suppression annotations

    - [x] 6.1 Add `@SuppressWarnings(PHPMD.ShortMethodName)` to
      `HttpClient::to()` method
        - Add the annotation to the method-level docblock
        - The `to()` name is an intentional fluent API convention
        - _Requirements: 5.1, 5.2_

    - [x] 6.2 Rename short variable `$v` to `$val` in `HttpClient::withHeader()`
      lambda
        - Change `fn($v) => (string)$v` to `fn($val) => (string)$val`
        - Minimum 2 characters per PHPMD ShortVariable rule (3 per project
          convention)
        - _Requirements: 6.1, 6.2_

    - [x] 6.3 Add `@SuppressWarnings(PHPMD.ShortMethodName)` to
      `DebugTrait::dd()` method
        - Add the annotation to the method-level docblock
        - `dd()` is a widely recognized dump-and-die convention
        - _Requirements: 13.1, 13.3_

    - [x] 6.4 Add `@SuppressWarnings(PHPMD.ExitExpression)` to
      `DebugTrait::debugDump()` method
        - Add the annotation to the method-level docblock
        - The `exit` call is the intentional behavior of the dump-and-die helper
        - _Requirements: 13.2_

- [x]
    7. Eliminate else expressions across all source files

    - [x] 7.1 Refactor `HttpClient::flush()` to eliminate `elseif` chains
        - Replace `elseif` chain for `$postFields` cleanup with sequential
          `if` + early processing
        - Use guard clauses and early returns where applicable
        - _Requirements: 11.1, 11.2, 11.3_

    - [x] 7.2 Refactor `HttpClient::sink()` (now in SinkTrait) to eliminate
      `elseif`
        - Replace `elseif (is_string($destination))` with guard clause pattern
        - Validate this was already handled during SinkTrait creation; if not,
          fix now
        - _Requirements: 11.1, 11.2, 11.3_

    - [x] 7.3 Refactor `PrepareHandleTrait` to eliminate any remaining `else`
      blocks
        - Check all methods in the trait for `else`/`elseif` usage
        - Replace with early returns or guard clauses
        - _Requirements: 14.2, 14.3_

    - [x] 7.4 Refactor `CurlOptionsTrait` to eliminate any remaining `else`
      blocks
        - Check `applyTransferOptions()` and other methods for `else` usage
        - Replace with early returns or guard clauses
        - _Requirements: 14.1, 14.3_

    - [x] 7.5 Scan all remaining `src/` files for `else`/`elseif` and refactor
        - Check `Response.php`, exception classes, stream classes, OAuth2
          clients
        - Replace any remaining else expressions with guard clauses
        - _Requirements: 11.1, 11.2, 11.3_

- [x]
    8. Reduce complexity of `getCoreHandler()`

    - [x] 8.1 Extract helper methods from `getCoreHandler()` to reduce
      cyclomatic complexity
        - Extract header capture setup into
          `setupHeaderCapture(CurlHandle $curl): Closure`
        - Extract response construction into `buildResponse(...)` helper
        - Extract retry decision logic into a helper or simplify inline
        - Use early returns to reduce nesting depth
        - Target: cyclomatic complexity ≤ 10
        - _Requirements: 12.1, 12.2_

- [x]
    9. Fix unused formal parameters with suppression annotations

    - [x] 9.1 Add `@SuppressWarnings(PHPMD.UnusedFormalParameter)` to cURL
      callback closures in `PrepareHandleTrait`
        - The `$ch` and `$fd` parameters in `CURLOPT_READFUNCTION` callback are
          required by the cURL API but unused
        - Add suppression annotation to the closure or enclosing method
        - _Requirements: 15.1, 15.2_

    - [x] 9.2 Add `@SuppressWarnings(PHPMD.UnusedFormalParameter)` to cURL
      callback in `getCoreHandler()`
        - The `$ch` parameter in `CURLOPT_HEADERFUNCTION` callback is required
          by cURL API but unused
        - Add suppression annotation to the closure or enclosing method
        - _Requirements: 9.1, 9.2, 9.3_

    - [x] 9.3 Scan all `src/` files for remaining UnusedFormalParameter
      violations and fix
        - Check `writeToSink()` `$ch` parameter
        - Add targeted suppression annotations only for parameters required by
          external API contracts
        - _Requirements: 9.1, 9.2, 9.3_

- [x]
    10. Checkpoint — Verify all PHPMD fixes

    - Ensure all tests pass, ask the user if questions arise.
    - Run `composer test` (186 tests, 358 assertions)
    - Run `vendor/bin/phpstan analyse --memory-limit=512M` (zero errors)
    - Run `vendor/bin/phpmd src text phpmd.xml` (zero violations)

- [x]
    11. Property-based test for request preparation

    - [x] 11.1 Write property test for request preparation cURL configuration
        - **Property 2: Request preparation produces correct cURL configuration
          **
        - **Validates: Requirements 10.4, 11.2**
        - Use `steos/quickcheck` to generate random request configurations (
          method from {GET, POST, PUT, PATCH, DELETE}, non-empty URL, arbitrary
          headers, body types)
        - Verify: CURLOPT_URL contains correct URL, method option matches,
          headers include user-agent and x-request-id, body options set
          correctly
        - Tag:
          `Feature: phpmd-compliance-fixes, Property 2: Request preparation produces correct cURL configuration`
        - Minimum 100 iterations

- [x]
    12. Final verification — Full compliance check

    - [x] 12.1 Run complete test suite and static analysis
        - Run `composer test` — all 186 tests pass with 358 assertions
        - Run `vendor/bin/phpstan analyse --memory-limit=512M` — zero errors at
          level 8
        - Run `vendor/bin/phpmd src text phpmd.xml` — zero violations
        - Verify `HttpClient` field count ≤ 15 (use ReflectionClass)
        - Verify `HttpClient` line count < 1000
        - _Requirements: 16.1, 16.2, 16.3, 1.1, 2.1_

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation after major refactoring phases
- Property tests validate universal correctness properties from the design
  document
- Unit tests validate specific examples and edge cases via the existing 186-test
  suite
- Content type constants (`TYPE_JSON`, `TYPE_FORM`, `TYPE_MULTIPART`,
  `TYPE_RAW`) remain on `HttpClient` as public API
- The `buildFormattedHeaders()` and `flattenMultipartData()` helper methods
  remain on `HttpClient` since they are used by both the class and traits

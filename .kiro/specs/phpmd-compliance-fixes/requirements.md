# Requirements Document

## Introduction

This specification covers the refactoring of the `simsoft/http-client` library
source code to eliminate all PHPMD (PHP Mess Detector) violations reported by
`phpmd src text phpmd.xml`. The refactoring must preserve full backward
compatibility: all 186 existing tests must continue to pass, and PHPStan level 8
must remain clean with zero errors.

## Glossary

- **HttpClient**: The main HTTP client class located at `src/HttpClient.php`
  responsible for building and executing cURL-based HTTP requests.
- **Response**: The HTTP response wrapper class located at `src/Response.php`
  implementing PSR-7 `ResponseInterface`.
- **PHPMD**: PHP Mess Detector — a static analysis tool that detects code
  smells, complexity issues, and naming violations.
- **Trait**: A PHP mechanism for horizontal code reuse, used here to decompose
  the HttpClient class into focused concerns.
- **CurlOptionsTrait**: Existing trait managing cURL handle lifecycle and option
  preparation.
- **PrepareHandleTrait**: Existing trait containing the decomposed
  `prepareHandle()` logic.
- **DebugTrait**: Existing trait providing request dumping and debugging
  helpers.
- **PHPStan**: A static analysis tool for PHP that checks type correctness at
  configurable strictness levels.
- **EARS**: Easy Approach to Requirements Syntax — a pattern-based method for
  writing unambiguous requirements.

## Requirements

### Requirement 1: Reduce HttpClient Class Size

**User Story:** As a maintainer, I want the HttpClient class to stay within
PHPMD's line-count threshold, so that the class remains comprehensible and
maintainable.

#### Acceptance Criteria

1. WHEN PHPMD analyzes `src/HttpClient.php`, THE HttpClient class SHALL have
   fewer than 1000 lines of code.
2. THE HttpClient class SHALL extract cohesive groups of methods into new traits
   without changing the public API.
3. WHEN methods are extracted to traits, THE HttpClient class SHALL use those
   traits to preserve all existing functionality.

### Requirement 2: Reduce HttpClient Field Count

**User Story:** As a maintainer, I want the HttpClient class to declare no more
than 15 fields directly, so that PHPMD's TooManyFields rule passes.

#### Acceptance Criteria

1. WHEN PHPMD analyzes `src/HttpClient.php`, THE HttpClient class SHALL declare
   at most 15 fields (properties) directly in the class body.
2. WHEN fields are moved to traits, THE HttpClient class SHALL retain access to
   those fields through trait usage.

### Requirement 3: Reduce HttpClient Method Count

**User Story:** As a maintainer, I want the HttpClient class to have at most 25
non-getter/setter methods and at most 10 public methods counted directly, so
that TooManyMethods and TooManyPublicMethods rules pass.

#### Acceptance Criteria

1. WHEN PHPMD analyzes `src/HttpClient.php`, THE HttpClient class SHALL have at
   most 25 non-getter/setter methods.
2. WHEN PHPMD analyzes `src/HttpClient.php`, THE HttpClient class SHALL have at
   most 10 public methods.
3. WHEN methods are moved to traits, THE HttpClient class SHALL preserve the
   same public interface accessible to consumers.

### Requirement 4: Reduce HttpClient Coupling Between Objects

**User Story:** As a maintainer, I want the HttpClient class to depend on at
most 14 other types, so that the CouplingBetweenObjects rule passes.

#### Acceptance Criteria

1. WHEN PHPMD analyzes `src/HttpClient.php`, THE HttpClient class SHALL have a
   coupling value of at most 14.
2. WHEN imports are redistributed to traits, THE HttpClient class SHALL retain
   the same runtime behavior.

### Requirement 5: Fix Short Method Name Violation for `to()`

**User Story:** As a maintainer, I want all method names to meet the minimum
3-character length, so that the ShortMethodName rule passes.

#### Acceptance Criteria

1. WHEN PHPMD analyzes `src/HttpClient.php`, THE HttpClient class SHALL suppress
   the ShortMethodName warning for the `to()` method using a PHPMD annotation,
   since `to()` is a well-known fluent API name that is intentionally short.
2. THE `to()` method SHALL remain callable with the same signature and behavior.

### Requirement 6: Fix Short Variable Name Violation

**User Story:** As a maintainer, I want all variable names to be at least 2
characters long, so that the ShortVariable rule passes.

#### Acceptance Criteria

1. WHEN PHPMD analyzes `src/HttpClient.php`, THE HttpClient class SHALL use
   variable names of at least 2 characters in all methods.
2. THE renamed variable SHALL preserve the same semantics and type as the
   original `$v` variable.

### Requirement 7: Reduce Cyclomatic Complexity of `normalizeAttachment()`

**User Story:** As a maintainer, I want `normalizeAttachment()` to have a
cyclomatic complexity of at most 10, so that the CyclomaticComplexity rule
passes.

#### Acceptance Criteria

1. WHEN PHPMD analyzes the method containing attachment normalization logic, THE
   method SHALL have a cyclomatic complexity of at most 10.
2. WHEN PHPMD analyzes the method containing attachment normalization logic, THE
   method SHALL have an NPath complexity of at most 200.
3. THE refactored attachment normalization logic SHALL produce identical
   CURLFile or string results for all input types (CURLFile, resource, file path
   string, raw string).

### Requirement 8: Remove Boolean Flag Argument from `sink()`

**User Story:** As a maintainer, I want the `sink()` method to avoid boolean
flag arguments, so that the BooleanArgumentFlag rule passes.

#### Acceptance Criteria

1. WHEN PHPMD analyzes the sink-related methods, THE methods SHALL not accept
   boolean flag arguments.
2. THE refactored sink API SHALL provide equivalent functionality for both
   file-based and stream-based download modes.
3. THE refactored sink API SHALL remain backward compatible or provide a clear
   migration path.

### Requirement 9: Remove Unused Formal Parameters

**User Story:** As a maintainer, I want all method parameters to be used or
properly suppressed, so that the UnusedFormalParameter rule passes.

#### Acceptance Criteria

1. WHEN PHPMD analyzes all source files, THE source code SHALL have zero
   UnusedFormalParameter violations.
2. WHEN cURL callback signatures require parameters mandated by the cURL API,
   THE callbacks SHALL suppress the PHPMD warning using an annotation or use the
   parameters.
3. THE suppression annotations SHALL only be applied to parameters that are
   genuinely required by external API contracts (cURL callback signatures).

### Requirement 10: Reduce Complexity of `prepareHandle()` in HttpClient

**User Story:** As a maintainer, I want the monolithic `prepareHandle()` method
in HttpClient to be decomposed, so that CyclomaticComplexity, NPathComplexity,
and ExcessiveMethodLength rules pass.

#### Acceptance Criteria

1. WHEN PHPMD analyzes the request preparation logic, THE `prepareHandle()`
   method SHALL have a cyclomatic complexity of at most 10.
2. WHEN PHPMD analyzes the request preparation logic, THE `prepareHandle()`
   method SHALL have an NPath complexity of at most 200.
3. WHEN PHPMD analyzes the request preparation logic, THE `prepareHandle()`
   method SHALL have at most 100 lines.
4. THE decomposed preparation logic SHALL produce identical cURL handle
   configuration for all request types (GET, POST, PUT, PATCH, DELETE, streams,
   multipart, downloads).

### Requirement 11: Eliminate All Else Expressions

**User Story:** As a maintainer, I want all conditional logic to use early
returns or guard clauses instead of else blocks, so that the ElseExpression rule
passes.

#### Acceptance Criteria

1. WHEN PHPMD analyzes all source files, THE source code SHALL contain zero
   ElseExpression violations.
2. THE refactored conditionals SHALL produce identical outcomes for all input
   combinations.
3. THE refactored conditionals SHALL use early returns, guard clauses, or
   ternary expressions as replacements for else blocks.

### Requirement 12: Reduce Complexity of `getCoreHandler()`

**User Story:** As a maintainer, I want `getCoreHandler()` to have a cyclomatic
complexity of at most 10, so that the CyclomaticComplexity rule passes.

#### Acceptance Criteria

1. WHEN PHPMD analyzes `getCoreHandler()`, THE method SHALL have a cyclomatic
   complexity of at most 10.
2. THE refactored core handler SHALL preserve retry logic, error handling,
   header capture, and response construction behavior.

### Requirement 13: Fix DebugTrait ShortMethodName and ExitExpression

**User Story:** As a maintainer, I want the DebugTrait to pass PHPMD's naming
and design rules, so that ShortMethodName and ExitExpression violations are
resolved.

#### Acceptance Criteria

1. WHEN PHPMD analyzes `src/Traits/DebugTrait.php`, THE trait SHALL suppress the
   ShortMethodName warning for the `dd()` method using a PHPMD annotation, since
   `dd()` is a widely recognized debugging convention (dump-and-die).
2. WHEN PHPMD analyzes `src/Traits/DebugTrait.php`, THE trait SHALL suppress the
   ExitExpression warning for the `debugDump()` method using a PHPMD annotation,
   since the exit is the intentional behavior of a dump-and-die debugging
   helper.
3. THE `dd()` method SHALL retain its existing dump-and-die behavior.

### Requirement 14: Fix Remaining Trait ElseExpressions

**User Story:** As a maintainer, I want all trait files to be free of
ElseExpression violations, so that PHPMD reports zero violations across the
entire `src/` directory.

#### Acceptance Criteria

1. WHEN PHPMD analyzes `src/Traits/CurlOptionsTrait.php`, THE trait SHALL
   contain zero ElseExpression violations.
2. WHEN PHPMD analyzes `src/Traits/PrepareHandleTrait.php`, THE trait SHALL
   contain zero ElseExpression violations.
3. THE refactored trait conditionals SHALL produce identical behavior for all
   input states.

### Requirement 15: Fix Unused Parameters in PrepareHandleTrait

**User Story:** As a maintainer, I want all parameters in PrepareHandleTrait
callbacks to be properly handled, so that UnusedFormalParameter violations are
resolved.

#### Acceptance Criteria

1. WHEN PHPMD analyzes `src/Traits/PrepareHandleTrait.php`, THE trait SHALL have
   zero UnusedFormalParameter violations.
2. WHEN cURL callback signatures require parameters mandated by the cURL API,
   THE callbacks SHALL suppress the PHPMD warning using an appropriate
   annotation.

### Requirement 16: Preserve Test Suite and Static Analysis Compliance

**User Story:** As a maintainer, I want all refactoring to preserve existing
behavior, so that the test suite and PHPStan analysis remain green.

#### Acceptance Criteria

1. WHEN the test suite is executed after all refactoring, THE test suite SHALL
   pass all 186 tests with 358 assertions.
2. WHEN PHPStan is executed at level 8 after all refactoring, THE analysis SHALL
   report zero errors.
3. WHEN `phpmd src text phpmd.xml` is executed after all refactoring, THE
   analysis SHALL report zero violations.

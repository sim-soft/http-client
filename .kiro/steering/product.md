# Product Overview

Simsoft HttpClient is a standalone PHP HTTP client library built directly on the
`curl_*` extension. It provides a fluent, chainable API for making HTTP requests
with full PSR-7 (HTTP messages), PSR-18 (HTTP client), and PSR-17 factory
compliance.

## Key Capabilities

- Zero-dependency core (only requires ext-curl)
- Fluent chainable API for all HTTP methods (GET, POST, PUT, PATCH, DELETE)
- Built-in retry with exponential backoff and custom retry conditions
- Named middleware pipeline for request/response interception
- Memory-efficient streaming uploads/downloads via PSR-7 StreamInterface
- File attachments (CURLFile, paths, resources, raw strings)
- OAuth2 client support (via league/oauth2-client)
- Dot-notation and wildcard response data access
- Custom SDK/response class extensibility

## Target Users

- PHP developers building standalone microservices, CLI tools, or libraries that
  need HTTP capabilities without a full framework dependency.

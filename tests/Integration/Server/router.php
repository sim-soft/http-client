<?php

/**
 * Router for the PHP built-in server used by the integration suite.
 *
 * Started by IntegrationTestCase via `php -S 127.0.0.1:<port> router.php`.
 * Each endpoint exercises one transport behaviour the unit suite cannot reach,
 * because the unit suite never executes a transfer.
 *
 * Handlers are closures rather than named functions so that the file declares
 * no symbols: a script that both declares symbols and produces side effects
 * violates PSR-1, and this file is unavoidably a side effect.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// The CLI server is single-process — PHP_CLI_SERVER_WORKERS is POSIX-only and
// absent on Windows. A keep-alive connection therefore blocks it inside one
// connection's handler while the pool's remaining transfers wait on accept(),
// which surfaces as a client-side timeout rather than a refusal. Closing each
// connection returns the server to accept() immediately.
header('Connection: close');

/**
 * Collect request headers, which are not exposed uniformly by the CLI server.
 *
 * @var Closure(): array<string, string> $readHeaders
 */
$readHeaders = static function (): array {
    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (str_starts_with((string)$key, 'HTTP_')) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string)$key, 5)))));
            $headers[$name] = (string)$value;
        }
    }

    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['Content-Type'] = (string)$_SERVER['CONTENT_TYPE'];
    }

    return $headers;
};

/**
 * Emit a JSON body with an explicit content type.
 *
 * @var Closure(array<string, mixed>, int=): void $sendJson
 */
$sendJson = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
};

$body = file_get_contents('php://input') ?: '';

// A plain 200 echoing everything the client sent, for method, query and
// header assertions.
if ($path === '/echo') {
    $sendJson([
        'method' => $method,
        'query' => $_GET,
        'headers' => $readHeaders(),
        'body' => $body,
    ]);
    return true;
}

// Arbitrary status codes, for the Response predicate methods.
if (preg_match('#^/status/(\d{3})$#', $path, $matches) === 1) {
    http_response_code((int)$matches[1]);
    header('Content-Type: application/json');
    echo json_encode(['status' => (int)$matches[1]]);
    return true;
}

// 204, which must yield an empty body rather than a parsed one.
if ($path === '/no-content') {
    http_response_code(204);
    return true;
}

// A redirect chain of the requested depth, ending at /echo.
if (preg_match('#^/redirect/(\d+)$#', $path, $matches) === 1) {
    $remaining = (int)$matches[1];
    $target = $remaining > 1 ? '/redirect/' . ($remaining - 1) : '/echo';

    http_response_code(302);
    header('Location: ' . $target);
    return true;
}

// A body of the requested size, for streaming to a sink.
if (preg_match('#^/download/(\d+)$#', $path, $matches) === 1) {
    $size = min((int)$matches[1], 5_000_000);

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . $size);
    echo str_repeat('a', $size);
    return true;
}

// A response delayed past a short client timeout.
if ($path === '/slow') {
    usleep(((int)($_GET['ms'] ?? 1000)) * 1000);
    $sendJson(['slept' => true]);
    return true;
}

// Fails with 503 for the first N requests bearing a given token, then
// succeeds, so that retry behaviour can be observed end to end.
if ($path === '/flaky') {
    $token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['token'] ?? 'default'));
    $failures = (int)($_GET['failures'] ?? 2);
    $counterPath = sys_get_temp_dir() . '/simsoft-flaky-' . $token;

    $seen = is_file($counterPath) ? (int)file_get_contents($counterPath) : 0;
    file_put_contents($counterPath, (string)($seen + 1));

    if ($seen < $failures) {
        $sendJson(['attempt' => $seen + 1, 'failed' => true], 503);
        return true;
    }

    $sendJson(['attempt' => $seen + 1, 'failed' => false]);
    return true;
}

// Echoes the Authorization header, for credential-forwarding assertions.
if ($path === '/auth') {
    $sendJson(['authorization' => $readHeaders()['Authorization'] ?? null]);
    return true;
}

// Reports what the server parsed from a multipart or form-encoded body.
if ($path === '/upload') {
    $files = [];

    foreach ($_FILES as $field => $file) {
        $files[$field] = [
            'name' => $file['name'],
            'size' => $file['size'],
            'content' => is_uploaded_file((string)$file['tmp_name'])
                ? file_get_contents((string)$file['tmp_name'])
                : null,
        ];
    }

    $sendJson(['post' => $_POST, 'files' => $files]);
    return true;
}

// A slow trickle that keeps the connection open, used to prove a pool run
// overlaps its transfers rather than serialising them.
if ($path === '/chunked') {
    header('Content-Type: text/plain');
    $chunks = min((int)($_GET['chunks'] ?? 3), 20);

    for ($index = 0; $index < $chunks; $index++) {
        echo 'chunk' . $index . "\n";
        flush();
        usleep(50_000);
    }

    return true;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'not found', 'path' => $path]);
return true;

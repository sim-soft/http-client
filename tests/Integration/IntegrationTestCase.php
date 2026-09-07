<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Base class for tests that perform real HTTP transfers.
 *
 * Starts a PHP built-in server on the loopback interface for each test and
 * tears it down afterwards. No external network access is required.
 *
 * The rest of the test suite never executes a transfer — it asserts on cURL
 * options before execution — so nothing there covers response parsing,
 * redirect following, sink streaming or concurrent transfers against a real
 * socket. These tests close that gap.
 *
 * One server per test, rather than one per class, is deliberate. The CLI
 * server is single-process: PHP_CLI_SERVER_WORKERS is POSIX-only and has no
 * effect on Windows. A long-lived instance stops accepting connections after
 * roughly three dozen requests, which surfaces as a 30-second client timeout
 * in whichever test happens to run last and looks like a library defect. A
 * fresh server costs about 0.2 seconds and removes that shared state.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** @var resource|null Handle for the server process. */
    private $process = null;

    /** @var array<int, resource> Pipes opened for the server process. */
    private array $pipes = [];

    /** @var int Port the server is listening on, 0 before it starts. */
    private int $port = 0;

    /**
     * Start a server for the test about to run.
     *
     * @return void
     * @throws RuntimeException When the server cannot be started.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->port = $this->findFreePort();

        $command = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $this->port,
            escapeshellarg(__DIR__ . '/Server/router.php')
        );

        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the built-in server.');
        }

        $this->process = $process;
        $this->pipes = $pipes;

        $this->waitForServer();
    }

    /**
     * Stop the server started for this test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);

            // The pipes must be closed before proc_close(), which otherwise
            // blocks indefinitely on Windows waiting for a process whose
            // stdout still has an open reader. Terminating does not release
            // them.
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_close($this->process);
        }

        $this->process = null;
        $this->pipes = [];
        $this->port = 0;

        parent::tearDown();
    }

    /**
     * Build an absolute URL against the running server.
     *
     * @param string $path Path beginning with a slash.
     * @return string
     */
    protected function serverUrl(string $path = '/'): string
    {
        return sprintf('http://127.0.0.1:%d%s', $this->port, $path);
    }

    /**
     * The origin the server is listening on, without a trailing slash.
     *
     * @return string
     */
    protected function serverBase(): string
    {
        return sprintf('http://127.0.0.1:%d', $this->port);
    }

    /**
     * Reserve a free port by binding to port 0 and reading what was assigned.
     *
     * A race remains between closing the socket and the server binding, which
     * no portable API removes; it is vanishingly unlikely on a loopback
     * interface and would surface as a startup failure rather than a silent
     * pass.
     *
     * @return int
     * @throws RuntimeException When no local port can be bound.
     */
    private function findFreePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            throw new RuntimeException(sprintf('Could not reserve a port: %s (%d)', $error, $errno));
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false || !str_contains($name, ':')) {
            throw new RuntimeException('Could not read the reserved port.');
        }

        return (int)substr($name, strrpos($name, ':') + 1);
    }

    /**
     * Block until the server answers, or fail after roughly five seconds.
     *
     * @return void
     * @throws RuntimeException When the server does not become reachable.
     */
    private function waitForServer(): void
    {
        $deadline = microtime(true) + 5.0;
        $errno = 0;
        $error = '';

        while (microtime(true) < $deadline) {
            $probe = @stream_socket_client(
                sprintf('tcp://127.0.0.1:%d', $this->port),
                $errno,
                $error,
                0.2
            );

            if (is_resource($probe)) {
                fclose($probe);
                return;
            }

            usleep(20_000);
        }

        // The last connection error is reported: a startup failure is otherwise
        // indistinguishable from a port that was taken between reserving it and
        // binding it, and the two are fixed differently.
        throw new RuntimeException(sprintf(
            'The built-in server did not start on port %d within five seconds: %s (%d)',
            $this->port,
            $error,
            $errno
        ));
    }
}

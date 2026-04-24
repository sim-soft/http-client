<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Response;


/**
 * Class PeppolIdParser.
 *
 * The PeppolIdParser class is a class that handles the peppol parser.
 */
class HttpClientTest extends TestCase
{
    protected static string $endpoint = 'http://local.code-lab.com/data/user.json';

    protected static Response $response;

    /**
     * @throws Exception
     * @throws Throwable
     */
    public static function dataProvider(): array
    {
        static::$response = HttpClient::make()->withoutVerifying()->get(static::$endpoint);
        //var_dump($response->ok(), $response->getStatusCode(), $response->getReasonPhrase()); exit;

        return [
            'User ID' => ['data.*.id', [1, 2, 3, 4,5,6,7,8,9, 10]],
            'User age' => ['data.*.profile.age', [28, 32, 24, 35, 30, 27, 41, 29, 26, 33]],
            'User city' => ['data.*.profile.address.*.city', [
                'Kuala Lumpur', 'Petaling Jaya', 'Shah Alam', 'Subang Jaya',
                'Seremban', 'Kuantan', 'Ipoh', 'Johor Bahru', 'Alor Setar', 'Melaka'
            ]],
        ];
    }

    /**
     * Perform test.
     *
     * @param string $key
     * @param mixed $expected
     * @return void
     * @throws Exception
     */
    #[DataProvider('dataProvider')]
    public function testClientRequest(string $key, mixed $expected): void
    {
        if (static::$response->ok()) {
            $this->assertEquals($expected, static::$response->data($key));
        }

        /*echo static::$response->failed() ? static::$response->getReasonPhrase() : 'Success';
        echo PHP_EOL;*/
    }

}

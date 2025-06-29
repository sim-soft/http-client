<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Simsoft\HttpClient\HttpClient;


/**
 * Class PeppolIdParser.
 *
 * The PeppolIdParser class is a class that handles the peppol parser.
 */
class HttpClientTest extends TestCase
{
    protected string $endpoint = 'http://local.code-lab.com/data/user.json';

    public static function dataProvider(): array
    {
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
     */
    #[DataProvider('dataProvider')]
    public function testClientRequest(string $key, mixed $expected): void
    {
        $client = new HttpClient();
        $response = $client
            ->withBaseUri($this->endpoint)
            //->retry(3)
            ->get();

        if($response->ok()) {
            $this->assertEquals($expected, $response->getAttribute($key));
        }
    }

}

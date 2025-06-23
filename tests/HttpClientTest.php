<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TickStar\Peppol;

/**
 * Class PeppolIdParser.
 *
 * The PeppolIdParser class is a class that handles the peppol parser.
 */
class PeppolIdParserTest extends TestCase
{
    public static function dataProvider(): array
    {
        return [
            'SSM: Valid identifier' => ['0230:01199401030027', '0230', true, '01', null, '199401030027', '199401030027'],
            'SSM: Old BRN' => ['0230:01315708X', '0230', false, '01', null, null, null],
            'SSM: Invalid BRN length' => ['0230:0120050107047', '0230', false, '01', null, null, null],
            'Sabah: Valid ROC BRN' => ['0230:0214TMPR201921', '0230', true, '02', '14', '14TMPR201921', 'TMPR201921'],
            'Sabah: Invalid ROC BRN' => ['0230:0204DBKK51902', '0230', false, '02', null, null, null],
            'Sabah: Valid enterprise BRN' => ['0230:020512345', '0230', true, '02', '05', '0512345', '12345'],
            'Sabah: Invalid enterprise BRN' => ['0230:02051234', '0230', false, '02', null, null, null],
            'Sabah: Invalid district code' => ['0230:02981234', '0230', false, '02', null, null, null],
            'Sarawak: Valid ROC BRN' => ['0230:03111238711111', '0230', true, '03', '11', '111238711111', '1238711111'],
            'Sarawak: Invalid ROC BRN length' => ['0230:03111238711', '0230', false, '03', null, null, null],
            'Sarawak: Valid enterprise BRN' => ['0230:038876566', '0230', true, '03', '88', '8876566', '76566'],
            'Sarawak: Invalid enterprise BRN length' => ['0230:03887656', '0230', false, '03', null, null, null],
            'Sarawak: Invalid district code.' => ['0230:0398765', '0230', false, '03', null, null, null],
            'Foreign Business: Valid BRN' => ['0230:04SG3131344', '0230', true, '04', null, 'SG3131344', 'SG3131344'],
            'Foreign Business: Valid BRN 2' => ['0230:04DE11133', '0230', true, '04', null, 'DE11133', 'DE11133'],
            'Foreign Business: Invalid country code' => ['0230:04SP01344', '0230', false, '04', null, null, null],
            'Foreign Business: Invalid length' => ['0230:04SGGAB013441231231', '0230', false, '04', null, null, null],
            'Others: Valid BRN' => ['0230:06PPM001101504', '0230', true, '06', null, 'PPM001101504', 'PPM001101504'],
            'Others: Invalid length' => ['0230:06PPM0011015041965', '0230', false, '06', null, null, null],
            'Statutory Body: Valid BRN' => ['0230:07KWSP01', '0230', true, '07', null, 'KWSP01', 'KWSP01'],
            'Statutory Body: Invalid BRN' => ['0230:07JMB9722019', '0230', false, '07', null, null, null],
            'Local Authority: Valid BRN' => ['0230:08MBSJ01', '0230', true, '08', null, 'MBSJ01', 'MBSJ01'],
            'Local Authority: Invalid BRN' => ['0230:08MBSJ012', '0230', false, '08', null, null, null],
            'Test: Valid BRN' => ['0230:05ABCD1234', '0230', true, '05', null, 'ABCD1234', 'ABCD1234'],
            'Test: Invalid BRN' => ['0230:05ABCD123456789', '0230', false, '05', null, null, null],
        ];
    }

    /**
     *
     * @param string $peppolId
     * @param string|null $code
     * @param bool $valid
     * @param string|null $specialIdentifier
     * @param string|null $district
     * @param string|null $businessIdentifier
     * @return void
     */
    #[DataProvider('dataProvider')]
    public function testParser(
        string $peppolId,
        ?string $code,
        bool $valid,
        ?string $specialIdentifier,
        ?string $district,
        ?string $businessIdentifier,
        ?string $brn
    ): void
    {
            $peppol = Peppol::parse($peppolId);

            $this->assertEquals($code, $peppol->code, 'Check code.');
            $this->assertEquals($valid, $peppol->isValid(), 'Determine valid.');
            $this->assertEquals($specialIdentifier, $peppol->specialIdentifier?->value,'Check special identifier');
            $this->assertEquals($district, $peppol->district?->value, 'Check district code');
            $this->assertEquals($businessIdentifier, $peppol->businessIdentifier, 'Check business identifier');
            $this->assertEquals($brn, $peppol->brn, 'Check BRN');

    }

}

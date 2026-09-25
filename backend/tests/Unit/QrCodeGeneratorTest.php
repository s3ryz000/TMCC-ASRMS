<?php

namespace Tests\Unit;

use App\Services\QrCodeGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the in-project QR encoder that replaced the quickchart.io dependency.
 *
 * The encoder exists because the system is deployed to an isolated campus LAN
 * (§1.4, §3.7.1), where a remotely hosted QR image cannot load. Correctness is
 * checked structurally here; the payload round-trip is what proves a scanner
 * will actually read the result.
 */
class QrCodeGeneratorTest extends TestCase
{
    public function test_produces_a_png_data_uri(): void
    {
        $uri = QrCodeGenerator::pngDataUri('http://192.168.1.10/verify/1');

        $this->assertStringStartsWith('data:image/png;base64,', $uri);

        $binary = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);

        $this->assertNotFalse($binary, 'Data URI payload must be valid base64.');
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $binary, 'Must carry the PNG signature.');
        $this->assertStringContainsString('IHDR', $binary);
        $this->assertStringContainsString('IDAT', $binary);
        $this->assertStringContainsString('IEND', $binary);
    }

    public function test_never_reaches_the_network(): void
    {
        // The whole point of the class: output is self-contained, so nothing in
        // it may reference an external host.
        $uri = QrCodeGenerator::pngDataUri('http://192.168.1.10/verify/1');

        $this->assertStringNotContainsString('http://', substr($uri, 22));
        $this->assertStringNotContainsString('quickchart', $uri);
    }

    /**
     * @dataProvider payloadSizes
     */
    public function test_selects_a_version_large_enough_for_the_payload(int $bytes, int $expectedVersion): void
    {
        $matrix = QrCodeGenerator::matrix(str_repeat('x', $bytes));
        $version = (count($matrix) - 17) / 4;

        $this->assertSame($expectedVersion, $version);
        $this->assertCount($version * 4 + 17, $matrix, 'Matrix must be square and sized to its version.');
    }

    public static function payloadSizes(): array
    {
        return [
            '14 bytes fits version 1'    => [14, 1],
            '15 bytes needs version 2'   => [15, 2],
            '26 bytes fits version 2'    => [26, 2],
            '62 bytes fits version 4'    => [62, 4],
            '63 bytes needs version 5'   => [63, 5],
            '213 bytes fits version 10'  => [213, 10],
            '214 bytes needs version 11' => [214, 11],
        ];
    }

    public function test_finder_patterns_are_well_formed(): void
    {
        $m = QrCodeGenerator::matrix('TMCC ASRMS');
        $size = count($m);

        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$originX, $originY]) {
            for ($y = 0; $y < 7; $y++) {
                for ($x = 0; $x < 7; $x++) {
                    $ring = max(abs($x - 3), abs($y - 3));

                    $this->assertSame(
                        $ring !== 2,
                        $m[$originY + $y][$originX + $x],
                        "Finder module ($x,$y) is wrong."
                    );
                }
            }
        }
    }

    public function test_timing_patterns_alternate(): void
    {
        $m = QrCodeGenerator::matrix('TMCC ASRMS');
        $size = count($m);

        for ($i = 8; $i < $size - 8; $i++) {
            $this->assertSame($i % 2 === 0, $m[6][$i], "Horizontal timing module $i is wrong.");
            $this->assertSame($i % 2 === 0, $m[$i][6], "Vertical timing module $i is wrong.");
        }
    }

    public function test_output_is_deterministic(): void
    {
        $text = 'http://192.168.1.10/api/requests/99/approval-slip';

        $this->assertSame(
            QrCodeGenerator::pngDataUri($text),
            QrCodeGenerator::pngDataUri($text)
        );
    }

    public function test_scale_and_border_change_the_image_dimensions(): void
    {
        $small = QrCodeGenerator::pngDataUri('TMCC', 4, 4);
        $large = QrCodeGenerator::pngDataUri('TMCC', 8, 4);

        $this->assertNotSame($small, $large);
        $this->assertGreaterThan(strlen($small), strlen($large));
    }

    public function test_rejects_an_empty_payload(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QrCodeGenerator::matrix('');
    }

    public function test_rejects_a_payload_beyond_capacity(): void
    {
        $this->expectException(RuntimeException::class);

        QrCodeGenerator::matrix(str_repeat('x', 5000));
    }
}

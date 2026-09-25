<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Self-contained QR Code encoder (ISO/IEC 18004), byte mode, EC level M.
 *
 * Written in-project on purpose. The approval slip and appointment pages used
 * to fetch their QR images from https://quickchart.io, but the capstone scope
 * (§1.4, §3.7.1) puts this system on an isolated campus LAN with no public
 * internet access — so every one of those images rendered as a broken icon on
 * the machines the system is actually deployed to. Composer is not available
 * on the deployment box either, which rules out pulling in a QR package, so
 * the encoder lives here and depends on nothing outside PHP core.
 *
 * Output is a PNG data URI, matching how the school logo is already embedded
 * (see RequestController::resolveSchoolLogoDataUri) so the <img> tags in the
 * existing HTML/PDF templates did not have to change shape.
 */
class QrCodeGenerator
{
    /** Error-correction codewords per block, EC level M, versions 1-20. */
    private const ECC_PER_BLOCK = [
        1 => 10, 16, 26, 18, 24, 16, 18, 22, 22, 26,
        30, 22, 22, 24, 24, 28, 28, 26, 26, 26,
    ];

    /** Number of error-correction blocks, EC level M, versions 1-20. */
    private const NUM_BLOCKS = [
        1 => 1, 1, 1, 2, 2, 4, 4, 4, 5, 5,
        5, 8, 9, 9, 10, 10, 11, 13, 14, 16,
    ];

    /** Format-info value for EC level M. */
    private const ECC_FORMAT_BITS = 0;

    /** Version 20 at level M holds 666 bytes — far more than any signed URL. */
    private const MAX_VERSION = 20;

    /** @var int[] */
    private static array $expTable = [];

    /** @var int[] */
    private static array $logTable = [];

    /**
     * Encode $text as a PNG data URI suitable for a src attribute.
     *
     * @param int $scale  Pixels per QR module.
     * @param int $border Quiet-zone width in modules (4 is the spec minimum).
     */
    public static function pngDataUri(string $text, int $scale = 6, int $border = 4): string
    {
        return 'data:image/png;base64,' . base64_encode(
            self::png(self::matrix($text), $scale, $border)
        );
    }

    /**
     * Build the QR module matrix.
     *
     * @return bool[][] Row-major grid; true is a dark module.
     */
    public static function matrix(string $text): array
    {
        if ($text === '') {
            throw new InvalidArgumentException('Cannot encode an empty string as a QR code.');
        }

        $version = self::chooseVersion($text);
        $codewords = self::addEccAndInterleave(self::encodeData($text, $version), $version);

        $qr = new self($version);
        $qr->drawFunctionPatterns();
        $qr->drawCodewords($codewords);
        $qr->applyBestMask();

        return $qr->modules;
    }

    // ---------------------------------------------------------------- state

    private int $size;

    /** @var bool[][] */
    private array $modules;

    /** @var bool[][] True where a module is a function pattern and must not be masked. */
    private array $isFunction;

    private function __construct(private int $version)
    {
        $this->size = $version * 4 + 17;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->isFunction = array_fill(0, $this->size, array_fill(0, $this->size, false));
    }

    // ------------------------------------------------------------- encoding

    /** Smallest version whose EC-level-M capacity fits the payload. */
    private static function chooseVersion(string $text): int
    {
        $length = strlen($text);

        for ($version = 1; $version <= self::MAX_VERSION; $version++) {
            $capacityBits = self::numDataCodewords($version) * 8;
            $headerBits = 4 + ($version < 10 ? 8 : 16);

            if ($length * 8 + $headerBits <= $capacityBits) {
                return $version;
            }
        }

        throw new RuntimeException(sprintf(
            'Payload of %d bytes exceeds the %d-byte capacity of a version-%d QR code.',
            $length,
            intdiv(self::numDataCodewords(self::MAX_VERSION) * 8 - 4 - 16, 8),
            self::MAX_VERSION
        ));
    }

    /**
     * Byte-mode segment with terminator and pad codewords.
     *
     * @return int[] Data codewords.
     */
    private static function encodeData(string $text, int $version): array
    {
        $bits = [];
        $append = static function (int $value, int $length) use (&$bits): void {
            for ($i = $length - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };

        $append(0b0100, 4);                              // byte mode
        $append(strlen($text), $version < 10 ? 8 : 16);  // character count

        foreach (str_split($text) as $char) {
            $append(ord($char), 8);
        }

        $capacityBits = self::numDataCodewords($version) * 8;

        // Terminator: up to four zero bits, then pad to a byte boundary.
        $append(0, min(4, $capacityBits - count($bits)));
        $append(0, (8 - count($bits) % 8) % 8);

        // Alternating pad codewords fill whatever capacity is left.
        for ($pad = 0xEC; count($bits) < $capacityBits; $pad ^= 0xEC ^ 0x11) {
            $append($pad, 8);
        }

        $codewords = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $codewords[] = bindec(implode('', $byte));
        }

        return $codewords;
    }

    /**
     * Split into blocks, append Reed-Solomon ECC, and interleave per spec.
     *
     * Block lengths are derived rather than tabulated: the spec distributes the
     * raw codewords so that the longer blocks all sit at the end.
     *
     * @param  int[] $data
     * @return int[]
     */
    private static function addEccAndInterleave(array $data, int $version): array
    {
        $numBlocks = self::NUM_BLOCKS[$version];
        $eccLen = self::ECC_PER_BLOCK[$version];
        $rawCodewords = intdiv(self::numRawDataModules($version), 8);

        $shortBlockLen = intdiv($rawCodewords, $numBlocks);
        $numShortBlocks = $numBlocks - $rawCodewords % $numBlocks;

        $blocks = [];
        $offset = 0;

        for ($i = 0; $i < $numBlocks; $i++) {
            $dataLen = $shortBlockLen - $eccLen + ($i < $numShortBlocks ? 0 : 1);
            $block = array_slice($data, $offset, $dataLen);
            $offset += $dataLen;

            $blocks[] = [
                'data' => $block,
                'ecc'  => self::reedSolomon($block, $eccLen),
            ];
        }

        // Interleave: one codeword from each block in turn. Short blocks are
        // one codeword shy of the long ones, so their slot is simply skipped.
        $result = [];

        for ($i = 0; $i < $shortBlockLen - $eccLen + 1; $i++) {
            foreach ($blocks as $index => $block) {
                if ($i < count($block['data'])) {
                    $result[] = $block['data'][$i];
                }
            }
        }

        for ($i = 0; $i < $eccLen; $i++) {
            foreach ($blocks as $block) {
                $result[] = $block['ecc'][$i];
            }
        }

        return $result;
    }

    // ------------------------------------------------- Reed-Solomon / GF(256)

    private static function initGaloisTables(): void
    {
        if (self::$expTable !== []) {
            return;
        }

        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$expTable[$i] = $x;
            self::$logTable[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D; // primitive polynomial x^8 + x^4 + x^3 + x^2 + 1
            }
        }

        for ($i = 255; $i < 512; $i++) {
            self::$expTable[$i] = self::$expTable[$i - 255];
        }
    }

    private static function gfMultiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$expTable[(self::$logTable[$a] + self::$logTable[$b]) % 255];
    }

    /**
     * Generator polynomial of the given degree, highest-order coefficient first.
     *
     * @return int[]
     */
    private static function generatorPolynomial(int $degree): array
    {
        self::initGaloisTables();

        $poly = [1];

        for ($i = 0; $i < $degree; $i++) {
            // Multiply by (x - alpha^i).
            $next = array_fill(0, count($poly) + 1, 0);

            foreach ($poly as $j => $coefficient) {
                $next[$j] ^= $coefficient;
                $next[$j + 1] ^= self::gfMultiply($coefficient, self::$expTable[$i]);
            }

            $poly = $next;
        }

        return $poly;
    }

    /**
     * @param  int[] $data
     * @return int[] $eccLen error-correction codewords.
     */
    private static function reedSolomon(array $data, int $eccLen): array
    {
        $generator = self::generatorPolynomial($eccLen);
        $remainder = array_merge($data, array_fill(0, $eccLen, 0));
        $dataLen = count($data);

        for ($i = 0; $i < $dataLen; $i++) {
            $factor = $remainder[$i];
            if ($factor === 0) {
                continue;
            }

            foreach ($generator as $j => $coefficient) {
                $remainder[$i + $j] ^= self::gfMultiply($coefficient, $factor);
            }
        }

        return array_slice($remainder, $dataLen, $eccLen);
    }

    // ---------------------------------------------------------- capacity maths

    /** Total data-module count for a version, including remainder bits. */
    private static function numRawDataModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;

        if ($version >= 2) {
            $numAlign = intdiv($version, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;

            if ($version >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    private static function numDataCodewords(int $version): int
    {
        return intdiv(self::numRawDataModules($version), 8)
            - self::ECC_PER_BLOCK[$version] * self::NUM_BLOCKS[$version];
    }

    /** @return int[] Alignment-pattern centre coordinates. */
    private static function alignmentPatternPositions(int $version): array
    {
        if ($version === 1) {
            return [];
        }

        $numAlign = intdiv($version, 7) + 2;
        $step = intdiv($version * 4 + $numAlign * 2 + 1, $numAlign * 2 - 2) * 2;

        $result = array_fill(0, $numAlign, 0);
        $result[0] = 6;

        for ($i = $numAlign - 1, $pos = $version * 4 + 10; $i >= 1; $i--, $pos -= $step) {
            $result[$i] = $pos;
        }

        return $result;
    }

    // ------------------------------------------------------- matrix drawing

    private function setFunctionModule(int $x, int $y, bool $isDark): void
    {
        $this->modules[$y][$x] = $isDark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        // Timing patterns.
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunctionModule(6, $i, $i % 2 === 0);
            $this->setFunctionModule($i, 6, $i % 2 === 0);
        }

        // Finder patterns, with their separators.
        $this->drawFinderPattern(3, 3);
        $this->drawFinderPattern($this->size - 4, 3);
        $this->drawFinderPattern(3, $this->size - 4);

        // Alignment patterns, skipping the three that collide with finders.
        $positions = self::alignmentPatternPositions($this->version);
        $last = count($positions) - 1;

        foreach ($positions as $i => $x) {
            foreach ($positions as $j => $y) {
                $isFinderCorner = ($i === 0 && $j === 0)
                    || ($i === 0 && $j === $last)
                    || ($i === $last && $j === 0);

                if (! $isFinderCorner) {
                    $this->drawAlignmentPattern($x, $y);
                }
            }
        }

        // Format and version info. Mask 0 is a placeholder; applyBestMask()
        // rewrites the format bits once the real mask has been chosen.
        $this->drawFormatBits(0);
        $this->drawVersionBits();
    }

    private function drawFinderPattern(int $centreX, int $centreY): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $centreX + $dx;
                $y = $centreY + $dy;

                if ($x < 0 || $x >= $this->size || $y < 0 || $y >= $this->size) {
                    continue;
                }

                $distance = max(abs($dx), abs($dy));
                $this->setFunctionModule($x, $y, $distance !== 2 && $distance !== 4);
            }
        }
    }

    private function drawAlignmentPattern(int $centreX, int $centreY): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunctionModule(
                    $centreX + $dx,
                    $centreY + $dy,
                    max(abs($dx), abs($dy)) !== 1
                );
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::ECC_FORMAT_BITS << 3) | $mask;

        // BCH(15,5) error correction.
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }

        $bits = (($data << 10) | $remainder) ^ 0x5412;

        $bitAt = static fn (int $i): bool => (($bits >> $i) & 1) !== 0;

        // First copy, around the top-left finder.
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunctionModule(8, $i, $bitAt($i));
        }
        $this->setFunctionModule(8, 7, $bitAt(6));
        $this->setFunctionModule(8, 8, $bitAt(7));
        $this->setFunctionModule(7, 8, $bitAt(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunctionModule(14 - $i, 8, $bitAt($i));
        }

        // Second copy, split between the other two finders.
        for ($i = 0; $i < 8; $i++) {
            $this->setFunctionModule($this->size - 1 - $i, 8, $bitAt($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunctionModule(8, $this->size - 15 + $i, $bitAt($i));
        }

        // The dark module is always set.
        $this->setFunctionModule(8, $this->size - 8, true);
    }

    private function drawVersionBits(): void
    {
        if ($this->version < 7) {
            return;
        }

        // BCH(18,6) error correction.
        $remainder = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }

        $bits = ($this->version << 12) | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $isDark = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);

            $this->setFunctionModule($a, $b, $isDark);
            $this->setFunctionModule($b, $a, $isDark);
        }
    }

    /**
     * Lay codewords into the matrix in the spec's two-column zigzag.
     *
     * @param int[] $codewords
     */
    private function drawCodewords(array $codewords): void
    {
        $bitIndex = 0;
        $totalBits = count($codewords) * 8;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5; // Skip the vertical timing column.
            }

            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;

                    if ($this->isFunction[$y][$x] || $bitIndex >= $totalBits) {
                        continue;
                    }

                    $byte = $codewords[$bitIndex >> 3];
                    $this->modules[$y][$x] = (($byte >> (7 - ($bitIndex & 7))) & 1) !== 0;
                    $bitIndex++;
                }
            }
        }
    }

    // ------------------------------------------------------------- masking

    private function applyBestMask(): void
    {
        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);

            $penalty = $this->penaltyScore();
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
            }

            $this->applyMask($mask); // XOR is its own inverse; undo.
        }

        $this->applyMask($bestMask);
        $this->drawFormatBits($bestMask);
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->isFunction[$y][$x]) {
                    continue;
                }

                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };

                if ($invert) {
                    $this->modules[$y][$x] = ! $this->modules[$y][$x];
                }
            }
        }
    }

    /**
     * The spec's four penalty rules. Only used to pick between masks, so a
     * near-miss here costs legibility rather than correctness.
     */
    private function penaltyScore(): int
    {
        $score = 0;
        $size = $this->size;

        // Rule 1: runs of five or more same-coloured modules.
        for ($i = 0; $i < $size; $i++) {
            $score += $this->runPenalty(array_map(fn ($j) => $this->modules[$i][$j], range(0, $size - 1)));
            $score += $this->runPenalty(array_map(fn ($j) => $this->modules[$j][$i], range(0, $size - 1)));
        }

        // Rule 2: 2x2 blocks of one colour.
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $colour = $this->modules[$y][$x];

                if ($colour === $this->modules[$y][$x + 1]
                    && $colour === $this->modules[$y + 1][$x]
                    && $colour === $this->modules[$y + 1][$x + 1]
                ) {
                    $score += 3;
                }
            }
        }

        // Rule 3: finder-like 1:1:3:1:1 patterns.
        $patterns = [
            [true, false, true, true, true, false, true, false, false, false, false],
            [false, false, false, false, true, false, true, true, true, false, true],
        ];

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x <= $size - 11; $x++) {
                foreach ($patterns as $pattern) {
                    $matchesRow = true;
                    $matchesCol = true;

                    for ($k = 0; $k < 11; $k++) {
                        $matchesRow = $matchesRow && $this->modules[$y][$x + $k] === $pattern[$k];
                        $matchesCol = $matchesCol && $this->modules[$x + $k][$y] === $pattern[$k];
                    }

                    $score += ($matchesRow ? 40 : 0) + ($matchesCol ? 40 : 0);
                }
            }
        }

        // Rule 4: deviation from an even balance of dark and light.
        $dark = 0;
        foreach ($this->modules as $row) {
            foreach ($row as $module) {
                $dark += $module ? 1 : 0;
            }
        }

        $total = $size * $size;
        $score += intdiv((int) (abs($dark * 20 - $total * 10) + $total - 1), $total) * 10;

        return $score;
    }

    /** @param bool[] $line */
    private function runPenalty(array $line): int
    {
        $score = 0;
        $runLength = 1;

        for ($i = 1, $count = count($line); $i < $count; $i++) {
            if ($line[$i] === $line[$i - 1]) {
                $runLength++;
                continue;
            }

            if ($runLength >= 5) {
                $score += $runLength - 2;
            }

            $runLength = 1;
        }

        return $score + ($runLength >= 5 ? $runLength - 2 : 0);
    }

    // ------------------------------------------------------------ PNG output

    /**
     * Minimal 8-bit greyscale PNG writer.
     *
     * Hand-rolled because GD is not guaranteed on the deployment box; this
     * needs only zlib and crc32, both of which are PHP core.
     *
     * @param bool[][] $matrix
     */
    private static function png(array $matrix, int $scale, int $border): string
    {
        $modules = count($matrix);
        $dimension = ($modules + $border * 2) * $scale;

        $white = str_repeat("\xFF", $dimension);
        $raw = '';

        for ($y = 0; $y < $dimension; $y++) {
            $moduleY = intdiv($y, $scale) - $border;

            if ($moduleY < 0 || $moduleY >= $modules) {
                $raw .= "\x00" . $white;
                continue;
            }

            $line = '';
            foreach ($matrix[$moduleY] as $module) {
                $line .= str_repeat($module ? "\x00" : "\xFF", $scale);
            }

            $raw .= "\x00" . str_repeat("\xFF", $border * $scale)
                 . $line
                 . str_repeat("\xFF", $border * $scale);
        }

        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $dimension, $dimension, 8, 0, 0, 0, 0))
            . $chunk('IDAT', gzcompress($raw, 9))
            . $chunk('IEND', '');
    }
}

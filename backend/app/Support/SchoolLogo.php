<?php

namespace App\Support;

/**
 * The TMCC logo as a data URI for PDFs (#98). Dompdf runs with remote access
 * off on the LAN, so the image is embedded rather than linked. The copy in
 * backend/resources/images ships with the backend; the other places are the
 * ones the approval slip has always looked in.
 */
final class SchoolLogo
{
    public static function candidates(): array
    {
        return [
            resource_path('images/tmcc-logo.png'),
            public_path('logo.png'),
            public_path('logo.jpg'),
            base_path('../frontend/public/logo.png'),
            base_path('../frontend/public/logo.jpg'),
        ];
    }

    /** "data:image/png;base64,…", or '' when no logo file is found. */
    public static function dataUri(?array $candidates = null): string
    {
        foreach ($candidates ?? self::candidates() as $path) {
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                continue;
            }
            $binary = @file_get_contents($path);
            if ($binary === false || $binary === '') {
                continue;
            }

            $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                default => 'application/octet-stream',
            };

            return 'data:' . $mime . ';base64,' . base64_encode($binary);
        }

        return '';
    }
}

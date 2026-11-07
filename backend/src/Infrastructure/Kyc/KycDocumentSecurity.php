<?php

declare(strict_types=1);

namespace app\Infrastructure\Kyc;

use Yii;
use yii\web\ServerErrorHttpException;
use yii\web\UnprocessableEntityHttpException;

/**
 * Validates KYC upload signatures, sanitizes dangerous content, and scans the
 * temporary file with ClamAV.
 *
 * Sanitization (M-05):
 *   - PDF: removes JavaScript actions (/JavaScript, /JS, /AA, /OpenAction),
 *          embedded file attachments (/EmbeddedFiles) and launch actions (/Launch)
 *          to neutralize PDF-based XSS / exploit payloads.
 *   - Images: re-encodes via GD to strip EXIF metadata, comments and any
 *             polyglot payloads embedded in image markers.
 *
 * Production deployments must configure SEDERG_CLAMD_HOST.
 */
final class KycDocumentSecurity
{
    /** PDF dangerous object name patterns to strip. */
    private const PDF_DANGEROUS = [
        '/\/JavaScript\s*[0-9]+\s+[0-9]+\s+R/i',
        '/\/JS\s*[0-9]+\s+[0-9]+\s+R/i',
        '/\/AA\s*[0-9]+\s+[0-9]+\s+R/i',
        '/\/OpenAction\s*[0-9]+\s+[0-9]+\s+R/i',
        '/\/EmbeddedFiles\s*[0-9]+\s+[0-9]+\s+R/i',
        '/\/Launch\s*[0-9]+\s+[0-9]+\s+R/i',
        '/\/S\s*\/JavaScript/i',
        '/\/S\s*\/Launch/i',
    ];

    public static function validateAndScan(string $path, string $expectedMime): void
    {
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > 5 * 1024 * 1024) {
            throw new UnprocessableEntityHttpException('The document is empty or exceeds 5 MB');
        }

        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($detectedMime !== $expectedMime) {
            throw new UnprocessableEntityHttpException('The document content does not match its declared file type');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ServerErrorHttpException('Cannot inspect uploaded document');
        }
        $signature = (string) fread($handle, 16);
        fclose($handle);

        $valid = match ($expectedMime) {
            'application/pdf' => str_starts_with($signature, '%PDF-'),
            'image/jpeg' => str_starts_with($signature, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($signature, "\x89PNG\r\n\x1A\n"),
            default => false,
        };
        if (!$valid) {
            throw new UnprocessableEntityHttpException('Invalid document signature');
        }

        // Sanitize dangerous content before malware scanning.
        self::sanitizeFile($path, $expectedMime);

        $host = trim((string) getenv('SEDERG_CLAMD_HOST'));
        $required = getenv('SEDERG_KYC_REQUIRED') === '1' || (getenv('SEDERG_AUTH_MODE') ?: 'development') !== 'development';
        if ($host === '') {
            if ($required) {
                throw new ServerErrorHttpException('Malware scanning is required but ClamAV is not configured');
            }
            Yii::warning('ClamAV is not configured; KYC upload is allowed only in development mode', __METHOD__);
            return;
        }

        $port = (int) (getenv('SEDERG_CLAMD_PORT') ?: 3310);
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 5, STREAM_CLIENT_CONNECT);
        if ($socket === false) {
            throw new ServerErrorHttpException('Malware scanning service is unavailable');
        }
        stream_set_timeout($socket, 20);
        try {
            if (fwrite($socket, "nINSTREAM\n") === false) {
                throw new ServerErrorHttpException('Cannot communicate with malware scanner');
            }
            $file = fopen($path, 'rb');
            if ($file === false) {
                throw new ServerErrorHttpException('Cannot read uploaded document for malware scan');
            }
            try {
                while (!feof($file)) {
                    $chunk = fread($file, 8192);
                    if ($chunk === false) {
                        throw new ServerErrorHttpException('Cannot read uploaded document for malware scan');
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    $frame = pack('N', strlen($chunk)) . $chunk;
                    $written = 0;
                    while ($written < strlen($frame)) {
                        $count = fwrite($socket, substr($frame, $written));
                        if ($count === false || $count === 0) {
                            throw new ServerErrorHttpException('Malware scanner connection failed');
                        }
                        $written += $count;
                    }
                }
            } finally {
                fclose($file);
            }
            fwrite($socket, pack('N', 0));
            $response = fgets($socket, 4096);
            if ($response === false) {
                throw new ServerErrorHttpException('Malware scanner returned no result');
            }
            if (str_contains($response, 'FOUND')) {
                throw new UnprocessableEntityHttpException('The uploaded document was flagged as malware');
            }
            if (!str_contains($response, ' OK')) {
                throw new ServerErrorHttpException('Malware scan could not be completed safely');
            }
        } finally {
            fclose($socket);
        }
    }

    /**
     * Removes dangerous PDF/JavaScript and re-encodes images to strip metadata.
     */
    private static function sanitizeFile(string $path, string $expectedMime): void
    {
        if ($expectedMime === 'application/pdf') {
            self::sanitizePdf($path);
        } elseif (in_array($expectedMime, ['image/jpeg', 'image/png'], true)) {
            self::sanitizeImage($path, $expectedMime);
        }
    }

    private static function sanitizePdf(string $path): void
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new ServerErrorHttpException('Cannot read PDF document for sanitization');
        }

        $sanitized = preg_replace(self::PDF_DANGEROUS, '', $content);
        if ($sanitized === null) {
            throw new ServerErrorHttpException('PDF sanitization failed');
        }

        // Strip object references, leaving unreferenced objects (harmless dead objects).
        if ($sanitized !== $content) {
            $written = file_put_contents($path, $sanitized, LOCK_EX);
            if ($written === false || $written !== strlen($sanitized)) {
                throw new ServerErrorHttpException('Cannot write sanitized PDF document');
            }
        }
    }

    private static function sanitizeImage(string $path, string $expectedMime): void
    {
        $src = match ($expectedMime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            default => null,
        };
        if ($src === false || $src === null) {
            throw new UnprocessableEntityHttpException('Cannot decode image for sanitization');
        }

        try {
            $width = imagesx($src);
            $height = imagesy($src);
            if ($width === false || $height === false || $width < 1 || $height < 1) {
                throw new UnprocessableEntityHttpException('Invalid image dimensions');
            }

            // Re-encode to a new image resource to strip EXIF, comments and polyglot payloads.
            $dest = imagecreatetruecolor($width, $height);
            if ($dest === false) {
                throw new ServerErrorHttpException('Cannot create sanitized image');
            }
            imagecopyresampled($dest, $src, 0, 0, 0, 0, $width, $height, $width, $height);

            $tmpPath = $path . '.sanitized';
            $success = match ($expectedMime) {
                'image/jpeg' => imagejpeg($dest, $tmpPath, 92),
                'image/png' => imagepng($dest, $tmpPath, 9),
                default => false,
            };
            imagedestroy($dest);
            imagedestroy($src);

            if ($success === false) {
                @unlink($tmpPath);
                throw new ServerErrorHttpException('Cannot write sanitized image');
            }

            if (!rename($tmpPath, $path)) {
                @unlink($tmpPath);
                throw new ServerErrorHttpException('Cannot replace image with sanitized version');
            }
        } catch (\Throwable $e) {
            imagedestroy($src ?? null);
            throw $e;
        }
    }
}

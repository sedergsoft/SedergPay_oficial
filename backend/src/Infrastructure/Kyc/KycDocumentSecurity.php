<?php

declare(strict_types=1);

namespace app\\Infrastructure\\Kyc;

use Yii;
use yii\\web\\ServerErrorHttpException;
use yii\\web\\UnprocessableEntityHttpException;

/**
 * Validates KYC upload signatures and scans the temporary file with ClamAV.
 * Production deployments must configure SEDERG_CLAMD_HOST.
 */
final class KycDocumentSecurity
{
    public static function validateAndScan(string $path, string $expectedMime): void
    {
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > 5 * 1024 * 1024) {
            throw new UnprocessableEntityHttpException('The document is empty or exceeds 5 MB');
        }

        $detectedMime = (new \\finfo(FILEINFO_MIME_TYPE))->file($path);
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
            'image/jpeg' => str_starts_with($signature, "\\xFF\\xD8\\xFF"),
            'image/png' => str_starts_with($signature, "\\x89PNG\\r\\n\\x1A\\n"),
            default => false,
        };
        if (!$valid) {
            throw new UnprocessableEntityHttpException('Invalid document signature');
        }

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
            if (fwrite($socket, "nINSTREAM\\n") === false) {
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
}

<?php

declare(strict_types=1);

namespace tests\Unit;

use app\Infrastructure\Kyc\KycDocumentSecurity;
use PHPUnit\Framework\TestCase;
use yii\web\ServerErrorHttpException;
use yii\web\UnprocessableEntityHttpException;

final class KycDocumentSecurityTest extends TestCase
{
    private array $originalEnvironment = [];
    private string $temporaryFile;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['SEDERG_CLAMD_HOST', 'SEDERG_CLAMD_PORT', 'SEDERG_KYC_REQUIRED', 'SEDERG_AUTH_MODE'] as $key) {
            $this->originalEnvironment[$key] = getenv($key);
        }
        putenv('SEDERG_CLAMD_HOST');
        putenv('SEDERG_CLAMD_PORT');
        putenv('SEDERG_KYC_REQUIRED=1');
        putenv('SEDERG_AUTH_MODE=production');
        $this->temporaryFile = tempnam(sys_get_temp_dir(), 'sederg-kyc-');
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryFile) && is_file($this->temporaryFile)) {
            unlink($this->temporaryFile);
        }
        foreach ($this->originalEnvironment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        parent::tearDown();
    }

    public function testRejectsEmptyDocument(): void
    {
        file_put_contents($this->temporaryFile, '');
        $this->expectException(UnprocessableEntityHttpException::class);
        KycDocumentSecurity::validateAndScan($this->temporaryFile, 'application/pdf');
    }

    public function testRejectsOversizedDocument(): void
    {
        $handle = fopen($this->temporaryFile, 'wb');
        fwrite($handle, str_repeat('A', 5 * 1024 * 1024 + 1));
        fclose($handle);

        $this->expectException(UnprocessableEntityHttpException::class);
        KycDocumentSecurity::validateAndScan($this->temporaryFile, 'application/pdf');
    }

    public function testRejectsMimeMismatch(): void
    {
        file_put_contents($this->temporaryFile, 'This is not a PDF document.');
        $this->expectException(UnprocessableEntityHttpException::class);
        KycDocumentSecurity::validateAndScan($this->temporaryFile, 'application/pdf');
    }

    public function testFailsClosedWhenProductionScannerIsUnavailable(): void
    {
        file_put_contents(
            $this->temporaryFile,
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"
        );

        $this->expectException(ServerErrorHttpException::class);
        KycDocumentSecurity::validateAndScan($this->temporaryFile, 'application/pdf');
    }
}

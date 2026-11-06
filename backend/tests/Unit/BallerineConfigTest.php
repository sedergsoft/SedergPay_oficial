<?php

declare(strict_types=1);

namespace tests\Unit;

use app\Infrastructure\Kyc\Provider\BallerineConfig;
use PHPUnit\Framework\TestCase;
use yii\base\InvalidConfigException;

final class BallerineConfigTest extends TestCase
{
    public function testBuildsTrustedRunAndCollectionUrls(): void
    {
        $config = new BallerineConfig(
            baseUrl: 'https://ballerine.example/',
            apiKey: 'secret-api-key',
            workflowId: 'kyc-manual-review',
            collectionBaseUrl: 'https://collect.example/flow',
            appReturnUrl: 'https://app.example/kyc'
        );

        self::assertSame('https://ballerine.example/api/v1/external/workflows/run', $config->runUrl());
        self::assertSame(
            ['Authorization' => 'Api-Key secret-api-key'],
            $config->authHeaders()
        );
        self::assertSame(
            'https://collect.example/flow?workflowRuntimeId=runtime-1',
            $config->buildCollectionUrl(
                'runtime-1',
                '11111111-1111-4111-8111-111111111111',
                '22222222-2222-4222-8222-222222222222',
                'es'
            )
        );
    }

    public function testRejectsCredentialedOrNonHttpUrls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BallerineConfig(baseUrl: 'https://user:pass@ballerine.example');
    }

    public function testSessionRequiresAnExplicitCollectionUrl(): void
    {
        $config = new BallerineConfig(
            baseUrl: 'https://ballerine.example',
            apiKey: 'secret-api-key',
            workflowId: 'kyc-manual-review'
        );

        self::assertFalse($config->isSessionConfigured());

        $this->expectException(InvalidConfigException::class);
        $config->assertSessionConfigured();
    }

    public function testTemplateMustIncludeRuntimeId(): void
    {
        $config = new BallerineConfig(
            baseUrl: 'https://ballerine.example',
            apiKey: 'secret-api-key',
            workflowId: 'kyc-manual-review',
            collectionBaseUrl: 'https://collect.example',
            collectionUrlTemplate: 'https://collect.example/start'
        );

        $this->expectException(InvalidConfigException::class);
        $config->assertSessionConfigured();
    }

    public function testRequiresEmptyConfigurationToBeExplicitlyDisabled(): void
    {
        $config = new BallerineConfig();

        self::assertFalse($config->isSessionConfigured());
        self::assertFalse($config->isWebhookConfigured());
    }

    public function testEnabledFlagGatesSessionAndWebhookActivation(): void
    {
        $config = new BallerineConfig(
            baseUrl: 'https://ballerine.example',
            apiKey: 'secret-api-key',
            workflowId: 'kyc-manual-review',
            collectionBaseUrl: 'https://collect.example',
            webhookSecret: 'test-webhook-secret-0123456789',
            enabled: false
        );

        self::assertFalse($config->isSessionConfigured());
        self::assertFalse($config->isWebhookConfigured());
    }

    public function testEnvironmentActivationRequiresEnabledFlag(): void
    {
        $values = [
            'SEDERG_BALLERINE_ENABLED' => '1',
            'SEDERG_BALLERINE_BASE_URL' => 'https://ballerine.example',
            'SEDERG_BALLERINE_API_KEY' => 'secret-api-key',
            'SEDERG_BALLERINE_WORKFLOW_ID' => 'kyc-manual-review',
            'SEDERG_BALLERINE_COLLECTION_URL' => 'https://collect.example',
            'SEDERG_BALLERINE_WEBHOOK_SECRET' => 'test-webhook-secret-0123456789',
            'SEDERG_BALLERINE_RETURN_URL' => 'https://app.example',
        ];
        $previous = [];
        foreach (array_keys($values) as $name) {
            $previous[$name] = getenv($name);
            putenv($name . '=' . $values[$name]);
        }

        try {
            $enabled = BallerineConfig::fromEnvironment();
            self::assertTrue($enabled->enabled);
            self::assertTrue($enabled->isSessionConfigured());
            self::assertTrue($enabled->isWebhookConfigured());

            putenv('SEDERG_BALLERINE_ENABLED=0');
            $disabled = BallerineConfig::fromEnvironment();
            self::assertFalse($disabled->enabled);
            self::assertFalse($disabled->isSessionConfigured());
            self::assertFalse($disabled->isWebhookConfigured());
        } finally {
            foreach ($previous as $name => $value) {
                if ($value === false) {
                    putenv($name);
                } else {
                    putenv($name . '=' . $value);
                }
            }
        }
    }

    public function testProductionRejectsPlainHttp(): void
    {
        $previous = getenv('SEDERG_AUTH_MODE');
        putenv('SEDERG_AUTH_MODE=production');
        try {
            $this->expectException(\InvalidArgumentException::class);
            new BallerineConfig(baseUrl: 'http://ballerine.internal');
        } finally {
            if ($previous === false) {
                putenv('SEDERG_AUTH_MODE');
            } else {
                putenv('SEDERG_AUTH_MODE=' . $previous);
            }
        }
    }
}

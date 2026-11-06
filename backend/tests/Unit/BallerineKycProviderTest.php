<?php

declare(strict_types=1);

namespace tests\Unit;

use app\Infrastructure\Kyc\Provider\BallerineConfig;
use app\Infrastructure\Kyc\Provider\BallerineKycProvider;
use app\Infrastructure\Kyc\Provider\BallerineTransportInterface;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class BallerineKycProviderTest extends TestCase
{
    private const CASE_ID = '11111111-1111-4111-8111-111111111111';
    private const USER_ID = '22222222-2222-4222-8222-222222222222';

    public function testCreatesWorkflowSessionAndReturnsConfiguredCollectionUrl(): void
    {
        $transport = new class implements BallerineTransportInterface {
            /** @var array<string,mixed> */
            public array $lastRequest = [];

            public function postJson(string $url, array $payload, array $headers, int $timeoutSeconds): array
            {
                $this->lastRequest = compact('url', 'payload', 'headers', 'timeoutSeconds');

                return [
                    'workflowRuntimeId' => 'runtime-1',
                    'ballerineEntityId' => 'entity-1',
                    'workflowDefinitionId' => 'workflow-definition-1',
                ];
            }
        };
        $config = new BallerineConfig(
            baseUrl: 'https://ballerine.example',
            apiKey: 'api-key',
            workflowId: 'kyc-manual-review',
            collectionBaseUrl: 'https://collect.example',
            collectionUrlTemplate: 'https://collect.example/start?runtime={workflowRuntimeId}&case={casePublicId}&locale={locale}',
            appReturnUrl: 'https://app.example'
        );

        $result = (new BallerineKycProvider($config, $transport))->createSession([
            'case_public_id' => self::CASE_ID,
            'user_public_id' => self::USER_ID,
            'country_code' => 'cu',
            'locale' => 'ru',
            'return_url' => 'https://app.example/kyc',
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
        ]);

        self::assertSame('runtime-1', $result['provider_session_id']);
        self::assertSame('entity-1', $result['provider_entity_id']);
        self::assertSame(
            'https://collect.example/start?runtime=runtime-1&case=' . self::CASE_ID . '&locale=ru',
            $result['collection_url']
        );
        self::assertSame('https://ballerine.example/api/v1/external/workflows/run', $transport->lastRequest['url']);
        self::assertSame('Api-Key api-key', $transport->lastRequest['headers']['Authorization']);
        self::assertSame('kyc-manual-review', $transport->lastRequest['payload']['workflowId']);
        self::assertSame('CU', $transport->lastRequest['payload']['context']['metadata']['countryCode']);
        self::assertSame(self::USER_ID, $transport->lastRequest['payload']['context']['entity']['id']);
        self::assertSame('Ana', $transport->lastRequest['payload']['context']['entity']['data']['firstName']);
    }

    public function testRejectsInvalidInternalIdentifiersBeforeCallingProvider(): void
    {
        $transport = new class implements BallerineTransportInterface {
            public int $calls = 0;

            public function postJson(string $url, array $payload, array $headers, int $timeoutSeconds): array
            {
                $this->calls++;

                return [];
            }
        };
        $config = new BallerineConfig(
            baseUrl: 'https://ballerine.example',
            apiKey: 'api-key',
            workflowId: 'kyc-manual-review',
            collectionBaseUrl: 'https://collect.example'
        );

        try {
            (new BallerineKycProvider($config, $transport))->createSession([
                'case_public_id' => 'not-a-uuid',
                'user_public_id' => self::USER_ID,
                'country_code' => 'CU',
                'locale' => 'es',
                'return_url' => '',
            ]);
            self::fail('Expected invalid identifier rejection');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $transport->calls);
        }
    }

    public function testWebhookVerificationFailsClosedWhenSecretIsMissing(): void
    {
        $transport = new class implements BallerineTransportInterface {
            public function postJson(string $url, array $payload, array $headers, int $timeoutSeconds): array
            {
                return [];
            }
        };
        $config = new BallerineConfig(
            baseUrl: 'https://ballerine.example',
            apiKey: 'api-key',
            workflowId: 'kyc-manual-review',
            collectionBaseUrl: 'https://collect.example'
        );

        $this->expectException(UnexpectedValueException::class);
        (new BallerineKycProvider($config, $transport))->verifyAndNormalizeWebhook('{}', []);
    }
}

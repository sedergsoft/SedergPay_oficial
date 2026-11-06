# Ballerine KYC integration

This directory documents the Ballerine adapter that now exists behind the
provider-neutral `KycProviderInterface`.

## Current status

- The adapter, session endpoint, signed webhook receiver, status mapping and
  idempotent event persistence are implemented.
- The integration is **disabled by default** through
  `SEDERG_BALLERINE_ENABLED=0`: with the flag off, the existing Yii2
  document-upload and manual-review flow remains the only active path even if
  the remaining variables are present.
- Enabling the adapter requires a reviewed, pinned, self-hosted Ballerine
  deployment. No Ballerine package, image, SDK or floating CDN is added to this
  repository.
- For local end-to-end validation without Ballerine, the repository ships its own
  Docker simulator behind the `kyc-mock` Compose profile
  (`scripts/Enable-BallerineMock.ps1`). See
  [the mock guide](../../../docs/ballerine-kyc-mock.md).
- Ballerine remains an observation source. Yii2 keeps authentication,
  authorization, audit, final compliance review and the decision to enable
  remittances.

## Security boundaries

- Internal user, case and provider identifiers are resolved server-side.
- Base URLs come only from environment configuration, never from requests.
- Webhooks require a shared secret (`X-Authorization`, as emitted by upstream)
  or a valid `X-Ballerine-Signature` HMAC-SHA256 header, plus a timestamp inside
  the configured window.
- Every provider event ID is unique in `kyc_provider_events`; replays return
  success without repeating transitions or audits.
- Provider `approved`/`rejected` values map to `pending_verification`; they never
  approve a case by themselves.
- Unknown provider statuses are stored as quarantined observations.
- Webhook payload summaries deliberately exclude document contents and raw PII;
  only a SHA-256 hash and selected status fields are retained.

## Operational requirements

1. Pin the Ballerine commit/release and complete the licence, dependency and
   security review described in ADR-001.
2. Deploy the workflow service and collection UI with HTTPS outside development.
3. Set `SEDERG_BALLERINE_ENABLED=1` and provide every required variable.
4. Set `WEBHOOK_SECRET` in the Ballerine workflow service to the same value as
   `SEDERG_BALLERINE_WEBHOOK_SECRET`.
5. Configure the Ballerine workflow definition webhook subscription to call
   `POST /api/v1/kyc/webhooks/ballerine` on the SEDERG_PAY API.
6. Run `php yii kyc/provider-status` and verify
   `GET /api/v1/admin/kyc/provider` before enabling customer traffic.
7. Validate documents, data residency and manual-review coverage for Russia and
   Cuba before processing real identities.

See [the activation runbook](../../../docs/ballerine-kyc-activation.md),
[ADR-001](../../../docs/adr/001-ballerine-kyc-core.md) and
[INTEGRATION_CONTRACT.md](INTEGRATION_CONTRACT.md) for the state mapping and
acceptance criteria.

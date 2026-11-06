# Ballerine KYC integration contract

## Activation

The adapter is gated by `SEDERG_BALLERINE_ENABLED`. It stays off unless the flag
is `1` and the session and webhook settings are complete:

```dotenv
SEDERG_BALLERINE_ENABLED=1
SEDERG_BALLERINE_BASE_URL=https://ballerine.internal
SEDERG_BALLERINE_API_KEY=<server-side key>
SEDERG_BALLERINE_WORKFLOW_ID=<workflow id>
SEDERG_BALLERINE_COLLECTION_URL=https://collect.example
SEDERG_BALLERINE_WEBHOOK_SECRET=<shared secret, at least 16 characters>
```

`php yii kyc/provider-status` reports readiness without printing secrets, and
`GET /api/v1/admin/kyc/provider` exposes the same safe state to administrators.
See [the activation runbook](../../../docs/ballerine-kyc-activation.md).

## Provider boundary

`KycProviderInterface` stays provider-neutral:

- `createSession()` accepts only server-resolved identities and returns a
  short-lived collection URL.
- `verifyAndNormalizeWebhook()` authenticates the raw bytes before parsing.
- `BallerineStatusMapper` never maps provider `approved`/`rejected` directly to
  a final internal decision. Both enter `pending_verification`.
- Unknown provider statuses throw in the mapper; the webhook service catches that
  and persists a quarantined observation instead of applying a transition.

## Outbound session creation

`POST {SEDERG_BALLERINE_BASE_URL}/api/v1/external/workflows/run`

Headers:

```http
Authorization: Api-Key <SEDERG_BALLERINE_API_KEY>
Content-Type: application/json
```

Payload:

```json
{
  "workflowId": "<SEDERG_BALLERINE_WORKFLOW_ID>",
  "context": {
    "entity": {
      "id": "<SEDERG user public UUID>",
      "type": "individual",
      "data": { "firstName": "...", "lastName": "..." }
    },
    "documents": [],
    "metadata": {
      "sedergCasePublicId": "<case UUID>",
      "sedergUserPublicId": "<user UUID>",
      "countryCode": "RU",
      "locale": "ru",
      "returnUrl": "https://app.example/"
    }
  }
}
```

The response must include `workflowRuntimeId`. The collection URL is built from
`SEDERG_BALLERINE_COLLECTION_URL_TEMPLATE`; supported placeholders are
`{collectionBaseUrl}`, `{workflowRuntimeId}`, `{casePublicId}`, `{entityId}`,
`{returnUrl}` and `{locale}`.

## Inbound webhook

`POST /api/v1/kyc/webhooks/ballerine`

- Does not use the customer bearer token, `X-User-Public-Id` or CSRF.
- Requires `X-Authorization: <SEDERG_BALLERINE_WEBHOOK_SECRET>`, matching the
  upstream Ballerine `WEBHOOK_SECRET` (the `Authorization` header is also
  accepted as an alias), or a valid
  `X-Ballerine-Signature: sha256=<hmac>` header.
- Requires `id`, `eventName`, `workflowRuntimeId` and a `timestamp` inside
  `SEDERG_BALLERINE_WEBHOOK_TOLERANCE_SECONDS`.
- Persists `(provider_name, provider_event_id)` with a unique constraint before
  any transition. Duplicate events return HTTP 200 with `"duplicate": true`.
- Rejects signatures, timestamps and malformed payloads with HTTP 403 and does
  not echo provider details.

## Internal persistence

`kyc_cases` stores provider session/status/URL/expiry and the last event marker.
`kyc_provider_events` stores the idempotency key, sanitized summary, payload hash,
mapped internal status and processing outcome (`applied`, `observed`,
`quarantined`, `out_of_order`, `unmatched`, `duplicate`).

Final internal states `approved` and `rejected` are protected from provider
overwrites. An authorized administrator still makes the final decision through
`POST /api/v1/admin/kyc/{public_id}/review`.

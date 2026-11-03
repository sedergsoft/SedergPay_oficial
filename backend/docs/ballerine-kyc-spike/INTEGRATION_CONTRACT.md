# KYC provider integration contract

The first implementation boundary is deliberately provider-neutral:

- `KycProviderInterface::createSession()` accepts only server-resolved internal identities and returns a short-lived collection URL.
- `verifyAndNormalizeWebhook()` must authenticate the raw webhook bytes before parsing or returning a normalized event.
- The mapper does not map provider `approved` or `rejected` directly to final internal decisions. Both enter `pending_verification`; SEDERG_PAY policy and authorized review decide the final outcome.
- Unknown provider statuses throw and must never be treated as approval.
- Provider session IDs and event IDs must be persisted with unique constraints before the webhook path is enabled, to guarantee idempotency.

No concrete HTTP client or Ballerine package is included until upstream compatibility, version pinning, package licenses, and security review are complete. The current Yii2 document-upload and manual-review routes remain the operational path.

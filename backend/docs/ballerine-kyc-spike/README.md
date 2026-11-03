# Ballerine KYC spike (local-only)

This directory is reserved for an isolated proof of concept for Ballerine.

## Current status

No Ballerine runtime, SDK, npm package, container image, or remote service is enabled by this commit. The repository's existing Yii2 KYC workflow remains the active implementation.

Before adding a dependency:
1. Pin an immutable upstream commit or release.
2. Review the upstream license and licenses of transitive packages.
3. Review security posture, maintenance status, deployment topology, and data residency.
4. Test only with synthetic identity data.
5. Keep the integration behind a Yii2 provider adapter so the core application is not coupled to Ballerine-specific models.

## Local proof-of-concept gate

The spike may proceed only after the above review. Do not connect production document storage, email, banking, remittance, or real identity data to this environment.

See [ADR-001](../adr/001-ballerine-kyc-core.md) for the target architecture, state mapping, Russia-Cuba requirements, and acceptance criteria.

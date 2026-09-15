# Working on rate-limit

Follow ../AGENTS.md and ../docs/AGENT_WORKFLOW.md. This package is an unreleased local RC; the user explicitly deferred GitHub repositories. Keep reusable capability here and application policy in the host. Run composer test and composer validate --strict. Contract tests accept NAF_TEST_AUTOLOAD for a verified integration host. Do not contact a live directory or send external messages in tests.

The root bootstrap registers `rateLimit` in the existing `Naf\guard()` registry and lazily
binds `PdoLimiter::class` using the host's `PDO::class` service. Preserve host overrides and
the existing array result. Do not move the registry out of the framework, automatically
migrate tables or enforce a global request policy. Regression tests cover lazy boot,
existing guards/bindings, counter results, host overrides and transaction ownership.

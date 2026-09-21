# Working on rate-limit

NAF is a small PHP framework with optional Composer plugins. Its core owns boot,
configuration, the service container, routing, events and PSR-7 responses. Prefer existing
NAF helpers, services and extension interfaces; keep application business rules in the host.
This package declares `type: naf-plugin` and is discovered after installation in a NAF host.
The plugin repository itself is not the application's web root.

Before changing code, read the [shared contribution workflow](https://github.com/nafphp/docs/blob/main/AGENT_WORKFLOW.md)
and [release procedure](https://github.com/nafphp/docs/blob/main/RELEASING.md).
In the multi-repository workspace, the same documents are in the sibling `docs/` checkout;
use the linked copies when working from a standalone clone. Preserve other contributors' work.

Keep reusable capability here and application policy in the host. Run `composer test` and
`composer validate --strict`. Contract tests accept `NAF_TEST_AUTOLOAD` for a verified
integration host. Do not send external messages in tests.

The root bootstrap registers `rateLimit` in the existing `Naf\guard()` registry and lazily
binds `PdoLimiter::class` using the host's `PDO::class` service. Preserve host overrides and
the existing array result. Do not move the registry out of the framework, automatically
migrate tables or enforce a global request policy. Regression tests cover lazy boot,
existing guards/bindings, counter results, host overrides and transaction ownership.

Follow the shared [PHP code style](https://github.com/nafphp/docs/blob/main/CODE_STYLE.md)
and `.php-cs-fixer.dist.php`. Run `composer style:check`; `composer style:fix` applies the rules.
Keep logical steps and local names readable, preserving public signatures and template output.

User docs: [Rate limits](https://nafphp.github.io/docs/rate-limits/).

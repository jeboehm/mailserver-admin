# mailserver-admin - AI Agent Context

Web administration interface for [docker-mailserver](https://github.com/jeboehm/docker-mailserver): domains, users, aliases, DKIM, fetchmail accounts, API tokens and an MCP endpoint, with a dashboard for Dovecot and Rspamd. PHP >= 8.4, Symfony and EasyAdmin; every other version is pinned in `composer.json`.

## Development workflow

**Skills.** Install [mattpocock/skills](https://github.com/mattpocock/skills): `claude plugin install mattpocock-skills@claude-plugins-official` (Claude Code) or `npx skills@latest add mattpocock/skills` (other agents). The "Agent skills" section and `docs/agents/*` configure them for this repository (issue tracker, triage labels, domain docs).

**Full cycle: features and anything with a design decision.**

1. `/grill-me`: interview the requester until goal, scope and edge cases are clear.
2. `/to-spec`: write the spec as a GitHub issue in this repository.
3. `/to-tickets`: split it into tracer-bullet sub-issues with blocking links.
4. `/implement-spec`: implement on a branch in a dedicated Git worktree (never on `main`, never in a checkout another agent uses). Use conventional commits. `csfix`, PHPStan and PHPUnit must pass (commands under "Build and test").
5. `/code-review`: review the branch against `main` (standards and spec) and fix the findings.
6. `/pr`: write the body and open the PR against `main`.

**Shortcut: bug fixes, chores and minor improvements.** Skip steps 1-3 and start at `/implement-spec`; `/code-review` and `/pr` still apply. There is no spec issue, so the PR description carries the context: what was wrong or missing, why this change, how it was verified. If the shortcut uncovers a design question, stop and go back to step 1. A minor improvement changes no public interface, configuration or data format; anything that does takes the full cycle.

**Definition of done.** An open PR that implements the feature or fixes the bug, with CI green (or failures explained in the PR). Then shut down and clean up: `docker compose down -v` (with `-f compose.yaml -f compose.mysql.yaml` if used), `git worktree remove <path>` and delete the local branch (the remote branch stays for the PR).

**Documentation PRs.** The user documentation of all three projects lives in `jeboehm/docker-mailserver/docs` (GitHub Pages). When a change affects users in a real way, open a docs PR there, cross-linked with the code PR. It affects users when it adds, changes or removes an environment variable or default, adds a feature or UI page, changes behaviour, adds a console command or option, is a breaking change or needs an upgrade step, adds a port or service, or changes the DB/Redis contract operators see. It does not when it is refactoring, tests, CI, an internal dependency bump or code style.

## Ground rules

- **Run every PHP command in the `php` container**: `docker compose exec php <command>`. The host needs Docker only.
- `declare(strict_types=1);` and the package header comment in every PHP file; PSR-4 namespaces `App\` (`src/`) and `Tests\` (`tests/`).
- PHPStan at level max, set only in `phpstan.dist.neon`; no baseline, no `ignoreErrors`. Fix the cause.
- Run `composer run csfix` before committing. CI also runs Rector in dry-run mode on `src/`.
- Business logic belongs in services, not controllers. Services are `readonly` where possible. Use the Doctrine entity API, not direct queries. Exception: `MailboxCredentials` uses DBAL, because a credential's expiry has to come from the database clock that Dovecot compares it with.
- Schema changes need a migration; read "Database migrations" first. Existing migrations are never edited.
- Roles: `ROLE_ADMIN > ROLE_DOMAIN_ADMIN > ROLE_USER`. Object-level rules live in the policies in `src/Service/Security/Access/`, for the web interface and the MCP tools alike. A domain admin can read a user with the `admin` flag in their domain, but not change or delete them (`UserPolicy::canModify()`, `canDelete()`).
- Never log or expose DKIM private keys.
- OAuth is optional: check `OAUTH_ENABLED`. CSRF protection follows `CSRF_ENABLED`.
- British English in documentation and comments. Conventional commits (`fix:`, `feat:`, `docs:`, ...).

## Build and test

```bash
docker compose up -d
```

Starts `php` (FrankenPHP on http://localhost:8000, the only published port; built from `docker/frankenphp/`, bind-mounts the repository so `vendor/` and `var/` live on the host), `postgres` (default database), `redis`, `mda` (Dovecot), `mta` (Postfix), `filter` (Rspamd) and `tls` (writes `tls/` once with `composer run create-tls`). On every start the entrypoint (`docker/frankenphp/docker-entrypoint.sh`) installs missing dependencies, migrates the dev and the test database, seeds an empty one with `example.com`, `admin@example.com` / `changeme` and DKIM, and runs `redis:sync`.

MySQL in place of PostgreSQL, for the application and the mail services alike: `docker compose -f compose.yaml -f compose.mysql.yaml up -d`. `docker compose down -v` resets all data.

- **Code style**: `docker compose exec php composer run csfix`
- **Static analysis**: `docker compose exec php composer run phpstan`
- **Tests**: `docker compose exec php composer run test`
- **Rector**: `docker compose exec php composer run rector`
- **Coverage**: `docker compose exec -e XDEBUG_MODE=coverage php composer run coverage`
- **Debugging**: `docker compose exec -e XDEBUG_MODE=debug -e XDEBUG_SESSION=1 php <command>`. Xdebug connects to `host.docker.internal:9003`; it is off otherwise, and OPcache is disabled.

Configuration:

- `compose.yaml` holds the values of the containers. It never interpolates `${…}`, as Compose would read Symfony's `.env` for that.
- `.env.dev` holds the settings of the application in dev. Symfony loads it only with `APP_ENV=dev`, which `compose.yaml` sets, since `.env` ships with the releases and says `prod`.
- The test environment only receives `DATABASE_URL`, `REDIS_HOST` and `REDIS_PORT` from the container, as in CI. Doctrine appends `_test` to the database name there (`config/packages/doctrine.yaml`); the end-to-end tests set `TEST_DATABASE_SUFFIX=` empty.
- Local changes (more published ports, `XDEBUG_MODE` for web requests) go in `compose.override.yaml`, which is not committed.

Tests:

- `tests/Unit/` and `tests/Integration/` mirror `src/`. Integration tests use `.env.test` and `dama/doctrine-test-bundle` for transaction rollback.
- Helpers: `tests/Integration/Helper/` (CSRF tokens, user and persisted-ID traits, MCP client and tool-output traits, fake IMAP/SMTP servers, DKIM verifier) and `Tests\Unit\Helper\Limiters` for rate limiters. Look there before writing a new one.

## Environment variables

`.env` lists them, `config/services.yaml` holds the defaults, and the operator reference is https://jeboehm.github.io/docker-mailserver/reference/mailserver-admin-config/. Constraints to respect when touching them:

- `MCP_ENABLED` (default `false`) switches the MCP endpoint and the API token pages. `MCP_ALLOWED_HOSTS` is matched against the raw `Host` header, so a reverse proxy has to preserve it.
- `MCP_MAIL_READ_ENABLED` and `MCP_MAIL_SEND_ENABLED` (default `false`) need a docker-mailserver with the `mailbox_credentials` passdb.
- `MCP_MAIL_MAX_IMAP_SESSIONS` (default `5`): keep it below Dovecot's `mail_max_userip_connections` of 10, which Roundcube shares.
- `MCP_MAIL_SEND_GLOBAL_LIMIT_PER_MINUTE` (default `15`): keep it below Postfix's 20 connections per minute per client address, which Roundcube shares. A third of it is available at once, the rest refills evenly.
- `MDA_IMAP_TIMEOUT_SECONDS` and `MTA_SMTP_TIMEOUT_SECONDS` (default `25`): keep them well above 15 s, the longest auth penalty.
- `MCP_MAIL_DRAFT_TOKEN_LIMIT_PER_HOUR` is the only draft limit: there is none per user or global, as Postfix is not involved.

## Architecture

`src/` is organised by layer (`Command`, `Controller`, `Entity`, `Form`, `Repository`, `Service`, `Mcp`, `Subscriber`, `Validator`, `Twig`); `src/Service/<Area>/` holds one area per feature, so list the directory rather than trusting a summary. Areas that carry rules:

- **DKIM** (`src/Service/DKIM/`): keys and settings reach the mailserver through Redis. `redis:sync` (`RedisSyncCommand`) publishes **only DKIM data and fetchmail accounts**. Postfix and Dovecot read users and aliases from the database, so a change to them needs no Redis sync.
- **Dovecot and Rspamd clients** (`src/Service/Dovecot/`, `src/Service/Rspamd/`): HTTP clients (doveadm, Rspamd controller), statistics and health checks.
- **Security and access policies** (`src/Service/Security/`): roles, voters and the policies in `Access/`.
- **MCP** (`src/Mcp/`, `src/Service/{ApiToken,Mailbox,MailboxCredential,MailSend,MailDraft,McpSetup}/`): see "MCP tools".

Adding things: a new entity needs its repository, a migration, an EasyAdmin CRUD controller in `src/Controller/Admin/` and tests under `tests/Unit/Entity/` and `tests/Integration/Controller/Admin/`. A new service goes into the matching `src/Service/` area with a unit test in `tests/Unit/Service/`.

## MCP tools

A tool is a service in `App\Mcp\Tool\` with `#[McpTool]` and `#[ToolAccess]` on the method. `App\Mcp\ToolGate` handles every call: it enforces `#[ToolAccess]`, writes the audit record and hides the reason for a denial. `tools/list` follows the same rule.

- `#[IsGranted]` has no effect on tools. Object-level rules go through the policies in `App\Service\Security\Access`.
- For a denied or missing object, throw `App\Mcp\ToolDeniedException`. An `AccessDeniedException` from a policy and a `MailboxDeniedException` are treated the same. Other messages for the caller go in a `Mcp\Exception\ToolCallException`.
- Declare the audit target in `#[ToolAccess(target: 'user:{id}')]`, a template over the tool's arguments, so that it is also recorded when the call is denied before the tool runs. A tool that knows better calls `App\Mcp\Audit\ToolAuditLog::target()`, and adds the rest through `details()`. Never pass bodies, passwords or tokens.
- Tool arguments are snake_case (`$domain_admin`, `$send_only`, `$verify_ssl`, `$unseen_only`), unlike all other PHP variables here: their names are the public tool API, so do not rename them.
- List tools check their page with `App\Mcp\ToolPage::assertRange()`, which refuses a limit or offset out of range rather than correcting it. `ToolPage::of()` does this for Doctrine queries.
- Write through `App\Mcp\ToolEntityWriter`. The calls of a JSON-RPC batch share one entity manager, so anything a rejected call leaves on a managed entity is saved by the next flush. `validate()` reloads a rejected entity; state that is not mapped, such as a plain password, is up to the tool.
- Never read authorisation from the MCP session, which is not bound to a token. Use `App\Mcp\CurrentApiToken` and `App\Service\Security\Access\CurrentActor`.
- Do not use `ClientGateway`: streaming holds a PHP thread the web interface needs. PHP's 30-second limit applies, so give outbound calls explicit timeouts.
- A tool group that depends on a matching docker-mailserver version names a `App\Mcp\ToolFeature` in `#[ToolAccess]`. `ToolAccessPolicy` holds the flag, so listing and calling follow it.
- A mailbox user only ever comes from `CurrentActor`, never from a tool parameter. Reach a mailbox through `App\Service\Mailbox\ImapSessions` and log in anywhere else through `App\Service\MailboxCredential\MailboxCredentials`, which issues one credential per operation and always deletes it. Use ImapEngine's connection commands with `EXAMINE` and `BODY.PEEK`, not its fluent API.
- Do not run these inside a transaction: Dovecot only sees committed rows. For the same reason the end-to-end tests `MailReadEndToEndTest` and `MailSendEndToEndTest` skip the database rollback and need a real stack, see their class comments.
- Send mail through `App\Service\MailSend\MailSender`. Once Postfix has taken a mail, nothing may look like a failed send, or a caller sends it again: a problem with the Sent copy goes in the result. The test environment replaces the transport factory with `Tests\Integration\Mcp\Fixture\FakeSmtpTransportFactory`, which falls through to the real one without a current `FakeSmtpServer`.
- Rate limits are defined in `framework.rate_limiter` (`config/packages/framework.yaml`: `send_token`, `send_user`, `send_global`, `draft_token`) and injected as `#[Target('send_token.limiter')] RateLimiterFactoryInterface`, never built with `new RateLimiterFactory()`. They share the pool `app.mail_send_limit_pool` and the lock factory `app.mail_send_lock_factory`. `MailSendGlobalLimitEnvProcessor` derives the bucket of `send_global` from `MCP_MAIL_SEND_GLOBAL_LIMIT_PER_MINUTE`; unit tests build limiters through `Tests\Unit\Helper\Limiters`.
- The sender of `message_send` and `message_draft` comes from the token's sender mode (`App\Service\ApiToken\SenderMode`: `primary`, the default, `fixed` or `any`) and the optional `from_address`, resolved by `App\Service\MailSend\SenderResolver` before any rate limit or credential is used. `App\Service\MailSend\OwnedAddresses` is the one check of what a user owns: the primary address plus every alias whose destination is exactly the primary address, with both stored in lower case; no catch-all, address extension, alias chain or external destination, and no wider set for any role. Only an `any` token accepts `from_address`: a plain address it owns, matched case-insensitively, defaulting to the primary address; `whoami` lists those addresses in `send_as`. A fixed address that is no longer owned fails the call; nothing revokes the token. The audit record holds `from`. The mode and address are chosen when the token is created and never change; `App\Service\ApiToken\SenderBinding` holds the pair and its rule (a fixed sender has a lower-case address, the others none), and `ApiToken::getSender()` is null for a token without `mail:send` or `mail:draft`, which `ApiTokenScope::isSenderBound()` and `anySenderBound()` decide. The create form (`App\Form\Type\ApiTokenType`) offers `OwnedAddresses` as the only choices of a fixed address, so the server refuses any other whatever is submitted; `ApiTokenIssuer` issues `primary` for a token without `mail:send` or `mail:draft`, and `SenderBinding::chosen()` drops a submitted address unless the mode is `fixed`. The form renders the sender fields hidden unless those scopes are ticked, and the address unless the mode is `fixed`; the Stimulus controller `api_token_sender` toggles them from there. The token list marks a fixed address that is no longer owned, through `SenderResolver::isBoundAddressOwned()`, the check a send uses.
- `message_draft` (`App\Mcp\Tool\MailDraftTools`) saves a composed mail in the owner's Drafts mailbox with `\Draft` and `\Seen` and sends nothing. Its scope `mail:draft` is independent of `mail:send` and is the only gate: no `ToolFeature`, no flag. It takes the same arguments as `message_send` and composes through the same `MailComposer`, saves through `App\Service\MailDraft\MailDrafter` (shared `SpecialUseMailbox`, per-token limit in `MailDraftRateLimiter`). Nothing has happened yet when the save fails, so every failure is a `MailDraftFailedException`, shown as a `ToolCallException` that names a retry only where one can help. There is no "skipped" outcome. The audit record holds `from`, `message_id`, `recipients` and `draft_folder`, never the body.
- `dns_get_record()` cannot be timed out and a domain takes about twenty lookups, so anything validating DNS from a tool goes through `App\Service\DnsWizard\TimeBoxedDnsValidation`.
- The test environment replaces the DNS resolver with `Tests\Integration\Mcp\Fixture\FakeDnsLookup`, so no test depends on real DNS.

## Database migrations

1. Generate: `docker compose exec php bin/console doctrine:migrations:generate`
2. Edit migration in `migrations/`
3. Test: `docker compose exec php bin/console doctrine:migrations:migrate`
4. Test rollback of your own migration: `docker compose exec php bin/console doctrine:migrations:migrate prev`.
   Rolling back further does not work: three of the 2018 migrations never got a
   `down()`, so `AbstractMigration` aborts there.
5. Test against both engines, MySQL and PostgreSQL (`compose.mysql.yaml`)

The migrations up to `Version20250403142933` describe the history of a MySQL
schema that started out as a pre-2018 `virtual_*` schema. They are guarded twice:
with `instanceof AbstractMySQLPlatform`, and with `$schema->hasTable(...)` so they
only apply to installations that actually carry that history. Everything else —
PostgreSQL, and any fresh MySQL database — reaches the baseline migration
`Version20260212000000` with an empty database and gets the schema as it stood
at that point from there. The baseline in turn skips itself when `mail_domains`
already exists. Migrations after the baseline run on every installation, fresh or
not, so they need no `hasTable` guard.

Take the DDL of a new migration verbatim from `doctrine:migrations:diff`, run once
per platform. In particular do not hand-write `DEFAULT CHARACTER SET` / `COLLATE`
clauses — the mapping defines no collation, so whatever DBAL emits is what keeps
`doctrine:migrations:diff` quiet.

Consequences for new migrations:

- Plain `CREATE TABLE` / `ADD COLUMN` statements must avoid MySQL-only syntax
  (`TINYINT(1)`, `LONGTEXT`, `AUTO_INCREMENT`, `ENGINE`, backtick quoting) and
  double-quoted string literals, which denote identifiers on PostgreSQL. Inside a
  block that already branched on the platform, that syntax is fine and expected.
- Anything referencing a constraint by name (`DROP FOREIGN KEY`, `DROP INDEX`)
  must branch per platform: MySQL installations carry legacy constraint names
  from before the 2018 table rename, PostgreSQL ones the canonical names.
- Existing migrations are never changed, and that includes the baseline. A
  mapping change gets a new migration; the `phpunit` CI job fails otherwise.

Note that `doctrine:schema:validate` reports doctrine's own `migration_versions`
table as drift. Use `doctrine:migrations:diff`, which excludes it.

CI builds the test schema exclusively from the migrations — `doctrine:schema:create`
is never used to set up a database. Every
`phpunit` matrix leg migrates an empty database, migrates a second time to prove
idempotence, gates on `doctrine:migrations:diff`, seeds and then runs the suite.


## Documentation

- Project README: `README.md`
- Development guide: https://jeboehm.github.io/docker-mailserver/development/mailserver-admin/
- Configuration reference: https://jeboehm.github.io/docker-mailserver/reference/mailserver-admin-config/
- Administration docs: https://jeboehm.github.io/docker-mailserver/administration/dashboard/

## Agent skills

### Issue tracker

Issues and specs are tracked in GitHub Issues on `jeboehm/mailserver-admin`, using the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Uses the default label vocabulary: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `GLOSSARY.md` and `docs/adr/` at the repo root, created lazily when terms or decisions get resolved. See `docs/agents/domain.md`.

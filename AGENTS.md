# mailserver-admin - AI Agent Context

This document provides essential context for AI agents working on the mailserver-admin project.

## Project Purpose

**mailserver-admin** is a web-based administration interface for [docker-mailserver](https://github.com/jeboehm/docker-mailserver). It provides a comprehensive management interface for mail domains, users, aliases, DKIM settings, and fetchmail configurations.

### Core Features

- **Domain Management**: Add, edit, and delete mail domains
- **User Management**: Create, update, and remove mail users with password management
- **Alias Management**: Define mail aliases for email forwarding
- **DKIM Management**: Configure DKIM settings for email authenticity
- **Fetchmail Configuration**: Set up and manage Fetchmail for external email retrieval
- **OAuth2 Integration**: Secure authentication using OAuth2 providers
- **DNS Setup Wizard**: Interactive wizard for DNS record configuration
- **Dashboard**: Monitoring and statistics for Dovecot and Rspamd services

## Technical Stack

### Core Framework

- **PHP**: >= 8.4 (strict types enabled)
- **Symfony**: 7.4.x (Framework Bundle, Console, Form, Security, Twig, etc.)
- **EasyAdmin Bundle**: v4.27.8 (admin interface)
- **Doctrine ORM**: 3.6.1 (database abstraction)
- **Doctrine Migrations**: 3.7.0 (database versioning)

### Key Dependencies

- **HWIOAuthBundle**: v2.4.0 (OAuth2 authentication)
- **Predis**: v3.3.0 (Redis client)
- **Symfony Asset Mapper**: Modern asset management
- **Symfony Stimulus Bundle**: JavaScript framework integration
- **Symfony UX Chart.js**: Dashboard visualizations

### Development Tools

- **PHP CS Fixer**: v3.93.0 (code style)
- **PHPStan**: Level max static analysis, with the Symfony, Doctrine and PHPUnit extensions
- **PHPUnit**: ^12.0 (testing)
- **Rector**: 2.3.4 (code refactoring)
- **devenv**: Reproducible development environment (Nix-based)

### Infrastructure

- **MySQL/MariaDB or PostgreSQL**: Database (via Doctrine)
- **Redis**: Caching and data synchronization
- **Caddy**: Web server (development)
- **PHP-FPM**: PHP execution (development)

## Architecture Overview

### Service Architecture

The application integrates with multiple external services:

1. **docker-mailserver** (via Dovecot)
   - User authentication and management
   - Mailbox operations
   - Health checks via `doveadm` HTTP API

2. **Rspamd** (Spam filtering)
   - Statistics and monitoring
   - Configuration management
   - Health checks via HTTP API

3. **Redis**
   - Data synchronization between admin interface and mailserver
   - Caching for performance
   - Runtime data storage

4. **Database**
   - Primary data storage for domains, users, aliases
   - Doctrine ORM for database operations

### Directory Structure

```
mailserver-admin/
├── src/                    # Application source code
│   ├── Command/           # Symfony console commands
│   ├── Controller/        # HTTP controllers (Admin, User, Autoconfig, Security)
│   ├── Entity/            # Doctrine entities (Domain, User, Alias, etc.)
│   ├── Form/              # Symfony form types
│   ├── Repository/        # Doctrine repositories
│   ├── Service/           # Business logic services
│   │   ├── DKIM/         # DKIM key management and configuration
│   │   ├── Dovecot/      # Dovecot integration services
│   │   ├── Rspamd/       # Rspamd integration services
│   │   ├── FetchmailAccount/  # Fetchmail configuration
│   │   ├── DnsWizard/    # DNS validation wizard
│   │   └── Security/     # Authentication and authorization
│   ├── Subscriber/        # Doctrine event subscribers
│   ├── Validator/         # Custom validation constraints
│   └── Twig/             # Twig extensions
├── tests/                 # Test suite
│   ├── Unit/             # Unit tests
│   └── Integration/      # Integration tests
├── config/                # Symfony configuration
├── migrations/            # Database migrations
├── templates/            # Twig templates
├── public/               # Web root
├── assets/               # Frontend assets (JavaScript, CSS)
└── bin/                  # Executable scripts
```

### Key Services

#### DKIM Services (`src/Service/DKIM/`)

- **KeyGenerationService**: Generates DKIM key pairs
- **DKIMStatusService**: Checks DKIM configuration status
- **Config/Manager**: Manages DKIM configuration files
- **Config/MapGenerator**: Generates configuration maps

#### Dovecot Services (`src/Service/Dovecot/`)

- **DoveadmHttpClient**: HTTP client for Dovecot admin API
- User management operations
- Health check integration

#### Rspamd Services (`src/Service/Rspamd/`)

- **RspamdControllerClient**: HTTP client for Rspamd API
- **RspamdStatsService**: Statistics and monitoring
- Health check integration

#### Security Services (`src/Service/Security/`)

- **OAuth User Provider**: OAuth2 user authentication
- Role management (ROLE_ADMIN, ROLE_DOMAIN_ADMIN, ROLE_USER)
- Password hashing and validation

## Development Environment

### Prerequisites

- **Nix**: Required for devenv
- **direnv**: Automatically loads development environment
- **Composer**: PHP dependency management

### Setup

1. **Start development environment**:

   ```bash
   devenv up
   ```

   This starts:
   - PHP 8.4 with Redis, PDO MySQL, PDO PostgreSQL, Xdebug extensions
   - MySQL database server (PostgreSQL optionally, see devenv.nix)
   - Redis server
   - Caddy web server on port 8000

2. **Install dependencies**:

   ```bash
   composer install
   ```

3. **Load environment** (via direnv or manually):
   ```bash
   eval "$(direnv export bash)"
   ```

### Important Development Notes

**CRITICAL**: Always load the devenv environment before running commands:

```bash
eval "$(direnv export bash)" && <command>
```

This ensures:

- Correct PHP version (8.4)
- All dependencies available
- Environment variables properly set
- Database and Redis connections configured

### Development Commands

- **Code Style**: `composer run csfix` (PHP CS Fixer)
- **Static Analysis**: `composer run phpstan` (PHPStan level max)
- **Tests**: `composer run test` (PHPUnit)
- **Refactoring**: `composer run rector` (Rector)
- **Coverage**: `composer run coverage` (with Xdebug)

## Code Standards

### PHP Coding Standards

- **PSR-2**: Base coding standard
- **Symfony**: Symfony coding standards
- **Strict Types**: `declare(strict_types=1);` required in all files
- **Header Comments**: All files must include package header comment
- **Array Syntax**: Short array syntax `[]` required
- **Ordered Imports**: Alphabetically ordered imports
- **Ordered Class Elements**: Consistent class element ordering

### Code Style Configuration

- **PHP CS Fixer**: `.php-cs-fixer.dist.php`
- **PHPStan**: Level max analysis (`phpstan.dist.neon`, the only place that sets the level). Neither a baseline nor `ignoreErrors` is used
- **Rector**: Automated refactoring (`rector.php`)

### File Structure Standards

- **Namespace**: `App\` for all application code
- **Test Namespace**: `Tests\` for all test code
- **PSR-4 Autoloading**: Standard PSR-4 structure

## Key Patterns and Conventions

### Entity Management

- Use Doctrine entities for all database models
- Repositories extend `ServiceEntityRepository`
- Use Doctrine migrations for schema changes

### Service Layer

- Services are typically `readonly` classes
- Dependency injection via constructor
- Services handle business logic, not controllers

### Controllers

- **Admin Controllers**: EasyAdmin CRUD controllers
- **User Controllers**: User-facing functionality
- **Autoconfig Controllers**: Email client autoconfiguration

### Security

- **Role Hierarchy**: `ROLE_ADMIN > ROLE_DOMAIN_ADMIN > ROLE_USER`
- **Protected admins**: A domain admin can read a user with the `admin` flag in their domain, but not change or delete them. `UserPolicy::canModify()` and `canDelete()` hold the rule for the web interface and the MCP tools.
- **OAuth2**: Configurable via environment variables
- **CSRF Protection**: Configurable via `CSRF_ENABLED` env var
- **Form Login**: Traditional username/password authentication

### Error Handling

- Custom exceptions in `src/Exception/`
- Domain-specific exceptions (DKIM, Dovecot, Domain)
- Proper exception hierarchy

### Testing

- **Unit Tests**: `tests/Unit/` - Isolated component testing
- **Integration Tests**: `tests/Integration/` - Full stack testing
- **Test Environment**: Uses `.env.test` configuration
- **Database**: Uses `dama/doctrine-test-bundle` for transaction rollback

## Environment Variables

Key environment variables (see `.env` and `config/services.yaml`):

- `DATABASE_URL`: Database connection string (`mysql://` or `postgresql://`)
- `REDIS_DSN`: Redis connection string
- `OAUTH_ENABLED`: Enable/disable OAuth2
- `OAUTH_BUTTON_TEXT`: OAuth button label
- `OAUTH_ADMIN_GROUP`: OAuth group for admin role
- `CSRF_ENABLED`: Enable/disable CSRF protection
- `RSPAMD_TIMEOUT_MS`: Rspamd API timeout
- `RSPAMD_CACHE_TTL_SECONDS`: Rspamd cache TTL
- `MCP_ENABLED`: Enable/disable the MCP endpoint and the API token pages (default `false`)
- `MCP_ALLOWED_HOSTS`: Comma-separated hosts the MCP endpoint answers for. Matched against the raw `Host` header, so a reverse proxy has to preserve it
- `MCP_TOKEN_MAX_LIFETIME_DAYS`: Default and maximum lifetime of an API token (default `90`)
- `MCP_MAIL_READ_ENABLED`: Enable/disable the mail read tools, which need a docker-mailserver with the `mailbox_credentials` passdb (default `false`)
- `MCP_MAIL_MAX_IMAP_SESSIONS`: Parallel IMAP sessions per user. Keep it below Dovecot's `mail_max_userip_connections` of 10, which Roundcube shares (default `5`)
- `MCP_MAIL_MESSAGE_MAX_BYTES`: Size limit of the body `message_get` returns (default `65536`)
- `MCP_MAIL_DRAFT_TOKEN_LIMIT_PER_HOUR`: Drafts per API token for `message_draft`. There is no per-user or global limit, as Postfix is not involved (default `60`)
- `MDA_IMAPS_ADDRESS`: Dovecot's implicit TLS address as `host:port` (default `mda:31993`)
- `MDA_IMAP_TIMEOUT_SECONDS`: IMAP client timeout. Keep it well above 15 s, the longest auth penalty (default `25`)
- `MCP_MAIL_SEND_ENABLED`: Enable/disable `message_send`, which needs a docker-mailserver with the `mailbox_credentials` passdb (default `false`)
- `MCP_MAIL_SEND_MAX_RECIPIENTS`: Recipients per mail, across `to`, `cc` and `bcc` (default `20`)
- `MCP_MAIL_SEND_MAX_BYTES`: Size limit of a sent message as it goes over the wire (default `1048576`)
- `MCP_MAIL_SEND_TOKEN_LIMIT_PER_HOUR`: Sends per API token (default `30`)
- `MCP_MAIL_SEND_USER_LIMIT_PER_HOUR`: Sends per user, across their tokens (default `60`)
- `MCP_MAIL_SEND_GLOBAL_LIMIT_PER_MINUTE`: Sends per rolling minute across everyone. Keep it below Postfix's 20 connections per minute per client address, which Roundcube shares. A third of it is available at once, the rest refills evenly (default `15`)
- `MTA_SMTP_SUBMISSION_ADDRESS`: Postfix's submission address as `host:port` (default `mta:587`)
- `MTA_SMTP_TIMEOUT_SECONDS`: SMTP client timeout. Keep it well above 15 s, the longest auth penalty (default `25`)

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
- The sender of `message_send` and `message_draft` comes from the token's sender mode (`App\Service\ApiToken\SenderMode`: `primary`, the default, `fixed` or `any`) and the optional `from_address`, resolved by `App\Service\MailSend\SenderResolver` before any rate limit or credential is used. `App\Service\MailSend\OwnedAddresses` is the one check of what a user owns: the primary address plus every alias with it as a destination, stored in lower case; no catch-all, address extension, alias chain or external destination, and no wider set for any role. Only an `any` token accepts `from_address`: a plain address it owns, matched case-insensitively, defaulting to the primary address; `whoami` lists those addresses in `send_as`. A fixed address that is no longer owned fails the call; nothing revokes the token. The audit record holds `from`. The mode and address are chosen when the token is created and never change. The create form (`App\Form\Type\ApiTokenType`) offers `OwnedAddresses` as the only choices of a fixed address, so the server refuses any other whatever is submitted; `ApiTokenIssuer` issues `primary` for a token without `mail:send` or `mail:draft` and drops the address unless the mode is `fixed`. Showing the sender fields only for those scopes is client-side, in the Stimulus controller `api_token_sender`. The token list marks a fixed address that is no longer owned.
- `message_draft` (`App\Mcp\Tool\MailDraftTools`) saves a composed mail in the owner's Drafts mailbox with `\Draft` and `\Seen` and sends nothing. Its scope `mail:draft` is independent of `mail:send` and is the only gate: no `ToolFeature`, no flag. It takes the same arguments as `message_send` and composes through the same `MailComposer`, saves through `App\Service\MailDraft\MailDrafter` (shared `SpecialUseMailbox`, per-token limit in `MailDraftRateLimiter`). Nothing has happened yet when the save fails, so every failure is a `MailDraftFailedException`, shown as a `ToolCallException` that names a retry only where one can help. There is no "skipped" outcome. The audit record holds `message_id`, `recipients` and `draft_folder`, never the body.
- `dns_get_record()` cannot be timed out and a domain takes about twenty lookups, so anything validating DNS from a tool goes through `App\Service\DnsWizard\TimeBoxedDnsValidation`.
- The test environment replaces the DNS resolver with `Tests\Integration\Mcp\Fixture\FakeDnsLookup`, so no test depends on real DNS.

## Integration Points

### docker-mailserver Integration

- **Dovecot**: User management, authentication, health checks
- **Redis Sync**: Synchronizes user/alias data to mailserver
- **DKIM Keys**: Manages DKIM private keys for mailserver
- **Fetchmail**: Configures fetchmail accounts

### External Services

- **OAuth2 Provider**: Configurable OAuth2 authentication
- **DNS**: DNS validation wizard for mail server setup
- **Mobile Config**: Generates iOS/macOS email profiles

## Common Tasks

### Adding a New Entity

1. Create entity class in `src/Entity/`
2. Create repository in `src/Repository/`
3. Generate migration: `php bin/console doctrine:migrations:generate`
4. Create EasyAdmin CRUD controller in `src/Controller/Admin/`
5. Add tests in `tests/Unit/Entity/` and `tests/Integration/Controller/Admin/`

### Adding a New Service

1. Create service class in appropriate `src/Service/` subdirectory
2. Use constructor dependency injection
3. Make service `readonly` if immutable
4. Add unit tests in `tests/Unit/Service/`

### Database Migrations

1. Generate: `php bin/console doctrine:migrations:generate`
2. Edit migration in `migrations/`
3. Test: `php bin/console doctrine:migrations:migrate`
4. Test rollback of your own migration: `php bin/console doctrine:migrations:migrate prev`.
   Rolling back further does not work: three of the 2018 migrations never got a
   `down()`, so `AbstractMigration` aborts there.
5. Test against both engines, MySQL and PostgreSQL

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

- **Project README**: `README.md`
- **Development Guide**: https://jeboehm.github.io/docker-mailserver/development/mailserver-admin/
- **Configuration Guide**: https://jeboehm.github.io/docker-mailserver/configuration/mailserver-admin/
- **Administration Docs**: https://jeboehm.github.io/docker-mailserver/administration/

## Important Notes for AI Agents

1. **Always use devenv**: Commands must run within the devenv environment
2. **Strict Types**: All PHP files must declare strict types
3. **Service Layer**: Business logic belongs in services, not controllers
4. **Doctrine**: Use Entity API, not direct database queries. Exception: `MailboxCredentials` uses DBAL, because a credential's expiry has to come from the database clock that Dovecot compares it with
5. **Testing**: Maintain high test coverage, especially for services
6. **Code Style**: Run `composer run csfix` before committing
7. **Static Analysis**: Ensure PHPStan passes at level max
8. **Redis Sync**: Changes to users/aliases must sync to Redis for mailserver
9. **DKIM Keys**: Private keys are stored securely, never expose in logs
10. **OAuth**: OAuth integration is optional, check `OAUTH_ENABLED` before use

## Project-Specific Conventions

- **British English**: Use British spelling in documentation and comments
- **Git Commits**: Follow conventional commits (fix:, feat:, docs:, etc.)
- **Code Quality**: Follow clean code principles, DRY, single responsibility

## Agent skills

### Issue tracker

Issues and specs are tracked in GitHub Issues on `jeboehm/mailserver-admin`, using the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Uses the default label vocabulary: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `GLOSSARY.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.

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
- **PHPStan**: Level 6 static analysis
- **PHPUnit**: ^12.0 (testing)
- **Rector**: 2.3.4 (code refactoring)
- **devenv**: Reproducible development environment (Nix-based)

### Infrastructure

- **MySQL/MariaDB**: Database (via Doctrine)
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

4. **MySQL**
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
   - PHP 8.4 with Redis, PDO MySQL, Xdebug extensions
   - MySQL database server
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
- **Static Analysis**: `composer run phpstan` (PHPStan level 6)
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
- **PHPStan**: Level 6 analysis (`phpstan.dist.neon`)
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

- `DATABASE_URL`: MySQL connection string
- `REDIS_DSN`: Redis connection string
- `OAUTH_ENABLED`: Enable/disable OAuth2
- `OAUTH_BUTTON_TEXT`: OAuth button label
- `OAUTH_ADMIN_GROUP`: OAuth group for admin role
- `CSRF_ENABLED`: Enable/disable CSRF protection
- `RSPAMD_TIMEOUT_MS`: Rspamd API timeout
- `RSPAMD_CACHE_TTL_SECONDS`: Rspamd cache TTL

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
4. Always test rollback: `php bin/console doctrine:migrations:migrate prev`

## Documentation

- **Project README**: `README.md`
- **Development Guide**: https://jeboehm.github.io/docker-mailserver/development/mailserver-admin/
- **Configuration Guide**: https://jeboehm.github.io/docker-mailserver/configuration/mailserver-admin/
- **Administration Docs**: https://jeboehm.github.io/docker-mailserver/administration/

## Important Notes for AI Agents

1. **Always use devenv**: Commands must run within the devenv environment
2. **Strict Types**: All PHP files must declare strict types
3. **Service Layer**: Business logic belongs in services, not controllers
4. **Doctrine**: Use Entity API, not direct database queries
5. **Testing**: Maintain high test coverage, especially for services
6. **Code Style**: Run `composer run csfix` before committing
7. **Static Analysis**: Ensure PHPStan passes at level 6
8. **Redis Sync**: Changes to users/aliases must sync to Redis for mailserver
9. **DKIM Keys**: Private keys are stored securely, never expose in logs
10. **OAuth**: OAuth integration is optional, check `OAUTH_ENABLED` before use

## Project-Specific Conventions

- **British English**: Use British spelling in documentation and comments
- **Git Commits**: Follow conventional commits (fix:, feat:, docs:, etc.)
- **Code Quality**: Follow clean code principles, DRY, single responsibility

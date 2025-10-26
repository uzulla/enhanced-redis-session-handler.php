# Development Environment Setup

This document describes how to set up the development environment for enhanced-redis-session-handler.php.

## Requirements

### PHP
- **Version**: 7.4 or higher
- **Required Extensions**:
  - ext-redis

### Composer
- **Version**: 2.0 or higher

## Setup Options

You can set up the development environment in two ways:

### Option 1: Docker Environment (Recommended)

The easiest way to get started is using Docker. This provides a complete development environment with PHP 7.4, Apache, and ValKey.

```bash
# Start the environment
docker compose -f docker/docker-compose.yml up -d

# Run health check to verify everything is working
./docker/healthcheck.sh

# Access the environment
docker compose -f docker/docker-compose.yml exec app bash
```

The Docker environment includes:
- PHP 7.4.33 with Apache
- ext-redis extension
- Composer 2.x
- ValKey (Redis-compatible) storage

Access points:
- Web server: http://localhost:8080
- ValKey: localhost:6379

Useful commands:
```bash
# Run tests in Docker
docker compose -f docker/docker-compose.yml exec app composer test

# Run static analysis
docker compose -f docker/docker-compose.yml exec app composer phpstan

# View logs
docker compose -f docker/docker-compose.yml logs -f app
docker compose -f docker/docker-compose.yml logs -f storage

# Stop the environment
docker compose -f docker/docker-compose.yml down
```

### Option 2: Local Installation

Install dependencies locally:

```bash
composer install
```

**Note**: As a library, `composer.lock` is not committed to the repository. This allows consuming applications to resolve their own dependency graph. CI automatically resolves the latest compatible dependencies on each run.

This will install the following development tools:
- PHPUnit 9.6+ (testing framework)
- PHPStan 2.0+ with strict rules (static analysis tool)
- PHP CS Fixer 3.0+ (code style checker)

## Development Tools

### Running Tests

```bash
# Run all tests
composer test

# Run tests with text coverage report
composer coverage

# Generate HTML coverage report
composer coverage-report
```

### Static Analysis

```bash
# Run PHPStan analysis
composer phpstan

# Run code style check
composer cs-check

# Fix code style issues automatically
composer cs-fix
```

### Combined Checks

```bash
# Run all linting checks (PHPStan + code style)
composer lint

# Run all checks (linting + tests)
composer check
```

## Configuration Files

### phpunit.xml
PHPUnit configuration file. Defines test directories and coverage settings.

### phpstan.neon
PHPStan configuration file. Set to maximum analysis level with strict rules enabled.

### .php-cs-fixer.php
PHP CS Fixer configuration file. Enforces PSR-12 coding standards.

## Continuous Integration

The following checks are automatically run via GitHub Actions on every pull request:

1. **Static Analysis**: `composer phpstan`
2. **Code Style**: `composer cs-check`
3. **Tests**: `composer test`

Please ensure all checks pass before submitting a pull request.

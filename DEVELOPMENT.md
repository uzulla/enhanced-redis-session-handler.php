# Development Environment Setup

This document describes how to set up the development environment for enhanced-redis-session-handler.php.

## Requirements

### PHP
- **Version**: 7.4 or higher
- **Required Extensions**:
  - ext-redis

### Composer
- **Version**: 2.0 or higher

## Setup

Install dependencies:

```bash
composer install
```

This will install the following development tools:
- PHPUnit 9.5+ (testing framework)
- PHPStan 1.0+ with strict rules (static analysis tool)
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

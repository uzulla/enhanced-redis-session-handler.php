# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-10-26

### Added

#### Core Features
- **SessionHandlerInterface Implementation**: Complete implementation of PHP's standard `SessionHandlerInterface` for Redis/ValKey backend storage
- **Pluggable Session ID Generators**: Customizable session ID generation with built-in generators:
  - `DefaultSessionIdGenerator`: Standard PHP session ID generation
  - `SecureSessionIdGenerator`: Enhanced security with cryptographically secure random bytes
  - `PrefixedSessionIdGenerator`: Session IDs with custom prefixes for namespace separation
  - `TimestampPrefixedSessionIdGenerator`: Session IDs with timestamp prefixes for debugging and analysis

#### Hook System
- **Read Hooks**: Extensible hook system for session read operations
  - `FallbackReadHook`: Automatic failover to backup Redis instances when primary is unavailable
  - `ReadTimestampHook`: Track and manage session read timestamps
- **Write Hooks**: Extensible hook system for session write operations
  - `DoubleWriteHook`: Write session data to multiple Redis instances simultaneously for redundancy
  - `LoggingHook`: Comprehensive logging of session operations with PSR-3 logger support

#### Configuration Management
- **RedisConnectionConfig**: Type-safe configuration for Redis connections with support for:
  - Host, port, timeout, password, database selection
  - Persistent connections
  - Connection retry logic with configurable intervals and max retries
  - Read timeout configuration
  - Key prefix support
- **SessionConfig**: Centralized session configuration management
- **SessionHandlerFactory**: Factory pattern for easy instantiation with builder pattern support

#### Error Handling
- **Custom Exception Hierarchy**: Specific exception types for different error scenarios:
  - `ConnectionException`: Redis connection failures
  - `OperationException`: Redis operation failures
  - `SessionDataException`: Session data serialization/deserialization errors
  - `HookException`: Hook execution errors
  - `ConfigurationException`: Configuration validation errors

#### Logging and Monitoring
- **PSR-3 Logger Integration**: Full support for PSR-3 compatible loggers (Monolog, etc.)
- **Comprehensive Logging**: Detailed logging of:
  - Connection attempts and failures
  - Session operations (read, write, destroy, gc)
  - Hook execution
  - Error conditions with full context

#### Development Tools
- **Docker Environment**: Complete Docker Compose setup with:
  - PHP 7.4 with Apache
  - ValKey (Redis-compatible) storage
  - Health check scripts
- **Testing Infrastructure**:
  - 144 unit and integration tests
  - 85% code coverage
  - E2E tests for all example code
- **Static Analysis**: PHPStan level max with strict rules
- **Code Style**: PHP CS Fixer with PSR-12 compliance
- **CI/CD**: GitHub Actions workflow for automated testing

#### Documentation
- **Comprehensive Documentation**:
  - Architecture design document
  - Detailed specification document
  - Redis/ValKey integration guide
  - Factory usage guide
  - Write hooks documentation
- **Practical Examples**: 5 complete working examples:
  - Basic usage
  - Custom session ID generators
  - Double write for redundancy
  - Fallback read for high availability
  - Logging configuration
- **Bilingual Support**: Documentation in both Japanese and English

### Technical Details

#### Requirements
- PHP 7.4 or higher
- ext-redis extension
- Redis/ValKey 5.0 or higher
- PSR-3 logger interface (psr/log)

#### Performance Features
- Connection pooling support
- Persistent connection option
- Configurable retry logic with exponential backoff
- Efficient key management with prefix support
- TTL-based automatic session expiration

#### Security Features
- Secure session ID generation with cryptographically secure random bytes
- Password-protected Redis connection support
- No sensitive data logging in production mode
- Proper exception handling to prevent information leakage

#### Compatibility
- PHP 7.4, 8.0, 8.1, 8.2, 8.3 compatibility
- Monolog 2.x and 3.x support
- Redis and ValKey compatibility
- PSR-3, PSR-4 compliance

### Development

#### Testing
- PHPUnit 9.6 test suite
- Unit tests for all core components
- Integration tests for Redis operations
- E2E tests for example code
- Code coverage reporting with PCOV

#### Quality Assurance
- PHPStan static analysis at maximum level
- Strict type checking enabled
- PHP CS Fixer for code style consistency
- Automated CI/CD pipeline

### Notes

This is the initial stable release of enhanced-redis-session-handler.php. The library provides a production-ready, extensible session handler for PHP applications using Redis/ValKey as backend storage.

All features have been thoroughly tested and documented. The library follows PHP best practices and coding standards.

[1.0.0]: https://github.com/uzulla/enhanced-redis-session-handler.php/releases/tag/v1.0.0

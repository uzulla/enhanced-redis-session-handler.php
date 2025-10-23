#!/bin/bash


set -e

echo "=== Docker Environment Health Check ==="
echo ""

if command -v docker-compose &> /dev/null; then
    DOCKER_COMPOSE="docker-compose"
elif docker compose version &> /dev/null; then
    DOCKER_COMPOSE="docker compose"
else
    echo "❌ Docker Compose is not available"
    exit 1
fi

echo "1. Checking Docker..."
if ! docker info > /dev/null 2>&1; then
    echo "❌ Docker is not running"
    exit 1
fi
echo "✓ Docker is running"
echo ""

echo "2. Checking containers..."
if ! $DOCKER_COMPOSE ps | grep -q "Up"; then
    echo "❌ Containers are not running. Please run: $DOCKER_COMPOSE up -d"
    exit 1
fi
echo "✓ Containers are running"
echo ""

echo "3. Checking app container..."
if ! $DOCKER_COMPOSE exec -T app php -v > /dev/null 2>&1; then
    echo "❌ App container is not responding"
    exit 1
fi
PHP_VERSION=$($DOCKER_COMPOSE exec -T app php -v | head -n 1)
echo "✓ App container is running: $PHP_VERSION"
echo ""

echo "4. Checking ext-redis..."
if ! $DOCKER_COMPOSE exec -T app php -m | grep -q "redis"; then
    echo "❌ ext-redis is not installed"
    exit 1
fi
echo "✓ ext-redis is installed"
echo ""

echo "5. Checking ValKey connection..."
if ! $DOCKER_COMPOSE exec -T app php -r "
\$redis = new Redis();
if (!\$redis->connect('storage', 6379)) {
    exit(1);
}
echo 'Connected to ValKey';
" > /dev/null 2>&1; then
    echo "❌ Cannot connect to ValKey"
    exit 1
fi
echo "✓ ValKey connection successful"
echo ""

echo "6. Checking Composer..."
if ! $DOCKER_COMPOSE exec -T app composer --version > /dev/null 2>&1; then
    echo "❌ Composer is not installed"
    exit 1
fi
COMPOSER_VERSION=$($DOCKER_COMPOSE exec -T app composer --version)
echo "✓ Composer is installed: $COMPOSER_VERSION"
echo ""

echo "=== All checks passed! ==="
echo ""
echo "You can now use the development environment:"
echo "  - Web server: http://localhost:8080"
echo "  - ValKey: localhost:6379"
echo ""
echo "Useful commands:"
echo "  $DOCKER_COMPOSE exec app bash          # Enter app container"
echo "  $DOCKER_COMPOSE exec app composer test # Run tests"
echo "  $DOCKER_COMPOSE logs -f app            # View app logs"
echo "  $DOCKER_COMPOSE logs -f storage        # View ValKey logs"

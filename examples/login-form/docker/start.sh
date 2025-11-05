#!/bin/bash

# Start script for login-form Docker environment
# ログインフォームDocker環境の起動スクリプト

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "=========================================="
echo "Login Form Docker Environment"
echo "=========================================="
echo ""

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo "Error: Docker is not running. Please start Docker first."
    exit 1
fi

# Build and start containers
echo "Building and starting containers..."
docker-compose up -d --build

echo ""
echo "Waiting for services to be ready..."
sleep 5

# Check if containers are running
if docker-compose ps | grep -q "Up"; then
    echo ""
    echo "=========================================="
    echo "Services are ready!"
    echo "=========================================="
    echo ""
    echo "Access the application:"
    echo ""
    echo "  Redis Extension Handler:"
    echo "    http://localhost:8080/"
    echo ""
    echo "  Enhanced Redis Session Handler:"
    echo "    http://localhost:8081/"
    echo ""
    echo "Demo accounts:"
    echo "  - admin / admin123 (Admin)"
    echo "  - user1 / password1 (User)"
    echo "  - user2 / password2 (User)"
    echo ""
    echo "To view logs:"
    echo "  docker-compose logs -f"
    echo ""
    echo "To stop:"
    echo "  docker-compose down"
    echo ""
    echo "=========================================="
else
    echo ""
    echo "Error: Some containers failed to start."
    echo "Run 'docker-compose logs' to see the error details."
    exit 1
fi

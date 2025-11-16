#!/bin/bash

# Stop script for login-form Docker environment
# ログインフォームDocker環境の停止スクリプト

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "Stopping login-form Docker environment..."
docker-compose down

echo ""
echo "Stopped successfully."
echo ""
echo "To remove volumes (Redis data), run:"
echo "  docker-compose down -v"

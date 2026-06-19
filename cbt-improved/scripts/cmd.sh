#!/bin/bash

# CBT-Improved Command Wrapper Script
# This script provides a convenient wrapper for Docker commands

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Load environment variables
if [ -f "$PROJECT_ROOT/.env" ]; then
    export $(grep -v '^#' "$PROJECT_ROOT/.env" | xargs)
fi

# Default values
export CONTAINER_NGINX=${CONTAINER_NGINX:-cbt_nginx}
export CONTAINER_PHP=${CONTAINER_PHP:-cbt_php}
export CONTAINER_WEBSOCKET=${CONTAINER_WEBSOCKET:-cbt_websocket}
export CONTAINER_DB=${CONTAINER_DB:-cbt_postgresql}
export CONTAINER_REDIS=${CONTAINER_REDIS:-cbt_redis}
export CONTAINER_PGADMIN=${CONTAINER_PGADMIN:-cbt_pgadmin}

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_header() {
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}  CBT-Improved - $1${NC}"
    echo -e "${BLUE}========================================${NC}"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_info() {
    echo -e "${YELLOW}ℹ $1${NC}"
}

show_help() {
    cat << EOF
CBT-Improved Command Wrapper

Usage: $0 <command> [options]

Commands:
  up              Start all services (default: detached mode)
  down            Stop all services
  restart         Restart all services
  logs            View logs from all services
  logs <service>  View logs from specific service
  shell           Enter PHP container shell
  exec <cmd>      Execute command in PHP container
  composer <cmd>  Run composer command in PHP container
  php <cmd>       Run PHP command in PHP container
  artisan <cmd>   Run spark/artisan command in PHP container
  migrate         Run database migrations
  seed            Run database seeders
  test            Run PHPUnit tests
  build           Build/rebuild containers
  status          Show container status
  clean           Remove orphaned containers and volumes
  help            Show this help message

Examples:
  $0 up                    # Start all services
  $0 up -d                 # Start in background
  $0 logs php              # View PHP container logs
  $0 shell                 # Enter PHP container
  $0 composer install      # Install dependencies
  $0 php spark migrate     # Run migrations
  $0 test                  # Run tests

EOF
}

# Change to project root
cd "$PROJECT_ROOT"

case "${1:-help}" in
    up)
        print_header "Starting Services"
        docker compose up -d "${@:2}"
        print_success "Services started successfully!"
        print_info "Access the application at: http://localhost:8080"
        print_info "Access pgAdmin at: http://localhost:8081"
        ;;
    
    down)
        print_header "Stopping Services"
        docker compose down
        print_success "Services stopped successfully!"
        ;;
    
    restart)
        print_header "Restarting Services"
        docker compose restart
        print_success "Services restarted successfully!"
        ;;
    
    logs)
        if [ -n "$2" ]; then
            docker compose logs -f "$2"
        else
            docker compose logs -f
        fi
        ;;
    
    shell|sh)
        print_info "Entering PHP container..."
        docker compose exec php bash
        ;;
    
    exec)
        shift
        docker compose exec php "$@"
        ;;
    
    composer)
        shift
        docker compose exec php composer "$@"
        ;;
    
    php|spark)
        shift
        docker compose exec php php "$@"
        ;;
    
    migrate)
        print_header "Running Migrations"
        docker compose exec php php spark migrate
        print_success "Migrations completed!"
        ;;
    
    seed)
        print_header "Running Seeders"
        docker compose exec php php spark db:seed
        print_success "Seeders completed!"
        ;;
    
    test|phpunit)
        print_header "Running Tests"
        docker compose exec php vendor/bin/phpunit "${@:2}"
        ;;
    
    build)
        print_header "Building Containers"
        docker compose build --no-cache "${@:2}"
        print_success "Build completed!"
        ;;
    
    status)
        print_header "Container Status"
        docker compose ps
        ;;
    
    clean)
        print_header "Cleaning Up"
        docker compose down -v --remove-orphans
        print_success "Cleanup completed!"
        ;;
    
    health)
        print_header "Health Check"
        docker compose ps
        echo ""
        print_info "Testing Redis connection..."
        docker compose exec redis redis-cli -a "${REDIS_PASSWORD:-redis_secret_password}" ping || print_error "Redis connection failed"
        echo ""
        print_info "Testing PostgreSQL connection..."
        docker compose exec postgresql pg_isready -U "${DB_USERNAME:-cbt_user}" -d "${DB_DATABASE:-cbt_improved}" || print_error "PostgreSQL connection failed"
        ;;
    
    help|--help|-h)
        show_help
        ;;
    
    *)
        print_error "Unknown command: $1"
        echo ""
        show_help
        exit 1
        ;;
esac

exit 0

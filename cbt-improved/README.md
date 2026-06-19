# CBT-Improved: Modern Computer-Based Testing Platform

A next-generation Computer-Based Test (CBT) system built with **PHP 8.3+**, **PostgreSQL**, and **Redis**, designed for high scalability, real-time monitoring, and enhanced security features.

## 🚀 Key Improvements over CBT-MF

### Architecture Enhancements
- **PostgreSQL Support**: Full support for PostgreSQL with advanced JSONB queries, window functions, and better concurrency handling
- **Repository Pattern**: Clean separation of business logic and data access layers
- **Service Layer**: Dedicated service classes for complex business operations
- **Entity System**: Strongly-typed entity classes with validation
- **Event-Driven Architecture**: Decoupled components using event dispatcher pattern

### Performance Improvements
- **Query Optimization**: Strategic indexing and query caching strategies
- **Connection Pooling**: Efficient database connection management
- **Redis Cluster Ready**: Support for Redis clustering for horizontal scaling
- **Lazy Loading**: On-demand resource loading for better memory management
- **Prepared Statements**: All queries use prepared statements for security and performance

### Security Enhancements
- **Multi-Factor Authentication (MFA)**: Built-in TOTP support
- **Advanced Anti-Cheat**: AI-powered suspicious activity detection
- **Rate Limiting**: Per-endpoint rate limiting with Redis
- **CSP Headers**: Strict Content-Security-Policy implementation
- **Audit Logging**: Comprehensive activity tracking

### Real-Time Features
- **WebSocket 2.0**: Improved WebSocket server with ReactPHP v3
- **Live Proctoring**: Real-time video/audio monitoring capability
- **Instant Analytics**: Live exam statistics and performance metrics
- **Collaborative Editing**: Real-time question bank collaboration

### Developer Experience
- **Type Safety**: Extensive use of PHP types and strict typing
- **PSR Compliance**: PSR-4, PSR-12, PSR-7, PSR-15 compliant
- **API-First Design**: RESTful API with OpenAPI/Swagger documentation
- **Testing Framework**: PHPUnit with comprehensive test coverage
- **CI/CD Ready**: GitHub Actions workflows included

## 🛠 Technology Stack

- **Backend**: PHP 8.3+ with strict types
- **Database**: PostgreSQL 16+ (with MariaDB/MySQL support)
- **Cache/Queue**: Redis 7+
- **Web Server**: Nginx with PHP-FPM
- **Real-Time**: ReactPHP + Ratchet WebSocket
- **Container**: Docker & Docker Compose

## 📦 Installation

### Prerequisites
- Docker & Docker Compose
- Git

### Quick Start

1. **Clone the repository**
```bash
git clone <repository-url> cbt-improved
cd cbt-improved
```

2. **Configure Environment**
```bash
cp .env.example .env
cp src/.env.example src/.env
```

Edit `.env` and `src/.env` with your settings:
- Database credentials (PostgreSQL by default)
- Redis configuration
- Application URL
- Security keys

3. **Start Services**
```bash
./scripts/cmd.sh up -d
```

4. **Install Dependencies**
```bash
./scripts/cmd.sh composer install
```

5. **Run Migrations & Seeders**
```bash
./scripts/cmd.sh php spark migrate
./scripts/cmd.sh php spark db:seed
```

6. **Access Application**
- Web Interface: http://localhost:8080
- phpPgAdmin: http://localhost:8081
- API Documentation: http://localhost:8080/api/docs

## 🏗 Project Structure

```
cbt-improved/
├── src/
│   ├── app/
│   │   ├── Commands/          # CLI commands
│   │   ├── Config/            # Application configuration
│   │   ├── Controllers/       # HTTP controllers
│   │   ├── Database/
│   │   │   ├── Migrations/    # Database migrations
│   │   │   └── Seeds/         # Database seeders
│   │   ├── Entities/          # Domain entities
│   │   ├── Events/            # Event classes
│   │   ├── Filters/           # Request filters
│   │   ├── Helpers/           # Helper functions
│   │   ├── Libraries/         # Third-party integrations
│   │   ├── Middleware/        # PSR-15 middleware
│   │   ├── Models/            # Database models
│   │   ├── Repositories/      # Data access layer
│   │   ├── Services/          # Business logic layer
│   │   ├── Tasks/             # Background tasks
│   │   ├── Validation/        # Validation rules
│   │   └── Views/             # View templates
│   ├── public/                # Public assets
│   └── writable/              # Writable directories
├── docker/                    # Docker configurations
├── tests/                     # Test suites
├── docs/                      # Documentation
├── scripts/                   # Utility scripts
└── docker-compose.yml         # Docker orchestration
```

## 🔧 Available Commands

```bash
# Development
./scripts/cmd.sh up -d              # Start all services
./scripts/cmd.sh down               # Stop all services
./scripts/cmd.sh logs               # View logs
./scripts/cmd.sh shell              # Enter PHP container

# Database
./scripts/cmd.sh php spark migrate         # Run migrations
./scripts/cmd.sh php spark migrate:rollback  # Rollback migrations
./scripts/cmd.sh php spark db:seed         # Run seeders

# Testing
./scripts/cmd.sh phpunit                   # Run tests
./scripts/cmd.sh phpunit --coverage-html   # Generate coverage report

# Code Quality
./scripts/cmd.sh composer cs               # Check code style
./scripts/cmd.sh composer cs-fix           # Fix code style
./scripts/cmd.sh composer analyse          # Static analysis
```

## 📊 Key Features

### Exam Management
- Dynamic exam scheduling
- Question randomization algorithms
- Multiple question types (MCQ, Essay, Matching, etc.)
- Automatic grading with manual review option
- Time extension capabilities (real-time)

### Student Experience
- Responsive design for all devices
- Offline-capable with sync
- Progress auto-save every 30 seconds
- Navigation map for questions
- Flag for review functionality

### Admin Dashboard
- Real-time exam monitoring
- Live analytics and reports
- User management with roles
- Bulk operations (import/export)
- Audit trail viewer

### Security Features
- Browser lockdown mode
- Tab-switching detection
- Copy-paste prevention
- Screenshot detection
- IP-based access control
- Session hijacking prevention

## 🔌 API Endpoints

The application provides a comprehensive REST API:

- `GET /api/v1/exams` - List exams
- `POST /api/v1/exams` - Create exam
- `GET /api/v1/exams/{id}` - Get exam details
- `PUT /api/v1/exams/{id}` - Update exam
- `DELETE /api/v1/exams/{id}` - Delete exam
- `POST /api/v1/exams/{id}/start` - Start exam attempt
- `POST /api/v1/exams/{id}/submit` - Submit answers
- `GET /api/v1/results/{attemptId}` - Get results

Full API documentation available at `/api/docs`.

## 🐛 Troubleshooting

### Common Issues

#### Database Connection Failed
```bash
# Check PostgreSQL is running
./scripts/cmd.sh docker ps | grep postgresql

# Verify credentials in src/.env
# Ensure DB_HOST=postgresql (service name)
```

#### Redis Connection Issues
```bash
# Check Redis status
./scripts/cmd.sh docker logs ujian_redis

# Test connection
./scripts/cmd.sh redis-cli -h redis -a $REDIS_PASSWORD ping
```

#### WebSocket Not Connecting
```bash
# Check WebSocket daemon
./scripts/cmd.sh docker logs ujian_websocket

# Restart WebSocket service
./scripts/cmd.sh docker restart ujian_websocket
```

#### Permission Denied Errors
```bash
# Fix permissions
./scripts/cmd.sh chown -R www-data:www-data writable
./scripts/cmd.sh chmod -R 775 writable
```

## 📝 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🤝 Contributing

Contributions are welcome! Please read our [Contributing Guidelines](docs/CONTRIBUTING.md) first.

## 📧 Support

For support, email support@cbt-improved.com or open an issue in the repository.

---

Built with ❤️ using PHP, PostgreSQL, and Redis

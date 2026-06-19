# CBT-Improved Architecture Documentation

## Overview

CBT-Improved is a modern Computer-Based Testing platform built with PHP 8.3+, PostgreSQL, and Redis. This document outlines the architectural improvements over the original CBT-MF project.

## Key Architectural Improvements

### 1. Database Layer (PostgreSQL)

**Why PostgreSQL?**
- Advanced JSONB support for flexible data structures
- Better concurrency handling with MVCC
- Full-text search capabilities
- Window functions for complex analytics
- Stronger data integrity with enums and constraints

**Key Features:**
```sql
-- JSONB columns for flexible storage
CREATE TABLE questions (
    options JSONB,           -- Answer options
    correct_answer JSONB,    -- Correct answers
    tags JSONB              -- Categorization tags
);

-- GIN indexes for fast JSON queries
CREATE INDEX questions_options_gin_idx ON questions USING GIN (options);

-- ENUM types for data integrity
CREATE TYPE exam_status AS ENUM ('draft', 'published', 'active', 'closed', 'archived');
```

### 2. Repository Pattern

The application uses the Repository pattern to separate business logic from data access:

```
src/app/
├── Repositories/     # Data access layer
├── Services/         # Business logic layer
├── Entities/         # Domain objects
└── Models/           # Database models (CodeIgniter)
```

**Example Repository:**
```php
interface ExamRepositoryInterface
{
    public function find(int $id): ?ExamEntity;
    public function findAll(array $filters = []): array;
    public function create(array $data): ExamEntity;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
}
```

### 3. Service Layer

Business logic is encapsulated in service classes:

```php
class ExamService
{
    public function __construct(
        private ExamRepositoryInterface $examRepo,
        private QuestionRepositoryInterface $questionRepo,
        private EventDispatcherInterface $dispatcher
    ) {}
    
    public function createExam(array $data): ExamEntity
    {
        // Complex business logic here
        $exam = $this->examRepo->create($data);
        
        // Dispatch events
        $this->dispatcher->dispatch(new ExamCreatedEvent($exam));
        
        return $exam;
    }
}
```

### 4. Event-Driven Architecture

Decoupled components using events:

```php
// Events
- ExamCreatedEvent
- ExamStartedEvent
- AnswerSubmittedEvent
- CheatDetectedEvent
- ExamCompletedEvent

// Listeners
- SendNotificationListener
- UpdateStatisticsListener
- LogActivityListener
- ProctorAlertListener
```

### 5. Redis Multi-Database Strategy

Different Redis databases for different purposes:

```
Database 0: Default (application cache)
Database 1: Sessions
Database 2: Cache (query results, API responses)
Database 3: Queues (background jobs)
Database 4: WebSocket (pub/sub for real-time features)
```

**Benefits:**
- Logical separation of concerns
- Independent TTL policies
- Easier monitoring and debugging
- Selective flushing

### 6. Real-Time Features (WebSocket 2.0)

Improved WebSocket server with ReactPHP v3:

**Features:**
- Non-blocking I/O
- Redis pub/sub for horizontal scaling
- Heartbeat mechanism
- Automatic reconnection
- Message queuing for offline clients

**Use Cases:**
- Live proctoring alerts
- Real-time exam timer sync
- Instant cheat detection notifications
- Live analytics dashboard
- Collaborative question editing

### 7. Security Enhancements

**Multi-Factor Authentication (MFA):**
- TOTP-based (Google Authenticator compatible)
- Backup codes
- Remember device option

**Advanced Anti-Cheat:**
- Browser fingerprinting
- Tab-switch detection with timestamps
- Copy-paste prevention
- Screenshot detection (where possible)
- IP address monitoring
- Session hijacking prevention

**Rate Limiting:**
- Per-endpoint limits
- Redis-backed counters
- Sliding window algorithm

### 8. API-First Design

RESTful API with OpenAPI/Swagger documentation:

```yaml
/api/v1/exams:
  get:
    summary: List all exams
    parameters:
      - page
      - limit
      - status
      - search
  post:
    summary: Create new exam
    
/api/v1/exams/{id}:
  get:
    summary: Get exam details
  put:
    summary: Update exam
  delete:
    summary: Delete exam
```

### 9. Testing Framework

Comprehensive test coverage:

```
tests/
├── Unit/               # Unit tests
├── Integration/        # Integration tests
├── Feature/           # Feature tests
└── fixtures/          # Test data
```

**Test Types:**
- Unit tests for services and repositories
- Integration tests for API endpoints
- Feature tests for user workflows
- Load tests for performance validation

### 10. Performance Optimizations

**Query Optimization:**
- Strategic indexing
- Query result caching
- Prepared statements
- Connection pooling

**Caching Strategy:**
- Multi-level caching (Redis + OPcache)
- Cache warming on deployment
- Tag-based cache invalidation

**Lazy Loading:**
- On-demand resource loading
- Pagination for large datasets
- Infinite scroll for question lists

## Directory Structure

```
cbt-improved/
├── src/
│   ├── app/
│   │   ├── Commands/          # CLI commands (spark)
│   │   ├── Config/            # Application configuration
│   │   ├── Controllers/       # HTTP controllers
│   │   ├── Database/
│   │   │   ├── Migrations/    # Database migrations
│   │   │   └── Seeds/         # Database seeders
│   │   ├── Entities/          # Domain entities (typed)
│   │   ├── Events/            # Event classes
│   │   ├── Filters/           # Request filters (middleware)
│   │   ├── Helpers/           # Helper functions
│   │   ├── Libraries/         # Third-party integrations
│   │   ├── Middleware/        # PSR-15 middleware
│   │   ├── Models/            # CodeIgniter models
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

## Deployment Considerations

### Environment Variables

Critical environment variables:
- `APP_ENV` (development, testing, production)
- `APP_KEY` (encryption key)
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`
- `WEBSOCKET_HOST`, `WEBSOCKET_PORT`

### Scaling

**Horizontal Scaling:**
- Stateless application containers
- Redis cluster for sessions and cache
- PostgreSQL read replicas
- Load balancer (Nginx/HAProxy)

**Vertical Scaling:**
- Increase PHP-FPM workers
- Optimize PostgreSQL shared_buffers
- Redis memory allocation

### Monitoring

**Health Endpoints:**
- `/health` - Overall system health
- `/health/database` - Database connectivity
- `/health/redis` - Redis connectivity
- `/health/live` - Liveness probe
- `/health/ready` - Readiness probe

**Metrics to Monitor:**
- Request response times
- Database query performance
- Redis hit/miss ratios
- WebSocket connection counts
- Error rates by type

## Migration from CBT-MF

For existing CBT-MF installations:

1. **Database Migration:**
   ```bash
   # Export MariaDB data
   mysqldump -u root cbt > backup.sql
   
   # Import to PostgreSQL (requires transformation)
   # Use provided migration scripts
   ```

2. **Configuration Updates:**
   - Update `.env` for PostgreSQL
   - Update session driver to Redis
   - Configure new Redis databases

3. **Code Updates:**
   - Review custom modifications
   - Update repository references
   - Test all features thoroughly

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on contributing to this project.

## License

MIT License - see [LICENSE](../LICENSE) for details.

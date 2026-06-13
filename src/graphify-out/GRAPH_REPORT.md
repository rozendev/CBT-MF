# Graph Report - .  (2026-06-13)

## Corpus Check
- 194 files · ~86,152 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 704 nodes · 932 edges · 145 communities (88 shown, 57 thin omitted)
- Extraction: 93% EXTRACTED · 7% INFERRED · 0% AMBIGUOUS · INFERRED: 63 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- [[_COMMUNITY_Module & User Administration|Module & User Administration]]
- [[_COMMUNITY_Application Configuration|Application Configuration]]
- [[_COMMUNITY_Security Filters & Middleware|Security Filters & Middleware]]
- [[_COMMUNITY_Composer Dependencies & Autoload|Composer Dependencies & Autoload]]
- [[_COMMUNITY_Excel Report Generation|Excel Report Generation]]
- [[_COMMUNITY_Exam Monitoring & Suspends|Exam Monitoring & Suspends]]
- [[_COMMUNITY_Question Bank Management|Question Bank Management]]
- [[_COMMUNITY_Testing Infrastructure|Testing Infrastructure]]
- [[_COMMUNITY_Exam Core Services|Exam Core Services]]
- [[_COMMUNITY_Database Models Layer|Database Models Layer]]
- [[_COMMUNITY_Test Settings & Scheduling|Test Settings & Scheduling]]
- [[_COMMUNITY_Core Entry Controllers|Core Entry Controllers]]
- [[_COMMUNITY_Subject CRUD Logic|Subject CRUD Logic]]
- [[_COMMUNITY_User Authentication Model|User Authentication Model]]
- [[_COMMUNITY_Group CRUD Logic|Group CRUD Logic]]
- [[_COMMUNITY_Community 15|Community 15]]
- [[_COMMUNITY_Community 16|Community 16]]
- [[_COMMUNITY_Community 17|Community 17]]
- [[_COMMUNITY_Community 18|Community 18]]
- [[_COMMUNITY_Community 19|Community 19]]
- [[_COMMUNITY_Community 20|Community 20]]
- [[_COMMUNITY_Community 21|Community 21]]
- [[_COMMUNITY_Community 22|Community 22]]
- [[_COMMUNITY_Community 23|Community 23]]
- [[_COMMUNITY_Community 24|Community 24]]
- [[_COMMUNITY_Community 25|Community 25]]
- [[_COMMUNITY_Community 26|Community 26]]
- [[_COMMUNITY_Community 27|Community 27]]
- [[_COMMUNITY_Community 28|Community 28]]
- [[_COMMUNITY_Community 29|Community 29]]
- [[_COMMUNITY_Community 30|Community 30]]
- [[_COMMUNITY_Community 31|Community 31]]
- [[_COMMUNITY_Community 32|Community 32]]
- [[_COMMUNITY_Community 33|Community 33]]
- [[_COMMUNITY_Community 34|Community 34]]
- [[_COMMUNITY_Community 35|Community 35]]
- [[_COMMUNITY_Community 36|Community 36]]
- [[_COMMUNITY_Community 37|Community 37]]
- [[_COMMUNITY_Community 38|Community 38]]
- [[_COMMUNITY_Community 39|Community 39]]
- [[_COMMUNITY_Community 40|Community 40]]
- [[_COMMUNITY_Community 41|Community 41]]
- [[_COMMUNITY_Community 42|Community 42]]
- [[_COMMUNITY_Community 43|Community 43]]
- [[_COMMUNITY_Community 44|Community 44]]
- [[_COMMUNITY_Community 45|Community 45]]
- [[_COMMUNITY_Community 46|Community 46]]
- [[_COMMUNITY_Community 47|Community 47]]
- [[_COMMUNITY_Community 48|Community 48]]
- [[_COMMUNITY_Community 49|Community 49]]
- [[_COMMUNITY_Community 50|Community 50]]
- [[_COMMUNITY_Community 51|Community 51]]
- [[_COMMUNITY_Community 52|Community 52]]
- [[_COMMUNITY_Community 53|Community 53]]
- [[_COMMUNITY_Community 54|Community 54]]
- [[_COMMUNITY_Community 55|Community 55]]
- [[_COMMUNITY_Community 56|Community 56]]
- [[_COMMUNITY_Community 57|Community 57]]
- [[_COMMUNITY_Community 58|Community 58]]
- [[_COMMUNITY_Community 59|Community 59]]
- [[_COMMUNITY_Community 60|Community 60]]
- [[_COMMUNITY_Community 61|Community 61]]
- [[_COMMUNITY_Community 62|Community 62]]
- [[_COMMUNITY_Community 63|Community 63]]
- [[_COMMUNITY_Community 64|Community 64]]
- [[_COMMUNITY_Community 65|Community 65]]
- [[_COMMUNITY_Community 66|Community 66]]
- [[_COMMUNITY_Community 67|Community 67]]
- [[_COMMUNITY_Community 68|Community 68]]
- [[_COMMUNITY_Community 69|Community 69]]
- [[_COMMUNITY_Community 70|Community 70]]
- [[_COMMUNITY_Community 72|Community 72]]
- [[_COMMUNITY_Community 73|Community 73]]
- [[_COMMUNITY_Community 74|Community 74]]
- [[_COMMUNITY_Community 75|Community 75]]
- [[_COMMUNITY_Community 76|Community 76]]
- [[_COMMUNITY_Community 77|Community 77]]
- [[_COMMUNITY_Community 78|Community 78]]
- [[_COMMUNITY_Community 123|Community 123]]

## God Nodes (most connected - your core abstractions)
1. `Session` - 59 edges
2. `BaseController` - 50 edges
3. `ReportController` - 20 edges
4. `QuestionController` - 17 edges
5. `TestController` - 16 edges
6. `UserController` - 16 edges
7. `ExamController` - 15 edges
8. `ExamApiController` - 14 edges
9. `SubjectController` - 12 edges
10. `SuspendController` - 12 edges

## Surprising Connections (you probably didn't know these)
- `PHPUnit Setup and Execution` --semantically_similar_to--> `CodeIgniter 4 Setup`  [INFERRED] [semantically similar]
  /home/rozen/Documents/Sistem-Ujian/src/tests/README.md → /home/rozen/Documents/Sistem-Ujian/src/README.md
- `App Directory Access Prevention` --semantically_similar_to--> `Tests Directory Access Prevention`  [INFERRED] [semantically similar]
  /home/rozen/Documents/Sistem-Ujian/src/app/index.html → /home/rozen/Documents/Sistem-Ujian/src/tests/index.html
- `AnalyticsController` --inherits--> `BaseController`  [EXTRACTED]
  app/Controllers/Admin/AnalyticsController.php → app/Controllers/BaseController.php
- `GroupController` --inherits--> `BaseController`  [EXTRACTED]
  app/Controllers/Admin/GroupController.php → app/Controllers/BaseController.php
- `ModuleController` --inherits--> `BaseController`  [EXTRACTED]
  app/Controllers/Admin/ModuleController.php → app/Controllers/BaseController.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **PHPUnit Testing Framework in CodeIgniter 4** — tests_readme_phpunit_setup, tests_readme_test_database, tests_readme_code_coverage, tests_readme_ciunittestcase [EXTRACTED 1.00]

## Communities (145 total, 57 thin omitted)

### Community 0 - "Module & User Administration"
Cohesion: 0.06
Nodes (16): ModuleController, UserController, ExamApiController, ActivityLogModel, ModuleModel, ActivityLogModel, GroupModel, UserModel (+8 more)

### Community 1 - "Application Configuration"
Cohesion: 0.06
Nodes (24): BaseConfig, App, Cache, ContentSecurityPolicy, Cookie, Cors, CURLRequest, Email (+16 more)

### Community 2 - "Security Filters & Middleware"
Cohesion: 0.09
Nodes (19): RequestInterface, ResponseInterface, RequestInterface, ResponseInterface, RequestInterface, ResponseInterface, RequestInterface, ResponseInterface (+11 more)

### Community 3 - "Composer Dependencies & Autoload"
Cohesion: 0.06
Nodes (32): autoload, autoload-dev, psr-4, exclude-from-classmap, psr-4, config, optimize-autoloader, preferred-install (+24 more)

### Community 4 - "Excel Report Generation"
Cohesion: 0.31
Nodes (3): ReportController, Spreadsheet, Worksheet

### Community 5 - "Exam Monitoring & Suspends"
Cohesion: 0.15
Nodes (4): SuspendController, TestAttemptModel, UserModel, ScoringEngine

### Community 6 - "Question Bank Management"
Cohesion: 0.18
Nodes (6): QuestionController, ActivityLogModel, AnswerModel, ModuleModel, QuestionModel, SubjectModel

### Community 7 - "Testing Infrastructure"
Cohesion: 0.15
Nodes (7): App, CIUnitTestCase, ExampleDatabaseTest, DatabaseTestTrait, ConfigReader, ExampleSessionTest, HealthTest

### Community 8 - "Exam Core Services"
Cohesion: 0.18
Nodes (10): ActivityLogModel, AnswerModel, QuestionModel, TestAttemptModel, TestLogModel, TestModel, Config, Database (+2 more)

### Community 9 - "Database Models Layer"
Cohesion: 0.17
Nodes (8): Model, ExampleModel, SubjectModel, TestLogAnswerModel, TestLogModel, TestModel, TestSubjectModel, TestSubjectSetModel

### Community 10 - "Test Settings & Scheduling"
Cohesion: 0.17
Nodes (3): TestController, ActivityLogModel, TestModel

### Community 11 - "Core Entry Controllers"
Cohesion: 0.21
Nodes (5): DashboardController, SyncController, BaseController, ExamController, DashboardController

### Community 12 - "Subject CRUD Logic"
Cohesion: 0.21
Nodes (4): SubjectController, ActivityLogModel, ModuleModel, SubjectModel

### Community 14 - "Group CRUD Logic"
Cohesion: 0.22
Nodes (3): GroupController, ActivityLogModel, GroupModel

### Community 15 - "Community 15"
Cohesion: 0.27
Nodes (4): ResultController, TestAttemptModel, TestLogModel, TestModel

### Community 17 - "Community 17"
Cohesion: 0.27
Nodes (3): ActivityLogModel, UserModel, AuthController

### Community 19 - "Community 19"
Cohesion: 0.28
Nodes (3): Migration, CreateTestSubjectSetsTable, AddStaticPageToTests

### Community 20 - "Community 20"
Cohesion: 0.43
Nodes (3): TestAttemptModel, TestModel, ResultController

### Community 23 - "Community 23"
Cohesion: 0.29
Nodes (7): Robots TXT Crawler Configuration, CodeIgniter 4 Setup, Public Folder index.php Security, CIUnitTestCase Base Test Class, Code Coverage Generation, PHPUnit Setup and Execution, Test Database Configuration

### Community 24 - "Community 24"
Cohesion: 0.38
Nodes (3): Seeder, ExampleSeeder, InitialSeeder

### Community 26 - "Community 26"
Cohesion: 0.47
Nodes (4): RequestInterface, ResponseInterface, Controller, LoggerInterface

### Community 27 - "Community 27"
Cohesion: 0.53
Nodes (4): getFirstChildWithTagName(), getHash(), init(), showTab()

## Knowledge Gaps
- **36 isolated node(s):** `DocTypes`, `Hostnames`, `Kint`, `Optimize`, `Paths` (+31 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **57 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `BaseController` connect `Core Entry Controllers` to `Module & User Administration`, `Community 66`, `Excel Report Generation`, `Exam Monitoring & Suspends`, `Question Bank Management`, `Test Settings & Scheduling`, `Subject CRUD Logic`, `Group CRUD Logic`, `Community 15`, `Community 16`, `Community 17`, `Community 18`, `Community 20`, `Community 21`, `Community 25`, `Community 26`, `Community 30`, `Community 57`?**
  _High betweenness centrality (0.138) - this node is a cross-community bridge._
- **Why does `Session` connect `Module & User Administration` to `Application Configuration`, `Community 66`, `Security Filters & Middleware`, `Question Bank Management`, `Test Settings & Scheduling`, `Core Entry Controllers`, `Subject CRUD Logic`, `Group CRUD Logic`, `Community 16`, `Community 17`, `Community 18`, `Community 20`, `Community 57`, `Community 30`?**
  _High betweenness centrality (0.123) - this node is a cross-community bridge._
- **Why does `ReportController` connect `Excel Report Generation` to `Core Entry Controllers`?**
  _High betweenness centrality (0.024) - this node is a cross-community bridge._
- **Are the 57 inferred relationships involving `Session` (e.g. with `.delete()` and `.store()`) actually correct?**
  _`Session` has 57 INFERRED edges - model-reasoned connections that need verification._
- **What connects `DocTypes`, `Hostnames`, `Kint` to the rest of the system?**
  _36 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Module & User Administration` be split into smaller, more focused modules?**
  _Cohesion score 0.05889724310776942 - nodes in this community are weakly interconnected._
- **Should `Application Configuration` be split into smaller, more focused modules?**
  _Cohesion score 0.06037414965986394 - nodes in this community are weakly interconnected._
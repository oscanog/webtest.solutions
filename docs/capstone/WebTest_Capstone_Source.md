:::title WebTest: Integrated Web and Mobile Issue, Checklist, and AI-Assisted QA Management System
:::subtitle BSIT Capstone Project Manuscript
:::center [School Name]
:::center Bachelor of Science in Information Technology
:::center [Department / College]
:::center
:::center Prepared by:
:::center [Proponent 1]
:::center [Proponent 2]
:::center [Proponent 3]
:::center [Proponent 4]
:::center [Proponent 5]
:::center
:::center Adviser: [Adviser Name]
:::center School Year 2025-2026
:::pagebreak

# Table of Contents
:::toc
:::pagebreak

# Chapter 1. Introduction

## 1.1 Project Background

WebTest is an integrated quality-assurance and issue-management platform composed of a PHP and MySQL backend and a separate React and Vite mobile web client. The backend repository provides user authentication, organization and member management, project scoping, issue lifecycle control, checklist management, evidence attachments, AI administration, AI-assisted checklist drafting, and in-app notifications. The mobile web repository provides a mobile-first operational interface for daily use of the same platform.

The capstone project answers a practical software-development problem: many student teams and small development groups track defects, checklist work, and review approvals across disconnected channels. WebTest addresses that problem by centralizing issue intake, project-level checklist work, role-based workflow routing, attachment evidence, and a supporting AI workflow that can help draft checklist items for QA review.

The implemented system is suitable for a BSIT capstone because it is a full-stack application system, uses a relational database, exposes API endpoints, includes a dedicated mobile client, applies role-based access control, and demonstrates real software-engineering concerns such as deployment, testing, runtime configuration, data validation, file handling, and system integration.

## 1.2 General Objective

The general objective of the project is to design, implement, and document an integrated issue, checklist, notification, and AI-supported QA management system that can be used through a web backend and a mobile-first client.

## 1.3 Specific Objectives

- To centralize authentication, organization membership, and project scoping in one backend platform.
- To manage issues through a role-aware workflow that supports project managers, developers, QA testers, senior QA staff, and QA leads.
- To manage checklist batches and checklist items at organization and project level.
- To support attachment evidence for issues, checklist items, checklist batches, and AI chat messages.
- To deliver a mobile-first client that consumes the live backend API for daily operational use.
- To provide in-app notifications with realtime delivery support.
- To provide an AI administration and AI chat subsystem that assists checklist drafting without replacing the core manual QA process.

## 1.4 Scope and Delimitation

The implemented system covers two code repositories that function as one integrated platform.

- The backend repository handles authentication, business rules, database operations, uploads, API routing, AI configuration, OpenClaw integration aliases, and notification delivery.
- The mobile web repository provides the login, dashboard, organizations, projects, reports, checklist, AI chat, notifications, AI admin, manage users, profile, and settings screens.
- The capstone manuscript focuses on system development and therefore gives more weight to architecture, database design, implementation, testing, and deployment than to general theory.
- The AI and OpenClaw subsystem is included as a supporting module, not as the sole core subject of the thesis.

# Chapter 2. Related Technical Context

## 2.1 System Type

The project is an application-system capstone under BSIT because it delivers a complete operational software solution rather than a conceptual prototype only. It includes data storage, user roles, business workflows, attachments, notification delivery, and a mobile operating surface.

## 2.2 Technologies Used

The current implementation uses the following technology stack as verified from the repositories:

- Backend language and runtime: PHP with MySQL and procedural module libraries.
- Frontend language and runtime: React 19, React Router 7, TypeScript, and Vite.
- Database: MySQL schema files and migrations maintained under the backend repository.
- Testing: Playwright-based end-to-end suites for API, authentication, checklist flows, and mobile web behavior.
- Deployment: Google Compute Engine deployment guides for backend releases and mobile web releases.
- Realtime delivery: a Node-based websocket notification service under `services/realtime-notifications`.

## 2.3 Development Context

The repository history and documentation show an incremental and integration-oriented development approach. The backend repository contains versioned SQL migrations, compatibility aliases, release scripts, deployment runbooks, and modular libraries. The mobile web repository consumes the live backend API and preserves route and naming parity with the backend. This reflects an iterative, Agile-inspired development method where the team adds features in increments, validates them through end-to-end tests, and deploys them through repeatable release guides.

# Chapter 3. System Design and Development Framework

## 3.1 Development Methodology

The project follows an iterative and incremental system-development approach. Instead of one large monolithic build, the repositories show feature-by-feature growth backed by migrations, module-level libraries, and end-to-end validation. This methodology is appropriate for the project because the system combines several concerns: authentication, multi-organization membership, issue workflow, checklist operations, file attachments, mobile interaction, notifications, and AI-assisted checklist drafting.

The observed development cycle is:

- identify a workflow requirement such as project scoping, AI runtime split, or attachment handling;
- implement schema updates in SQL bootstrap files and migrations;
- implement or update backend modules and API routes;
- connect or adjust the mobile web screens and API clients;
- verify behavior through end-to-end tests and deployment runbooks.

## 3.2 System Overview

WebTest is organized as one integrated platform with three major runtime parts:

- a PHP and MySQL backend that stores data, validates requests, enforces business rules, and serves API endpoints;
- a React and Vite mobile web client that consumes the backend API for operational use;
- a websocket-based notification service that pushes notification updates to authenticated clients.

The backend repository contains both legacy PHP surfaces and API v1 endpoints. The API v1 router currently includes authentication, organizations, projects, issues, dashboard, notifications, realtime socket token generation, AI admin, AI chat, checklist, and compatibility alias endpoints. This provides a stable service layer for the mobile web client while still supporting selected legacy paths during transition.

## 3.3 User Roles and Access Model

The platform uses both system-level and organization-level roles.

- System roles are stored in the `users` table and include `super_admin`, `admin`, and `user`.
- Organization-specific roles are stored in `org_members` and include `owner`, `member`, `Project Manager`, `QA Lead`, `Senior Developer`, `Senior QA`, `Junior Developer`, and `QA Tester`.

This separation is important in the design because some screens and operations depend on organization role while others depend on system-wide privileges. For example, AI chat access is restricted to system administrators and QA leads, while route guards in the mobile web client also consider active organization selection and route visibility.

## 3.4 Architectural Design

The architectural design is a service-oriented full-stack web application.

- Presentation layer: the mobile web client provides the user interface, route guards, stateful authentication, notification state management, and feature-based API clients.
- Application layer: PHP route handlers and domain libraries implement validation, authorization, transactions, workflow rules, attachment logic, AI runtime configuration, and response shaping.
- Data layer: MySQL stores user, organization, project, issue, checklist, attachment, AI runtime, AI chat, OpenClaw request, notification, and contact data.
- Support service layer: the realtime notification service issues websocket messages for created and updated notifications.

The design uses API-first integration between backend and mobile web. This is evident in the mobile web feature modules such as dashboard, organizations, projects, issues, checklist, AI chat, AI admin, account, and notifications, all of which call backend API endpoints instead of hardcoding local-only data.

## 3.5 Major Functional Modules

### 3.5.1 Authentication and Session Management

The backend API exposes login, signup, refresh, logout, profile update, password change, and forgot-password OTP routes. The presence of `password_reset_requests` in the database and the dedicated authentication tests confirms that recovery is part of the implemented design instead of being only planned.

The mobile web client provides `/login`, `/signup`, `/forgot-password`, `/forgot-password/verify`, and `/forgot-password/success`. Route guards ensure that anonymous users are redirected to login and authenticated users are redirected to the correct app path.

### 3.5.2 Organization and Membership Management

The organization module allows creation of organizations, membership joins and leaves, role changes, owner transfer, and organization deletion. The mobile client also supports organization switching and an all-organizations scope in supported contexts. This module gives the system its multi-tenant behavior because projects, issues, checklist batches, checklist items, AI chat threads, and notifications are all scoped through organizations.

### 3.5.3 Project Management

Projects are organization-scoped and support creation, update, archive, and activation behavior. Current code and migration history show that projects are not only a descriptive entity but a core scoping mechanism for issues, checklist batches, checklist items, OpenClaw requests, and notifications.

### 3.5.4 Issue Workflow Management

The issue module is one of the main operational cores of the system. The backend supports issue listing, creation, detail retrieval, deletion, evidence uploads, and multiple role-based transitions such as assign developer, assign junior, mark junior done, assign QA, report to senior QA, report to QA lead, approve, reject, and project-manager close.

The `issues` table stores workflow status and multiple assignee fields, which means the workflow is modeled directly in the main issue record. The mobile web client exposes reports and report-detail views that align with this workflow and allow role-aware issue handling.

### 3.5.5 Checklist Management

Checklist management is the second major operational core of the system. A checklist batch belongs to an organization and project and contains many checklist items. Both item-level and batch-level attachment uploads are implemented. Checklist items also support status updates and can link to an issue record when a failure or blocker needs issue-level tracking.

The mobile web client exposes checklist list pages, batch detail pages, and item detail pages. These screens use the backend checklist API for live loading and update actions.

### 3.5.6 Notifications and Realtime Delivery

The system stores in-app notifications in the `notifications` table and supports notification listing, mark-read, and mark-all-read actions. The backend also exposes a realtime socket-token endpoint, and the separate notification service provides websocket delivery. On the client side, the notifications feature builds websocket URLs, parses realtime events, and merges notification updates into local state.

This design improves responsiveness because high-priority workflow updates do not depend only on page refresh.

### 3.5.7 AI Admin and AI Chat

The project includes a built-in AI administration surface and an in-app AI checklist drafting flow. The AI admin module configures providers, models, default runtime values, assistant name, and system prompt. The AI chat module creates threads and messages, supports attachments, stores chat messages, and can produce generated checklist drafts for human review.

The AI subsystem remains supportive rather than fully autonomous. Generated checklist items still pass through review actions such as approve and reject. This preserves human control in the QA workflow and keeps the AI module aligned with the capstone's system-development focus.

### 3.5.8 OpenClaw Compatibility and Integration

The backend retains OpenClaw-related request and runtime tables plus compatibility alias routes. These tables and aliases preserve integration with checklist duplicate checks, checklist batch ingestion, runtime configuration, and provider/model compatibility during the transition toward AI Admin. This is important to document because the current system is not a greenfield build; it is an evolving integrated platform with backward-compatible components.

## 3.6 API and Data-Flow Design

The backend route registry shows a clear API-driven architecture. Core endpoint groups are:

- authentication endpoints for account access and recovery;
- organization endpoints for membership and tenant context;
- project endpoints for scoped planning and activation;
- issue endpoints for issue lifecycle actions;
- checklist endpoints for batches, items, status changes, and attachments;
- notification endpoints for in-app alerts and socket tokens;
- AI admin endpoints for runtime, providers, and models;
- AI chat endpoints for threads, messages, draft contexts, generated checklist items, and streaming;
- compatibility endpoints for legacy checklist and OpenClaw integrations.

The general runtime data flow is:

- the mobile client authenticates and stores access context;
- the client requests data using bearer-authenticated API calls;
- the backend validates user role, organization scope, and payload structure;
- database transactions update rows and related entities;
- the backend returns JSON payloads for client rendering;
- notification and websocket layers propagate relevant updates to clients.

## 3.7 Database Design

The database design is relational and centered on traceability between actors, projects, issues, checklist records, AI records, and notifications. The consolidated current model draws from the backend bootstrap SQL, schema file, migration set, and AI chat schema helper. This is necessary because the current implementation evolved across bootstrap snapshots and migrations.

The full ERD included in this capstone package covers the following logical areas:

- core identity and tenant records;
- project and issue workflow records;
- checklist management records;
- AI runtime and provider records;
- OpenClaw request and control-plane records;
- AI chat records;
- notification records;
- contact records.

The ERD also documents the current issue-to-project relationship used by the running code, and it separates the runtime-created `ai_chat_generated_checklist_items` table as an extension note rather than a bootstrap table.

## 3.8 Security, Validation, and File Handling Design

The project applies several practical security and integrity controls:

- role-aware access checks on backend endpoints;
- organization scoping for multi-tenant data access;
- password reset OTP flow in a dedicated request table;
- encrypted storage of AI provider API keys;
- internal bearer protection for retained OpenClaw internal endpoints;
- attachment handling through explicit storage metadata fields;
- socket-token generation for realtime notification connections.

These controls are not only conceptual. They are visible in the backend configuration and library files, in the schema, and in the route groups used by the mobile client.

## 3.9 Summary of Chapter 3

Chapter 3 presented the development methodology, architecture, modules, API structure, database design, and security design of WebTest. The chapter established that the project is a complete integrated application system with a backend service layer, a mobile-first client, a relational database, a realtime notification service, and a supporting AI-assisted QA subsystem.

# Chapter 4. System Development, Implementation, and Testing

## 4.1 Development Environment

The backend repository is organized around PHP modules, SQL schema and migration files, deployment documentation, and end-to-end tests. The mobile web repository is organized around React feature modules, route definitions, shared UI components, and client-side feature APIs.

The project uses a realistic software-development environment:

- local SQL bootstrap files for development data setup;
- versioned migrations for incremental schema change;
- feature-oriented frontend source structure;
- deployment guides for backend and mobile web release flows;
- Playwright end-to-end tests for verification of actual user and API behavior.

## 4.2 Backend Implementation

### 4.2.1 Authentication Implementation

The backend implements login, signup, refresh, logout, active organization switching, profile update, password change, and OTP-based forgot-password operations. Authentication behavior is covered by API and UI test suites, which confirms that the implementation is functional in both service and presentation layers.

### 4.2.2 Organization and Project Implementation

Organizations are implemented as tenant containers with members and roles. Projects extend that organization model by providing a finer scope for issues, checklist batches, checklist items, OpenClaw requests, and notifications. The presence of dedicated organization and project API handlers, together with tests for organization lifecycle and project CRUD plus archive and activate behavior, demonstrates that these features are fully implemented.

### 4.2.3 Issue Workflow Implementation

Issue handling is implemented through backend workflow endpoints and mobile report screens. The issue workflow includes authoring, assignment, transition control, approval, rejection, closure, and evidence upload. The test suite validates multipart issue evidence uploads and multiple workflow transitions, including approve-to-close and reject-to-reassign scenarios.

### 4.2.4 Checklist Implementation

Checklist implementation is composed of batch management, item management, item status changes, item attachments, batch attachments, and linkages to issue records. The backend includes direct checklist APIs and compatibility alias routes, while the mobile client includes checklist list, batch detail, and item detail flows.

The checklist-specific tests verify creation, listing, retrieval, update, status transitions, upload and delete behavior for item attachments and batch attachments, authorization checks, and cleanup after delete operations.

### 4.2.5 AI Admin and AI Chat Implementation

The AI admin implementation stores provider records, model records, and runtime settings. The mobile client exposes a dedicated AI Admin page where administrators can save built-in AI settings, providers, and models.

The AI chat implementation supports:

- bootstrap checks for runtime readiness;
- thread creation and retrieval;
- draft-context updates;
- multipart attachment submission;
- checklist-draft generation;
- generated-item approval and rejection;
- streaming message flow.

This makes the AI module an implemented, user-facing part of the system rather than a placeholder. At the same time, it remains governed by explicit review actions and role restrictions.

### 4.2.6 Realtime Notification Implementation

Realtime notification support is implemented through stored notification rows, read-state endpoints, socket-token generation, a websocket service, and client-side realtime parsing and upsert behavior. Mobile web tests confirm both realtime delivery and read-state synchronization across sessions.

## 4.3 Mobile Web Implementation

The mobile web client is a dedicated operational interface rather than a simple responsive mirror of the backend pages. Route definitions confirm support for the following major screens:

- public entry and authentication pages;
- dashboard;
- organizations;
- projects and project detail;
- reports and report detail;
- profile;
- notifications;
- super admin;
- AI admin;
- manage users;
- checklist pages and checklist item detail;
- AI chat;
- settings.

The application uses guarded routes, session-aware redirects, active-organization checks, and route visibility checks. This strengthens the overall usability of the system because users see only the workflows appropriate to their current access context.

## 4.4 Integrated Workflows

### 4.4.1 Issue Lifecycle Workflow

The implemented issue lifecycle begins with issue creation inside an organization and project scope. The issue is then routed through developer and QA states until final approval, rejection, or closure. Evidence attachments allow each issue to retain supporting files inside the same workflow.

### 4.4.2 Checklist Workflow

A checklist batch is created for a selected project. The batch contains checklist items that can be assigned, updated, and given statuses such as open, in progress, passed, failed, or blocked. Item and batch attachments preserve evidence. Failed or blocked items can also lead to linked issue records, allowing the system to bridge checklist execution and issue resolution.

### 4.4.3 AI-Assisted Checklist Workflow

The AI-assisted checklist workflow begins in AI chat, where a user with valid permissions selects a project and draft context, submits a prompt, and optionally uploads attachments. The backend generates draft checklist items, compares them against existing checklist content for duplicates, and stores them for explicit human approval or rejection. Approved items are promoted into the actual checklist domain.

This workflow demonstrates a practical and controlled application of AI in system development. AI assists drafting, but final checklist acceptance stays under human supervision.

### 4.4.4 Notification Workflow

Notifications are created in response to events and become visible in the mobile client. Realtime websocket delivery reduces delay, while the API still supports polling-friendly list and read-state operations. This dual-path design improves reliability because the system remains usable even when websocket connectivity is degraded.

## 4.5 Testing Strategy

The project applies end-to-end testing to validate actual user-visible and API-visible behavior. This approach is appropriate because the system has several integration boundaries: browser to API, API to database, and notification service to browser client.

The observed testing coverage includes:

- API health and root reachability;
- authentication lifecycle and guards;
- organization lifecycle;
- project CRUD and status changes;
- issue workflow transitions and evidence uploads;
- checklist CRUD, status updates, and attachment flows;
- AI admin behavior and retained OpenClaw alias limits;
- notification listing, deep links, mark-read, and mark-all-read;
- mobile login and route guarding;
- dashboard behavior including QA lead workload summaries;
- realtime notification delivery and read-state synchronization;
- datetime rendering in the mobile web client.

## 4.6 Representative Test Evidence

The following repository tests directly support the implemented claims in this manuscript:

- `e2e-tests/api-v1/tests/auth.spec.ts` for authentication service behavior.
- `e2e-tests/api-v1/tests/orgs.spec.ts` for organization lifecycle behavior.
- `e2e-tests/api-v1/tests/projects.spec.ts` for project CRUD and status changes.
- `e2e-tests/api-v1/tests/issues-workflow.spec.ts` for issue workflow, evidence upload, and dashboard summaries.
- `e2e-tests/api-v1/tests/notifications.spec.ts` for notification actions and deep links.
- `e2e-tests/api-v1/tests/admin-ai.spec.ts` for AI admin APIs and remaining OpenClaw aliases.
- `e2e-tests/checklist/tests/checklist-crud.spec.ts` for checklist CRUD and attachment operations.
- `e2e-tests/webtest-mobileweb/tests/notifications.realtime.spec.js` for realtime delivery and sync.
- `e2e-tests/webtest-mobileweb/tests/checklist-item-status.spec.js` for checklist-item updates and linked issue access.
- `e2e-tests/webtest-mobileweb/tests/dashboard-qa-lead.spec.js` for project-based checklist workload summaries.

## 4.7 Deployment and Integration Readiness

The backend and mobile web repositories both contain dedicated deployment guides for Google Compute Engine. The backend uses a release-based deployment flow and documents migration handling, attachment verification, AI Admin verification, and optional notification-service restart steps. The mobile web repository has its own deployment script and release order guidance relative to backend API changes.

This level of deployment documentation strengthens the implementation because it shows that the project is not limited to local execution. It also demonstrates operational planning, rollback awareness, and environment-specific release procedure.

## 4.8 Observed Limitations During Implementation

The current implementation still shows normal real-world evolution points:

- schema truth is distributed across bootstrap SQL, schema files, migrations, and runtime schema helpers;
- selected legacy alias endpoints remain in place for compatibility;
- the AI chat generated-checklist table is created dynamically and therefore needs explicit documentation when presenting the complete system model;
- the thesis must distinguish between bootstrap tables and runtime extension tables to avoid confusion during defense.

These limitations do not invalidate the project. Instead, they show a living software system under active refinement.

## 4.9 Summary of Chapter 4

Chapter 4 showed how WebTest was implemented across backend, mobile web, notification, and AI-support layers. It also showed that the project is supported by repository-backed end-to-end tests and operational deployment procedures, which increases the credibility of the implemented capstone output.

# Chapter 5. Summary, Conclusions, and Recommendations

## 5.1 Summary of Findings

The project successfully produced an integrated full-stack application that combines issue management, project scoping, checklist execution, attachment handling, notifications, and AI-assisted checklist drafting within one operational platform. The backend and mobile web repositories work together as a single system rather than as disconnected prototypes.

The repositories confirm that the project includes:

- a role-aware backend API;
- a mobile-first operational client;
- a relational database with organization, project, issue, checklist, AI, and notification records;
- realtime notification support;
- repeatable deployment documentation;
- end-to-end verification assets.

## 5.2 Conclusions

Based on the implemented repositories, WebTest meets the profile of a BSIT capstone application system. It is not limited to isolated features; instead, it demonstrates coordinated system analysis, database design, API development, mobile client integration, file handling, runtime configuration, testing, and deployment planning.

The project also demonstrates that AI can be integrated into a practical QA workflow without removing human control. By keeping generated checklist items subject to review and approval, the system applies AI as an assistive mechanism rather than an ungoverned replacement for testers and leads.

From a software-engineering standpoint, the project is successful because it shows:

- modular backend organization;
- traceable database relationships;
- practical frontend integration;
- operational notification flow;
- measurable testing coverage for important user and API paths.

## 5.3 Recommendations

The following recommendations are grounded in the current codebase and its visible evolution:

- consolidate schema truth further so bootstrap SQL, schema snapshots, and runtime-created tables remain easier to reconcile;
- continue reducing transitional alias endpoints once all clients rely on the stable API v1 paths;
- add a formal architecture diagram and sequence diagrams for selected workflows to strengthen future manuscript revisions;
- extend testing further around AI chat edge cases and failure handling;
- improve thesis appendix materials by including selected API samples, role matrices, and deployment checklists when required by the adviser;
- consider adding analytics and audit-history records for issue and checklist transitions in future iterations.

## 5.4 Final Statement

WebTest demonstrates that a student development team can build an integrated and operational quality-assurance platform that combines traditional CRUD features, workflow management, mobile access, realtime communication, and supporting AI capabilities in one deployable BSIT capstone project.

# Appendix A. Full ERD

The ERD included with this capstone package is a consolidated current-system diagram. It combines the core bootstrap tables from local development SQL, project-scoped issue relationships used by the current backend schema and migrations, and supporting notes about runtime-created AI chat extension tables.

:::image WebTest_Full_ERD.svg|Figure A-1. Consolidated WebTest full ERD|7.0

# Appendix B. Schema Reconciliation Notes

The current backend implementation evolved across multiple schema sources. For a correct defense narrative, the following statements should be remembered:

- `local_dev_full.sql` contains the broad bootstrap model used for local setup and seeded development data.
- `infra/database/schema.sql` and migrations reflect newer structural alignment, including the project-scoped issue relationship used by current backend code.
- `api/v1/lib/ai_chat.php` creates `ai_chat_generated_checklist_items` dynamically and therefore this table is treated as a runtime extension in the documentation.
- The ERD in this package is therefore a consolidated implementation diagram rather than a raw export from a single SQL file.

# Appendix C. Primary Repository References

The capstone manuscript was grounded on the following implementation sources:

- backend route registry under `api/v1/lib/routes.php`;
- backend issue, checklist, AI chat, AI admin, organization, project, notification, and OpenClaw libraries;
- backend deployment guides under `docs/deployment/`;
- schema references under `local_dev_full.sql`, `infra/database/schema.sql`, and `infra/database/migrations/`;
- mobile web route definitions under `src/App.tsx`;
- mobile web feature API modules for checklist, AI chat, notifications, dashboard, organizations, projects, and account behavior;
- Playwright-based test suites under `e2e-tests/`.

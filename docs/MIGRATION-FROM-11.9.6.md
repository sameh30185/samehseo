# SAMEH 11.9.6 → 12.0 Migration Inventory

Baseline: `2.0.0-alpha.11.9.6-factory-polish-rollback` (WordPress plugin, ~66 include classes)

## MOVE TO SAMEH CENTRAL CORE
- Project Truth / Business Brain: `class-ssos-project-truth.php`, `class-ssos-business-brain.php`, `class-ssos-project-discovery.php`
- Context / Evidence: `class-ssos-context-engine.php`
- AI Company / Workforce: `class-ssos-workforce-registry.php`, `class-ssos-ai-employee.php`, `class-ssos-employee-runtime.php`, `class-ssos-delegation-manager.php`, `class-ssos-company-orchestrator.php`
- Director / Mission: `class-ssos-director-runtime.php`, `class-ssos-mission-engine.php`, `class-ssos-mission-repository.php`, `class-ssos-mission-execution-router.php`, `class-ssos-live-command.php`
- Model Router / Local AI: `class-ssos-model-router.php`, `class-ssos-local-ai.php`
- Quality / QA / Policy: `class-ssos-quality-gate.php`, `class-ssos-qa.php`, `class-ssos-policy-engine.php`, `class-ssos-capability-registry.php`
- Decision / Contracts / Preview: `class-ssos-action-contract.php`, `class-ssos-preview-builder.php`, `class-ssos-runtime-contract.php`, `class-ssos-architecture.php`
- Execution orchestration: `class-ssos-execution-manager.php`, `class-ssos-executor-registry.php`
- Verification / Rollback (coordination): `class-ssos-verification-engine.php`, `class-ssos-rollback-manager.php`, `class-ssos-history-manager.php`
- Growth / Intelligence: `class-ssos-growth-intelligence.php`, `class-ssos-intelligence-center.php`, `class-ssos-opportunities.php`, `class-ssos-serp-intelligence.php`, `class-ssos-competitor-crawler.php`, `class-ssos-search-console.php`, `class-ssos-google-ads*.php` (read-only ads)
- Page Factory / Production: `class-ssos-production-factory.php`, `class-ssos-production-engine.php`, `class-ssos-generator.php`, `class-ssos-generate-center.php`, `class-ssos-template-engine.php`, `class-ssos-factory-batch-gate.php`, `class-ssos-factory-batch-content-polish.php`, `class-ssos-areas-hub.php`
- Content intelligence: `class-ssos-content-engine.php`, `class-ssos-content-integrity-engine.php`, `class-ssos-content-repair-engine.php`, `class-ssos-content-scope.php`, `class-ssos-published-review.php`, `class-ssos-scanner.php`
- Admin UX concepts (not wp-admin coupling): live mission progress, approvals, safe mode defaults

## MOVE TO THIN WORDPRESS CONNECTOR
- DB/settings bootstrap → connector config only: slim `class-ssos-db.php` / settings subset
- Rank Math: `class-ssos-rank-math-schema.php`
- Read/Write executors against WP: `class-ssos-read-executor.php`, `class-ssos-write-executor.php`, `class-ssos-content-cleanup-executor.php`
- Sitemap/scanner hooks that need WP APIs: `class-ssos-sitemap.php` (server-side fetch can stay Core)
- WPBakery parse/serialize (extract from content engines)
- Snapshots, revisions, verify rendered page, health check
- Existing `class-ssos-connector-manager.php` patterns for local engine pairing → redesign as signed SAMEH↔Connector bridge

## REDESIGN (do not port as-is)
- `class-ssos-admin.php`, `class-ssos-frontend.php`, `class-ssos-notices.php` — wp-admin UI
- Plugin lifecycle / `class-ssos-lifecycle-hooks.php`
- Tight ABSPATH / `$wpdb` coupling inside Company/Factory — move logic to Core, leave IO in Connector
- Local Windows Engine assumptions — Model Provider adapters instead

## PRESERVE BEHAVIOR
- Safe pipeline: Evidence → Workforce → QA → Director → Typed Action → Preview → Approval → Execute → Verify → Rollback
- Default deny / READ ONLY / SAFE MODE
- Typed operation keys (e.g. `factory_batch_content_polish`)
- Factory gate FACTORY_READY / WARNINGS / BLOCKED
- Hermes optional Independent QA with timeout + JSON + fallback
- Scope Lock / workspace isolation (elevate to multi-site)
- No fake data: NOT CONNECTED / NOT IMPLEMENTED

## FIRST E2E MVP PATH
Owner+2FA → Add Site → Pair Connector → Discover WP → Inventory → Read-only mission → Typed action → Preview → Approve → Execute one safe meta/content change on staging → Verify → Rollback → Audit

---

## 12.0 foundation mapping (this repo)

| 11.9.6 concept | 12.0 location |
|----------------|---------------|
| Connector manager / signed bridge | `apps/connector` + future pairing API |
| Action contracts / missions | tables `missions`, `action_contracts` (minimal schema only) |
| Snapshots / rollback coordination | table `snapshots` (minimal) |
| Kill / safe mode | `kill_switches` + `/security/kill-switch` |
| Audit | `audit_events` + `GET /audit` |
| WP admin UI | **dropped** — replaced by `apps/web` |
| Factory / workforce / intelligence | **NOT IMPLEMENTED** — Core later |

Do not port PHP classes wholesale. Extract contracts and behavior into Go services.

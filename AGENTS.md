# Datawell

This repository is a Laravel package: declarative data sources — define each unit of data once (query, fields, filters, sorts, actions, parameters, permissions) and every consumer (tables, AI agents, reports, exports) renders from that single contract. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Project Documents

The design pack is the project's memory. It lives in the parent workspace at `../docs/` (outside this repository, alongside sibling packages) — read it before changing behavior, and keep it in sync in the same commit as the code it describes.

- `../docs/datasource-decision-log.md` — **binding.** Numbered decisions (D01+) with rationale. Never silently re-litigate one; if implementation proves a decision wrong, stop, explain the conflict, and propose an amendment as a new numbered entry. D45 (action-run persistence) is proposed but not approved — do not implement it without the owner's sign-off.
- `../docs/datasource-package-design.md` — the architecture: concepts, query grammar, permissions, timezone model, relations, executor pipeline, consumers, phased build plan, open questions.
- `../docs/datasource-examples.md` — one source walked through every feature. PHP snippets are illustrative shape, not final API; the JSON wire formats are near-binding.
- `../docs/datasource-class-diagram-v2.mermaid`, `../docs/datasource-executor-pipeline.mermaid` — the class model and the enforcement pipeline.
- `../docs/phase-1-plan.md` — the current build phase's file layout and test plan.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions. Validation is Laravel rules; authorization routes to policies; queued execution is bus batches.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `datawell/datawell` and the `Datawell\` namespace. Never put "laravel" in a product or domain name.
- Wire identity is a stable string key; class names never appear in anything serialized. Hidden means absent — gated things are missing from output, never present-but-flagged.
- Add only the files and dependencies needed for the package behavior being implemented.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, published resources, and documentation promises. Schema JSON snapshots per source are the primary proof of the contract layer.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.

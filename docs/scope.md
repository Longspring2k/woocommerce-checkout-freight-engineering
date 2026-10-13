# Scope and evidence boundaries

## Public runnable state

This repository contains an original synthetic PHP mechanism demo plus anonymized technical documentation. Local tests exercise the partial checkout reducer and the in-memory freight preview/guarded-apply protocol.

## Evidence basis

Passing tests establish the documented behavior of these newly authored classes on the tested PHP runtime. They cover omission versus explicit clear, preservation of unrelated state, owned cleanup, atomic invalid-patch refusal, freight schema and duplicate validation, stable ordering/hash behavior, successful backup/audit creation, and unchanged state after stale/replayed/foreign plan refusal.

The broader case study remains based on private implementation and verification artifacts. Those materials are not redistributed, and the public demo is not represented as the private production source.

## Not claimed or distributed

- No production order, checkout submission, payment, freight calculation, or completed real-store import is performed.
- No original client source, identities, domains, paths, workbooks, actual rates, datasets, configuration, credentials, backups, screenshots, or deployment scripts are included.
- No XLSX parser, WooCommerce hook adapter, persistence layer, database transaction/CAS, cross-process concurrency control, or rollback operator workflow is implemented.
- The in-memory plan registry assumes trusted code in one PHP process. It is not a hostile-process security boundary.
- Content hashes provide deterministic identity, not authorization or authenticity.

## Public repository boundary

The public code uses normalized arrays and synthetic integer charge units. It deliberately limits reconciliation to documented merge semantics. Production adapters would own workbook ingestion, operator authorization, WooCommerce request/session integration, schema translation, durable transactional persistence, retention, monitoring, and operational recovery.

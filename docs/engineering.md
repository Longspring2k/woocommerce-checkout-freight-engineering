# Engineering decisions

## One state interpretation

A partial request is an instruction set, not a complete form snapshot. The reducer tests field presence rather than coercing a missing value to an empty value. Consequently, omission preserves state, a present `null` clears the nullable note, and a method transition cleans only its owned dependent note. Validation completes before the output state is assembled.

## Exact normalized input instead of pretend parsing

The public demo begins with normalized PHP arrays. It does not claim to parse XLSX. That boundary keeps spreadsheet concerns—cell typing, headers, formulas, merged cells, row locations, and upload security—out of a mechanism intended to show domain validation and guarded mutation.

## Integer synthetic charge units

Charges use exact integers. The example intentionally assigns no currency or decimal conversion rule. A production adapter would define units and currency explicitly; using floats in the core would make deterministic equality and hashing less reliable.

## Merge-only reconciliation

The service implements one named mode: `merge`. Candidate business keys replace matching rows and leave unrelated current rows intact. Replace and deletion behavior are omitted because adding modes without complete review semantics would broaden the demo while weakening its contract.

## Preview before mutation

Preview validates both the expected predecessor and candidates, normalizes ordering, then stores exact immutable-by-convention values in a private registry. Apply retrieves that frozen entry rather than accepting rows or predecessor hashes from public plan fields. The plan is an opaque, single-use capability for one service instance.

## Deterministic identity, not proof of authority

SHA-256 identifies the exact normalized ordered content. It supports review and predecessor comparison, but it does not authenticate an operator or make client-provided data trustworthy. Authorization and server-side plan storage remain production adapter responsibilities.

## Success-only recovery evidence

Backup and audit entries are appended only after every validation and stale-plan guard passes. A refused operation cannot produce records suggesting a change occurred. Within this single-process object, the state/backup/audit assignments model one guarded unit; they are not a production database atomicity guarantee.

## Isolation boundary

The runnable code is pure/in-memory and has no network, WordPress, database, filesystem-import, shipping, payment, or order-submission side effects. That makes the contracts inspectable but says nothing about host-level isolation or production concurrency.

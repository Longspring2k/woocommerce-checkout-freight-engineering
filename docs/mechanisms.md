# Mechanism contracts

This repository contains a newly authored, dependency-free PHP teaching demo. It models two mechanisms related to the original private engineering case; it does not reproduce client code, data, workbooks, rates, domains, or integration details.

## Quickstart

Requirements: PHP 8.2 or newer.

```bash
php tests/run.php
php tests/run.php freight
php demo/run.php
```

The optional test argument is a case-sensitive substring filter. A filter matching no test exits with status `2`.

## Code map

- `src/CheckoutStateReducer.php` — pure partial-request reducer.
- `src/FreightPlanService.php` — normalized freight rows, preview registry, deterministic hashing, merge, backup, and audit behavior.
- `tests/run.php` — dependency-free regression runner.
- `demo/run.php` — successful checkout/freight sequences plus replay and stale-plan refusals.

## Partial checkout state

The reducer accepts an existing session-like array and a patch containing only `delivery_method` and/or `delivery_note`. It validates the complete patch before producing a new array.

| Request | Meaning | Result |
| --- | --- | --- |
| `{}` | both fields omitted | preserve applicable owned values and every unrelated value |
| `{"delivery_note": null}` | explicit clear | remove only `delivery_note` |
| `{"delivery_method": "collect"}` | change method | set method and remove the now-inapplicable owned note |
| unknown/invalid field | malformed patch | throw before any result is produced |

`array_key_exists` supplies the important distinction: omission means “no instruction,” while a present `null` is an explicit clear for the nullable note. Cleanup is limited to keys this reducer owns. It never rebuilds the whole session or removes unrelated cart/plugin state.

Accepted method values in a patch are exactly `ship` and `collect`. Notes are null or strings whose trimmed form is at most 120 bytes. Empty and whitespace-only strings clear the note. A note is retained only when the resulting method is `ship`. Existing owned session values are expected to come from valid prior reducer output; even an empty patch removes a leftover inapplicable note when the stored method is absent or not `ship`. The reducer does not validate unrelated session values or repair an invalid stored method.

## Freight row contract

Input is a sequential list of normalized array fixtures, not an XLSX file. Named or sparse outer keys are rejected. Every row must contain exactly these fields in this order:

```php
[
    'zone_id' => '007',          // digit string, 1..12 chars; leading zero preserved
    'service_code' => 'EXPRESS', // uppercase identifier, max 32 chars
    'charge_units' => 1299,      // integer synthetic units, 0..100000000
    'active' => true,             // exact boolean
]
```

The business key is the pair `(zone_id, service_code)`. Length-prefixing the zone when constructing the internal key prevents ambiguous concatenation. Duplicate keys fail closed. Inactive rows must have zero charge units. Floats, numeric strings, missing/extra fields, invalid identifiers, and malformed cross-field combinations are rejected before preview registration or mutation.

Rows are sorted lexically by exact `zone_id`, then `service_code`. This preserves identifier text such as `007` and makes content hashes independent of incoming row order. The hash is SHA-256 over JSON encoding of normalized ordered rows with unescaped slashes. It is a content identity, not authentication or authorization.

## Preview/apply protocol

Only **merge mode** is implemented. Incoming business keys replace matching current rows; current rows with other keys remain. Replace/delete semantics are deliberately omitted rather than implied.

1. `preview(expectedCurrentRows, candidateRows)` fully validates and normalizes both sets.
2. It records a private, in-memory registry entry containing exact candidate rows, candidate content hash, and predecessor hash.
3. The returned plan exposes only row count/content hash plus an opaque token. Apply does not trust public display fields as mutation input.
4. `apply(plan)` requires a token issued by the same service instance and a current-state hash equal to the frozen predecessor hash.
5. Only after all guards pass does apply compute and assign the merged state, append the pre-apply backup, append the audit event, and consume the token.

A plan is single-use. A replay, foreign-service token, unknown token, or changed predecessor is refused. Failure leaves freight state, backups, and audit events unchanged. The in-memory assignments are presented as one guarded operation for this single-process demo; they are not a database transaction or compare-and-swap guarantee.

Changing a plan object's display hash or row count cannot change the private frozen candidate. Anyone already holding the issued token can request its original operation; the token is the capability, not a signed display object. The regression suite checks that caller edits and forged display fields do not alter applied rows or audit metadata. Predecessor comparison is content-based rather than revision-based: a change away and back to identical content is not detected as an intervening edit.

## Hash trust boundary

The content hash makes normalized content comparison deterministic and visible. It does not make a plan unforgeable by itself. Trust comes from the service-private plan registry and high-entropy opaque token. Code running in the same trusted PHP process could still use reflection or alter memory, so this model is not a hostile-process security boundary.

A production implementation should store an immutable plan server-side, associate it with an authenticated/authorized operator and environment, expire it, and enforce predecessor comparison plus backup/audit writes inside a real database transaction or equivalent atomic primitive.

## Failure matrix

| Failure | Detected | Mutation |
| --- | --- | --- |
| unknown checkout field | patch validation | none |
| invalid checkout type/value | patch validation | none |
| malformed freight field/type | row validation | none |
| duplicate business key | normalized set validation | none |
| inactive row with non-zero charge | cross-field validation | none |
| stale predecessor | apply guard | none; no backup/audit |
| replayed/foreign/unknown plan | registry guard | none; no backup/audit |

## Deliberate boundaries and production adapters

This demo does not parse XLSX, call WooCommerce hooks, update sessions, calculate shipping, submit checkout/orders, take payments, persist rows, or provide inter-process concurrency. It uses synthetic integer charge units and fixtures only.

Real adapters would be responsible for:

- converting an uploaded workbook into normalized row arrays while preserving identifier text and reporting source row locations;
- authenticating/authorizing preview and apply operations, validating nonces, limiting upload size, and protecting stored plans;
- mapping checkout request presence semantics into the pure reducer and writing only owned WooCommerce session keys;
- translating validated synthetic-domain rows into the actual freight schema;
- database transaction/CAS locking, durable backups, audit identity/timestamps, retention, rollback, and operational monitoring;
- WooCommerce-specific shipping/package recalculation and regression testing without pretending this demo submits an order.

# Engineering decisions

WooCommerce Checkout and Freight Engineering

## One state interpretation

Do not let a partial AJAX payload silently reset a valid session choice.

## Preview before mutation

Separate workbook analysis and mapping review from the operation that changes live data.

## Recovery is part of the import

Treat backups, expected row state and audit records as apply preconditions rather than optional aftercare.

## Isolation has a boundary

WordPress-level transport guards reduce side effects; they are not host-level network isolation.

## Reading the architecture

Workbook input → Analysis and mapping preview → Validation and backup gate → Batched apply and audit → Checkout configuration

The diagram reduces the implementation to its relevant responsibilities. It does not expose private endpoints, exact deployment layout, resource identifiers or operational configuration.

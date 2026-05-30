# Manager Ticket Generation Design

## Goal

Extend the manager role so a manager can generate hotspot tickets, print them immediately, review vendor stock levels, and organize transfers either from one vendor to another or from a global manager stock to a chosen vendor.

## Current State

- `manager.php` already exposes dashboard, accounting, vendor management, transfer, logs, and a custom `tickets` page.
- `hotspot/generateuser.php` already accepts manager sessions, but its working form contract is still the legacy admin POST contract.
- The manager `tickets` screen uses a custom GET form that does not match `generateuser.php`, so generation is only partially wired.
- `manage_sellers.php` already implements global-stock distribution for admins.
- `manager.php?action=transfer` only supports vendor-to-vendor transfer, not global-stock-to-vendor transfer.

## Requested Behavior

When a manager generates tickets, the screen must offer two explicit destination modes:

1. Keep generated tickets in manager/global stock.
2. Assign generated tickets immediately to a selected vendor.

The manager must also be able to:

- print generated tickets,
- view the stock level of each vendor,
- move stock from vendor X to vendor Y,
- move stock from global manager stock to vendor X or Y.

## Recommended Approach

Reuse the existing generation engine in `hotspot/generateuser.php` instead of creating a second ticket-generation workflow.

### Why

- It already knows how to create users on the MikroTik router.
- It already provides the print actions after generation.
- It already supports manager sessions at the access-control level.
- It keeps voucher generation, numbering, comments, and print templates in one place.

## Functional Design

### 1. Manager generation flow

The manager `tickets` page becomes a manager-friendly front end for the legacy generator:

- submit with `POST`, not `GET`,
- send the field names expected by `generateuser.php`,
- include an explicit destination mode selector:
  - `global` for manager/global stock,
  - `seller` for immediate vendor assignment,
- reveal vendor selection only when `seller` is chosen.

Comment assignment rules:

- `global`: generated batch comment stays unassigned to any seller suffix,
- `seller`: generated batch comment is suffixed with the seller key through the existing helper logic.

### 2. Manager transfer flow

The manager transfer page is extended with a second section:

- existing section: vendor-to-vendor transfer,
- new section: global stock distribution to one or more vendors.

This mirrors the admin stock distribution pattern but remains constrained to the manager session.

### 3. Printing behavior

Printing continues to rely on the existing voucher print engine:

- immediate print after generation from `generateuser.php`,
- existing template behavior remains unchanged,
- no extra print renderer is introduced.

### 4. Visibility and role boundaries

Manager rights after this change:

- can generate tickets,
- can print generated tickets,
- can distribute global stock,
- can transfer stock between vendors,
- can see stock by vendor/profile,
- cannot access broader admin-only settings outside the already-allowed manager routes.

Vendor rights remain unchanged.

Admin rights remain unchanged.

## Files Expected To Change

- `manager.php`
  - fix manager ticket generation form contract,
  - add explicit destination mode,
  - add manager global-stock distribution UI and handler.
- `hotspot/generateuser.php`
  - accept manager generation mode cleanly,
  - normalize seller/global destination handling.
- `include/transfer_log.php`
  - reuse existing logging for manager global distributions if needed.
- language files if new labels are added.

## Verification Strategy

- PHP lint on touched files.
- Browser verification on:
  - manager login,
  - `manager.php?action=tickets`,
  - `manager.php?action=transfer`,
  - immediate print redirect after generation.
- Rebuild flattened `armv7` and `arm64` images.
- Push Docker Hub tags.
- Redeploy `armv7` image to MikroTik `10.10.0.1`.

# Manager Ticket Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let managers generate and print tickets with an explicit destination choice, then distribute global stock or transfer vendor stock from the manager portal.

**Architecture:** Reuse `hotspot/generateuser.php` as the single ticket-generation backend, and upgrade `manager.php` so the manager UI speaks the same field contract as the legacy generator. Extend the manager transfer screen with a second flow for global-stock distribution while preserving the existing vendor-to-vendor transfer path.

**Tech Stack:** PHP, RouterOS API, existing Mikhmon UI/CSS, flattened OCI archives for MikroTik containers.

---

### Task 1: Align manager ticket generation with the existing generator

**Files:**
- Modify: `/Applications/MAMP/htdocs/mikhmon/manager.php`
- Modify: `/Applications/MAMP/htdocs/mikhmon/hotspot/generateuser.php`

- [ ] Replace the current manager `GET` ticket form with a `POST` form that sends the field names expected by `generateuser.php`.
- [ ] Add an explicit destination mode on the manager ticket screen:
  - `global`
  - `seller`
- [ ] Show the seller selector only when destination mode is `seller`.
- [ ] Make sure the manager ticket form includes the minimum required generator fields:
  - `qty`
  - `server`
  - `user`
  - `userl`
  - `char`
  - `profile`
  - `timelimit`
  - `datalimit`
  - `mbgb`
  - `seller_id`
  - `adcomment`
  - `session`

### Task 2: Normalize manager generation destination handling

**Files:**
- Modify: `/Applications/MAMP/htdocs/mikhmon/hotspot/generateuser.php`

- [ ] Add manager-safe handling for the destination mode so a manager can intentionally generate:
  - unassigned global stock,
  - or seller-assigned stock.
- [ ] Preserve the existing seller-comment suffix helper when a seller is chosen.
- [ ] Keep the print redirect behavior unchanged so generated vouchers can still be printed immediately.

### Task 3: Extend manager transfers with global-stock distribution

**Files:**
- Modify: `/Applications/MAMP/htdocs/mikhmon/manager.php`
- Reuse: `/Applications/MAMP/htdocs/mikhmon/include/transfer_log.php`

- [ ] Add a manager-side distribution handler that consumes unassigned unused tickets from the current session.
- [ ] Add a UI section under `manager.php?action=transfer` showing global stock by profile.
- [ ] Add a distribution form to move global stock to one or more vendors.
- [ ] Log successful distributions with the existing transfer log helper using role `manager`.

### Task 4: Keep the UI coherent and responsive

**Files:**
- Modify: `/Applications/MAMP/htdocs/mikhmon/manager.php`
- Modify: `/Applications/MAMP/htdocs/mikhmon/css/mikhmon-portal.css` only if needed

- [ ] Reuse the existing portal card/grid styles for the new manager controls.
- [ ] Keep the new generation and distribution controls responsive on narrow screens.
- [ ] Avoid adding a second visual language; follow the portal styles already in use.

### Task 5: Verify application behavior

**Files:**
- Verify: `/Applications/MAMP/htdocs/mikhmon/manager.php`
- Verify: `/Applications/MAMP/htdocs/mikhmon/hotspot/generateuser.php`

- [ ] Run PHP lint on all touched files.
- [ ] Verify manager login still works.
- [ ] Verify `manager.php?action=tickets` renders and submits.
- [ ] Verify a manager can generate global stock.
- [ ] Verify a manager can generate stock directly for a seller.
- [ ] Verify `manager.php?action=transfer` supports both:
  - vendor-to-vendor transfer,
  - global-to-vendor distribution.

### Task 6: Rebuild and publish delivery artifacts

**Files:**
- Modify generated artifacts:
  - `/Users/bambaabdoulatif/Desktop/mikhmon-safelink-armv7.tar.gz`
  - `/Users/bambaabdoulatif/Desktop/mikhmon-safelink-arm64.tar.gz`

- [ ] Rebuild flattened `armv7` and `arm64` archives from `/Applications/MAMP/htdocs/mikhmon`.
- [ ] Push updated `armv7`, `arm64`, and `latest` tags to Docker Hub.
- [ ] Redeploy the new `armv7` archive to MikroTik `10.10.0.1`.
- [ ] Verify the container is running and the HTTP endpoint responds.

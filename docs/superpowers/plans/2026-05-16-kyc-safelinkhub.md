# SafeLinkHub KYC Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the SafeLinkHub KYC flow so operators and admins can use the dashboard before verification, but must complete KYC before withdrawing earnings.

**Architecture:** Reuse the existing `OperatorVerification` backend model and endpoints, extend the gate logic to role-aware behavior, and replace the profile-page KYC form with a guided dashboard wizard. Keep withdrawal enforcement server-side and add dashboard UI cues so the rule is visible before the user reaches withdrawal.

**Tech Stack:** Express, Prisma, PostgreSQL, Next.js 15, React 19, Tailwind CSS, lucide-react, framer-motion.

---

## File Structure

- Modify `backend/src/services/operatorVerification.service.ts`: make the KYC gate role-aware and exempt superadmins.
- Modify `backend/src/controllers/operatorVerification.controller.ts`: return role-aware status and prevent superadmin submissions from becoming required.
- Modify `backend/src/controllers/withdrawal.controller.ts`: keep hard withdrawal gate and ensure admin/operator statuses block correctly.
- Modify `frontend/src/app/dashboard/profile/page.tsx`: replace the dense form with a Binance-inspired guided wizard.
- Modify `frontend/src/app/dashboard/earnings/page.tsx`: preserve hard redirect to profile/KYC and make status text clearer.
- Modify `frontend/src/app/dashboard/admin/verifications/page.tsx`: ensure admin can view, superadmin can review, and role/status labels are explicit.
- Modify `frontend/src/components/LightDashboardLayout.tsx`: add a non-blocking KYC notification for operator/admin only.

## Tasks

### Task 1: Backend KYC Gate

**Files:**
- Modify: `backend/src/services/operatorVerification.service.ts`
- Modify: `backend/src/controllers/operatorVerification.controller.ts`
- Modify: `backend/src/controllers/withdrawal.controller.ts`

- [ ] Step 1: Update `getOperatorVerificationGate` to fetch user role and return `requiresVerification: false`, `canWithdraw: true`, and `status: verified` for `superadmin`.
- [ ] Step 2: Ensure `operator` and `admin` require KYC when they have withdrawable automatic payment sales.
- [ ] Step 3: Keep pending/rejected/unverified blocked in `requestWithdrawal`.
- [ ] Step 4: Run `npm run build --workspace=backend`.

### Task 2: KYC Wizard UI

**Files:**
- Modify: `frontend/src/app/dashboard/profile/page.tsx`

- [ ] Step 1: Keep existing camera capture and upload mechanics.
- [ ] Step 2: Add wizard state for the 5 steps: identity, document type, document upload, face scan, review.
- [ ] Step 3: Replace the single long form with step cards, a progress header, and bottom navigation.
- [ ] Step 4: Disable forward actions until the current step requirements are complete.
- [ ] Step 5: Preserve pending, rejected, and verified state screens.
- [ ] Step 6: Run `npm run build --workspace=frontend`.

### Task 3: Dashboard Cues And Review UI

**Files:**
- Modify: `frontend/src/components/LightDashboardLayout.tsx`
- Modify: `frontend/src/app/dashboard/earnings/page.tsx`
- Modify: `frontend/src/app/dashboard/admin/verifications/page.tsx`

- [ ] Step 1: Add a compact KYC notification in dashboard layout for `operator` and `admin` when `/operator-verification/status` says verification is required and not complete.
- [ ] Step 2: Keep withdrawal CTA routing blocked users to `/dashboard/profile`.
- [ ] Step 3: Make the admin verification list display role and review permission clearly.
- [ ] Step 4: Run frontend build again.

### Task 4: Deploy On VPS

**Files:**
- Deploy modified files into `/docker/safelink`.

- [ ] Step 1: Save timestamped backups of each file before replacement.
- [ ] Step 2: Copy modified files into place.
- [ ] Step 3: Run backend and frontend builds on the VPS.
- [ ] Step 4: Restart the running SafeLinkHub services using the project’s existing Docker/compose workflow.
- [ ] Step 5: Verify public site responds at `https://www.safelinkhub.io`.

## Verification Checklist

- [ ] Backend build succeeds.
- [ ] Frontend build succeeds.
- [ ] Superadmin KYC status is exempt by backend gate.
- [ ] Operator/admin withdrawal still returns `PROFILE_VERIFICATION_REQUIRED` until verified.
- [ ] Profile page has a guided KYC wizard and no mobile-overlap-prone long control stack.
- [ ] Admin review page still opens document files and only superadmin can approve/reject.

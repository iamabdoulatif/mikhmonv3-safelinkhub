# SafeLinkHub KYC Design

Date: 2026-05-16
Project: `/docker/safelink` on VPS `31.97.153.83`
Site: `https://www.safelinkhub.io`

## Goal

Add a Binance-inspired identity verification flow to SafeLinkHub while preserving the existing dashboard design. Operators and admins can continue using the platform before verification, but verification becomes mandatory before withdrawing earnings. Superadmins are exempt from KYC and are the only role allowed to approve or reject KYC submissions.

## Scope

This feature applies to authenticated users with role `operator` or `admin`.

It does not apply to `superadmin` accounts. Superadmins can review all submitted KYC files, including admin submissions.

The implementation will reuse the existing `OperatorVerification` model and API surface instead of creating a second KYC system. The current verification base already stores country, phone number, document type, document files, three face captures, status, rejection reason, and review timestamps.

## User Flow

Users may browse and operate the dashboard without completing KYC. When their account needs verification, the dashboard should show a visible but non-blocking notification.

The withdrawal flow remains the hard gate. When an operator or admin clicks withdraw while not verified, SafeLinkHub redirects them to the KYC flow or shows a clear call to action. Backend withdrawal requests must remain blocked with `PROFILE_VERIFICATION_REQUIRED` until the KYC status is `verified`.

The KYC flow is a guided wizard inspired by Binance:

1. Country and phone confirmation.
2. Document type selection: CNI or passport.
3. Document upload: CNI recto/verso, or passport face page.
4. Face capture: front, left, and right angles using camera capture, with manual upload fallback.
5. Review screen: user confirms all data and submits for review.

After submission, the user sees a pending state with the existing 24-hour review expectation. If rejected, the user sees the rejection reason and can submit a new dossier. If verified, the selfie becomes the visible profile avatar, and withdrawals are unlocked.

## Admin Review Flow

The admin verification page remains available to `admin` and `superadmin` for viewing. Only `superadmin` can approve or reject requests.

Review cards must display:

- User name, email, and role.
- Country, phone, and document type.
- Submitted date and 24-hour review deadline.
- Front, left, and right face captures.
- Download/open actions for document front and back.
- Approve and reject actions only for superadmin.

The backend must enforce the same permission checks. UI hiding is not enough.

## Data And Security

Documents stay in the secure backend folder using the existing `secure://operator-verification/...` storage pattern. Selfie files can stay in `public/profile-faces/...` because the approved front selfie is used as the operator/admin avatar.

Uploaded files keep the current limits:

- Max 10 MB per file.
- Selfies: JPG, PNG, WEBP.
- Documents: JPG, PNG, WEBP, PDF.

The backend must prevent incomplete approvals. A KYC request can be approved only when required face captures and required document files exist.

## Role Rules

`operator`: KYC notification shown when needed; withdrawal blocked until verified.

`admin`: same KYC requirement as operator; can view KYC review list but cannot approve or reject.

`superadmin`: no KYC requirement; can approve and reject all KYC submissions.

`reseller`: out of scope for this change.

## UI Direction

The new KYC user experience should match SafeLinkHub's current dashboard language:

- White cards with subtle borders.
- Orange primary actions.
- Compact but clear dashboard layout.
- Mobile-friendly wizard controls.
- Lucide icons for actions and status.
- No marketing-style landing page.

The Binance reference should influence flow structure and guided capture behavior, not copy Binance branding.

## Expected Code Areas

Backend:

- `backend/src/services/operatorVerification.service.ts`
- `backend/src/controllers/operatorVerification.controller.ts`
- `backend/src/routes/operatorVerification.routes.ts`
- `backend/src/controllers/withdrawal.controller.ts`
- `backend/prisma/schema.prisma` only if existing fields are insufficient.

Frontend:

- `frontend/src/app/dashboard/profile/page.tsx` or a new dedicated KYC route under dashboard.
- `frontend/src/app/dashboard/earnings/page.tsx`
- `frontend/src/app/dashboard/admin/verifications/page.tsx`
- `frontend/src/components/LightDashboardLayout.tsx` for the non-blocking notification if needed.
- `frontend/src/components/Sidebar.tsx` only if navigation label changes are necessary.

## Testing Strategy

Backend tests or focused scripts should verify:

- Superadmin is exempt from KYC.
- Admin and operator are blocked from withdrawal until verified when verification is required.
- Pending and rejected statuses remain blocked.
- Verified status unlocks withdrawal.
- Admin cannot approve or reject; superadmin can.
- Incomplete dossiers cannot be approved.

Frontend verification should cover:

- Wizard renders all steps.
- Submit button stays disabled until required files/captures are present.
- Earnings page routes blocked users to KYC.
- Verified/pending/rejected states display correctly.
- Mobile layout has no overlapping controls.

## Non-Goals

This change will not integrate a paid external KYC provider.

This change will not perform automated face matching, liveness detection, or OCR validation. It is a guided manual-review KYC flow.

This change will not require KYC before login or before ordinary dashboard usage.

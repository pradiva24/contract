# Contract Studio – Phase 1 Canvas Foundation

## What is included
- Canvas-first Contract Studio page at `public/contract-studio.php`.
- Konva-based canvas with A4 portrait/landscape, zoom, multiple pages, text, shapes, image insertion, selection, drag, resize/rotate transformer, object properties, layer ordering, delete, undo/redo.
- Company-scoped template library at `public/contract-templates.php`.
- Save endpoint in the editor page, with CSRF validation, company ownership checks, JSON size/schema checks, and template version snapshots.
- New migration: `database/migrations/20261105_002_contract_studio.sql`.

## Deployment
1. Back up the database.
2. Run migration `20261105_001_multi_company_foundation.sql` first if it has not already been applied.
3. Run `20261105_002_contract_studio.sql`.
4. Deploy the repository branch files.
5. Sign in as an admin assigned to a company, open Dashboard → Contract Studio, create a template and save it.

## Current MVP limitations
- The editor is an initial canvas foundation, not yet feature-equivalent to Canva.
- Image files are currently embedded as data URLs in the saved document JSON. This is suitable only for small prototype documents; the next phase should move uploads into company-scoped media storage and persist asset references.
- The initial canvas object types are text, rectangle, and image. Product tables, gallery, signature, stamp, QR, richer table editing, grouping, guides, and PDF rendering are planned next.
- A4 page dimensions are represented on screen using a 96-DPI CSS-pixel approximation. The PDF renderer must map the document coordinates to physical A4 dimensions and be tested separately.
- Konva is loaded from jsDelivr CDN. Production deployment should pin and self-host the library or provide an approved asset fallback.
- The existing certificate template editor is not modified.

## Security and tenant rules
- Every template read/write is scoped by the authenticated admin's `company_id`.
- A global super admin without an explicitly selected company context receives the existing company-context 403 response.
- Do not expose the editor to another company until migration and tenant access tests have passed.

## Not done
- No migration has been executed on production.
- No PDF export has been connected to this canvas model.
- No live deployment or browser QA has been performed.

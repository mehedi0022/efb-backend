# Project Architecture Analysis

## 1. Current System Overview

- Backend: Laravel 10 (`project/`)
- Frontend (legacy): React + Vite inside Laravel resources
  - Store/User panel: `resources/js`
  - Admin panel: `resources/js/admin`

## 2. Routing Analysis

## Web Routes

- `routes/web.php`
  - `/` -> `welcome` blade
  - fallback SPA route for non-admin paths
- `routes/admin.php`
  - `/admin/*` -> admin blade entry

## API Routes

- `routes/api.php`
  - Public storefront API: `/api/v1/*`
  - Admin API: `/api/admin/*`

### Public API domain areas

- Settings + site metadata
- Product catalog + product details
- External proxy endpoints (menu/categories/search/product)
- Cart operations
- Checkout
- Customer auth (register/login/user/logout/refresh)

### Admin API domain areas

- Auth (`login/refresh/logout/me`)
- Dashboard
- Orders
- Products
- Categories/subcategories/brands/colors/sizes
- Reviews
- Site settings (general/contact/pages/shipping/order statuses)
- Integrations (payment/sms/courier)
- Pixels/GTM/Banners
- Incomplete orders
- Reports
- Users

## 3. Controller and Service Layer Analysis

## Reusable Business Logic Identified

- Product domain service split:
  - `app/Services/ProductService.php`
  - `app/Repositories/ProductRepository.php`
- Inventory enrichment:
  - `app/Services/InventoryService.php`
- Cart lifecycle:
  - `app/Services/CartService.php`
- Order creation pipeline:
  - `app/Services/OrderService.php`

## Observed issues before refactor

- Auth inconsistency:
  - public/admin used Sanctum tokens
  - admin `me/logout` previously stubbed
- Mixed response shapes across controllers
- Admin routes were broadly exposed without strict auth grouping
- Query issues:
  - product list formatter triggered relation access not eagerly loaded
  - cancellation stock logic reduced stock instead of restoring
  - several duplicated data-fetch patterns

## 4. Middleware and Auth Flow

## Before

- `auth:sanctum` partially applied
- many admin routes publicly reachable

## After (refactor)

- New JWT middleware:
  - `app/Http/Middleware/JwtAuthenticate.php`
  - `app/Http/Middleware/JwtOptionalAuthenticate.php`
- Middleware aliases added in `app/Http/Kernel.php`:
  - `jwt`
  - `jwt.optional`
- Token service:
  - `app/Services/JwtService.php`
- Auth controllers migrated to JWT token pair flow:
  - Customer: `app/Http/Controllers/Api/AuthController.php`
  - Admin: `app/Http/Controllers/Api/Admin/AuthController.php`
- Admin API now protected under `jwt:admin` group

## 5. Frontend Analysis (legacy React)

## User panel

- Router: `resources/js/router.jsx`
- Context wrappers:
  - `AuthContext`
  - `CartContext`
  - `SettingsContext`
  - `SiteDataContext`
- API/state: RTK Query via `resources/js/store/publicApi.js`

## Admin panel

- Router shell: `resources/js/admin/App.jsx`
- Layout: `AdminLayout`, `Sidebar`, `Topbar`
- API/state: generic RTK Query endpoints in `resources/js/store/adminApi.js`
- Large module surface under `resources/js/admin/pages/*`

## 6. Frontend ↔ Backend Interaction

- Store panel uses `/api/v1/*` endpoints
- Admin panel uses `/api/admin/*` endpoints
- Tokens stored in localStorage:
  - store: `token`
  - admin: `auth_token`
- Cart identity propagated with `X-Cart-ID`

## 7. Refactor Outcomes

- Laravel converted to API-first with JWT auth flow and protected admin routes
- Shared API response helper + standardized API exception responses
- Query fixes in high-impact controllers (product/order/dashboard)
- New standalone Next.js app extracted to `naxt-ecommerce/`

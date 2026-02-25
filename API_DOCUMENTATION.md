# Laravel API Documentation

Last updated: 2026-02-20
Project: `project` (Laravel API-only backend)

## 1) Quick Overview

- Public API prefix: `/api/v1`
- Admin API prefix: `/api/admin`
- Auth mechanism: JWT (custom service, access + refresh token)
- Rate limit: `60` requests/minute (per user or IP)

Swagger/OpenAPI:

- Swagger UI: `/docs` (redirects to `/swagger/index.html`)
- OpenAPI JSON: `/openapi.json` (or `/openapi`)
- Regenerate spec file: `php scripts/generate_openapi.php`

## 2) Base URL and Environment

Use your deployed API domain, example:

- Local: `http://localhost:8000`
- Production example: `https://your-domain.com`

Important `.env` variables:

- `APP_URL` -> used for app URL and external domain normalization.
- `API_BASE_URL` -> external proxy base URL (default fallback: `https://api.freelancerbangladesh.com`).
- `JWT_SECRET`, `JWT_ISSUER`, `JWT_ACCESS_TTL`, `JWT_REFRESH_TTL`, `JWT_CLOCK_SKEW`.

## 3) Required Headers

Common headers:

- `Accept: application/json`
- `Content-Type: application/json` (for JSON body)
- `Authorization: Bearer <access_token>` (for protected routes)

Cart/checkout headers:

- `X-Cart-ID: <cart_id>`

Notes:

- `GET /api/v1/cart` and `POST /api/v1/cart/add` can create a cart if no `X-Cart-ID` is provided.
- `PUT /api/v1/cart/items/{itemId}`, `DELETE /api/v1/cart/items/{itemId}`, `DELETE /api/v1/cart`, `POST /api/v1/checkout` require `X-Cart-ID`.

## 4) Authentication Types

- `Public`: token লাগবে না.
- `Customer JWT`: customer access token required.
- `Admin JWT`: admin access token required.
- `Optional Customer JWT`: token না দিলেও চলবে; invalid token দিলে error আসবে.

## 5) JWT Auth Flow

### 5.1 Customer Flow (`/api/v1`)

1. `POST /api/v1/register` or `POST /api/v1/login`
2. Response includes:
   - `access_token`
   - `refresh_token`
   - `expires_in`
   - `refresh_expires_in`
   - `token_type` (`Bearer`)
3. Protected calls: `Authorization: Bearer <access_token>`
4. Renew token: `POST /api/v1/refresh-token` with `refresh_token`
5. Logout: `POST /api/v1/logout` with bearer token and optionally `refresh_token`

### 5.2 Admin Flow (`/api/admin`)

1. `POST /api/admin/login`
2. Use returned `access_token` for protected admin routes.
3. Renew token: `POST /api/admin/refresh-token`
4. Logout: `POST /api/admin/logout`

### 5.3 Auth Request Bodies

Customer register:

```json
{
  "name": "John Doe",
  "phone": "01700000000",
  "email": "john@example.com",
  "password": "secret123",
  "password_confirmation": "secret123"
}
```

Customer login:

```json
{
  "phone": "01700000000",
  "password": "secret123"
}
```

Customer/Admin refresh:

```json
{
  "refresh_token": "<refresh_token>"
}
```

Admin login:

```json
{
  "email": "admin@example.com",
  "password": "secret123"
}
```

## 6) Common Response Format

Most endpoints use:

Success:

```json
{
  "success": true,
  "message": "...",
  "data": {}
}
```

Error:

```json
{
  "success": false,
  "message": "...",
  "errors": {}
}
```

Common HTTP status:

- `200` OK
- `201` Created
- `400` Bad Request
- `401` Unauthenticated/invalid token
- `403` Forbidden
- `404` Not Found
- `422` Validation Error
- `500` Internal Server Error

Note: কিছু endpoint legacy shape return করে (যেমন কিছু route-এ `data` সরাসরি array/object, বা `message` না-ও থাকতে পারে)।

## 7) Public API (`/api/v1`) - Easy Guide

### 7.1 Content and Catalog

- `GET /api/v1/settings` -> active general setting
- `GET /api/v1/site-data` -> contact, pages, menu categories
- `GET /api/v1/pages/{slug}` -> single page by slug
- `GET /api/v1/home-data` -> home product blocks
- `GET /api/v1/home-categories` -> max 3 show_home categories with products
- `GET /api/v1/products` -> product listing with filters
- `GET /api/v1/products/{slug}` -> product detail + related
- `GET /api/v1/products/hot-deals`
- `GET /api/v1/products/latest`
- `GET /api/v1/products/new-arrivals`
- `GET /api/v1/shipping-charges` -> active shipping charges

Product list query params (`GET /api/v1/products`):

- `category_id`
- `subcategory_id`
- `childcategory_id`
- `search`
- `limit` (default 20)

Hot/latest/new-arrivals query params:

- `limit`

### 7.2 External Proxy Endpoints

These proxy routes call external API base URL from `API_BASE_URL` (fallback `https://api.freelancerbangladesh.com`).

- `GET /api/v1/external/featured-categories` (`page`, `limit`)
- `GET /api/v1/external/menu-categories` (`page`, `limit`)
- `GET /api/v1/external/top-sell` (`page`, `limit`)
- `GET /api/v1/external/hot-deals` (`page`, `limit`)
- `GET /api/v1/external/category-products` (`cat_page`, `cat_limit`, `prod_page`, `prod_limit`)
- `GET /api/v1/external/category/{slug}` (`page`, `limit`)
- `GET /api/v1/external/product/{slug}`
- `GET /api/v1/external/search` (`keyword` or `search`, `page`, `limit`)

Important:

- Domain is derived from `APP_URL` and normalized to `www.<host>` before forwarding.

### 7.3 Customer Auth Endpoints

- `POST /api/v1/register`
- `POST /api/v1/login`
- `POST /api/v1/refresh-token`
- `GET /api/v1/user` (Customer JWT)
- `POST /api/v1/logout` (Customer JWT)

### 7.4 Cart and Checkout

- `GET /api/v1/cart` (optional JWT)
- `POST /api/v1/cart/add` (optional JWT)
- `POST /api/v1/cart/external/add` (optional JWT)
- `PUT /api/v1/cart/items/{itemId}` (optional JWT)
- `DELETE /api/v1/cart/items/{itemId}` (optional JWT)
- `DELETE /api/v1/cart` (optional JWT)
- `POST /api/v1/checkout` (optional JWT)

`POST /api/v1/cart/add` body:

```json
{
  "product_id": 123,
  "quantity": 2,
  "options": {
    "size": "L",
    "color": "Red"
  }
}
```

`POST /api/v1/cart/external/add` body:

```json
{
  "external_product_id": "EXT-001",
  "product_name": "External Product",
  "product_image": "https://...",
  "price": 999,
  "quantity": 1,
  "options": {}
}
```

`PUT /api/v1/cart/items/{itemId}` body:

```json
{
  "quantity": 3
}
```

`POST /api/v1/checkout` body:

```json
{
  "name": "John Doe",
  "phone": "01700000000",
  "address": "Dhaka",
  "area": 1,
  "district": "Dhaka",
  "payment_method": "cod",
  "email": "john@example.com",
  "note": "Call before delivery"
}
```

Validation rules (checkout):

- `name` required
- `phone` required
- `address` required
- `area` nullable integer exists in `shipping_charges`
- `district` nullable string
- `payment_method` in `cod|bkash|shurjopay`

## 8) Admin API (`/api/admin`) - Module Summary

All routes below (except login + refresh-token) require `Admin JWT`.

### 8.1 Admin Auth + Identity

- `POST /api/admin/login`
- `POST /api/admin/refresh-token`
- `POST /api/admin/logout`
- `GET /api/admin/me`

### 8.2 Dashboard and Support Data

- `GET /api/admin/dashboard`
- `GET /api/admin/users`
- `GET /api/admin/reports/orders`

`GET /api/admin/reports/orders` query:

- `keyword`
- `user_id`
- `start_date`
- `end_date`
- `per_page`

### 8.3 Product Module

Routes:

- `GET /api/admin/products`
- `GET /api/admin/products/filters`
- `GET /api/admin/products/{id}`
- `POST /api/admin/products`
- `PUT /api/admin/products/{id}`
- `POST /api/admin/products/update-status`
- `DELETE /api/admin/products/delete`

List query (`GET /products`):

- `keyword`, `category_id`, `subcategory_id`, `brand_id`, `status`, `sort_by`, `sort_order`, `per_page`

Create body important fields:

- `name`, `category_id`, `purchase_price`, `selling_price` required
- optional: `brand_id`, `product_code`, `old_price`, `stock`, `description`, `status`, `topsale`, `feature`, `view_home`, `colors[]`, `sizes[]`, `image(file)`

Status update body:

```json
{
  "product_ids": [1, 2],
  "status": 1
}
```

Delete body:

```json
{
  "product_ids": [1, 2]
}
```

### 8.4 Category / Subcategory / Brand / Color / Size

Category routes:

- `GET /api/admin/categories`
- `GET /api/admin/categories/{id}`
- `POST /api/admin/categories`
- `PUT /api/admin/categories/{id}`
- `POST /api/admin/categories/update-status`
- `POST /api/admin/categories/toggle-show-home`
- `DELETE /api/admin/categories/delete`

Subcategory routes:

- `GET /api/admin/subcategories`
- `POST /api/admin/subcategories`
- `PUT /api/admin/subcategories/{id}`
- `POST /api/admin/subcategories/update-status`
- `DELETE /api/admin/subcategories/delete`

Brand routes:

- `GET /api/admin/brands`
- `POST /api/admin/brands`
- `PUT /api/admin/brands/{id}`
- `POST /api/admin/brands/update-status`
- `DELETE /api/admin/brands/delete`

Color routes:

- `GET /api/admin/colors`
- `POST /api/admin/colors`
- `PUT /api/admin/colors/{id}`
- `POST /api/admin/colors/update-status`
- `DELETE /api/admin/colors/delete`

Size routes:

- `GET /api/admin/sizes`
- `POST /api/admin/sizes`
- `PUT /api/admin/sizes/{id}`
- `POST /api/admin/sizes/update-status`
- `DELETE /api/admin/sizes/delete`

Common list query in these modules:

- `keyword`
- `status` (where supported)
- `per_page`

Common status toggle payloads:

- Categories: `{ "category_ids": [...], "status": 0|1 }`
- Subcategories: `{ "subcategory_ids": [...], "status": 0|1 }`
- Brands: `{ "brand_ids": [...], "status": 0|1 }`
- Colors: `{ "color_ids": [...], "status": 0|1 }`
- Sizes: `{ "size_ids": [...], "status": 0|1 }`

Special category endpoint:

```json
POST /api/admin/categories/toggle-show-home
{
  "category_id": 5,
  "show_home": 1
}
```

Note: সর্বোচ্চ 3 category `show_home=1` হতে পারবে.

### 8.5 Orders

Routes:

- `GET /api/admin/orders/{status}` (`status`: `all|pending|processing|confirmed|delivered|cancelled|complete`)
- `GET /api/admin/orders/detail/{id}`
- `POST /api/admin/orders/update-status`
- `POST /api/admin/orders/assign-user`
- `DELETE /api/admin/orders/delete`
- `GET /api/admin/orders/statistics/all`
- `POST /api/admin/orders/courier/steadfast`
- `POST /api/admin/orders/courier/pathao`
- `POST /api/admin/orders/print`
- `GET /api/admin/orders/invoice/{invoiceId}`

Order list query:

- `keyword`
- `start_date`
- `end_date`
- `per_page`

Bulk action payload examples:

```json
{ "order_ids": [101, 102], "status": 4 }
```

```json
{ "order_ids": [101, 102], "user_id": 3 }
```

```json
{ "order_ids": [101, 102] }
```

### 8.6 Incomplete Orders

Routes:

- `GET /api/admin/incomplete-orders`
- `GET /api/admin/incomplete-orders/meta`
- `GET /api/admin/incomplete-orders/{id}`
- `POST /api/admin/incomplete-orders`
- `PUT /api/admin/incomplete-orders/{id}`
- `DELETE /api/admin/incomplete-orders/{id}`
- `POST /api/admin/incomplete-orders/update-qty`
- `POST /api/admin/incomplete-orders/update-shipping`
- `POST /api/admin/incomplete-orders/{id}/create-order`

Important payloads:

`update-qty`:

```json
{
  "order_id": 10,
  "row_id": "abcd123",
  "qty": 2
}
```

`update-shipping`:

```json
{
  "order_id": 10,
  "shipping_charge_id": 3
}
```

`create-order`:

```json
{
  "name": "Customer",
  "phone": "01700000000",
  "address": "Dhaka",
  "area": 1,
  "district": "Dhaka",
  "email": "optional@example.com",
  "note": "optional"
}
```

### 8.7 Reviews

Routes:

- `GET /api/admin/reviews`
- `GET /api/admin/reviews/pending`
- `GET /api/admin/reviews/meta`
- `POST /api/admin/reviews`
- `PUT /api/admin/reviews/{id}`
- `POST /api/admin/reviews/{id}/activate`
- `POST /api/admin/reviews/{id}/deactivate`
- `DELETE /api/admin/reviews/{id}`

Store body fields:

- `customer_id`, `ratting`, `review`, `product_id`, `status`

Update body fields:

- `name`, `email`, `ratting`, `review`, `product_id`, optional `status`

### 8.8 CMS / Settings / Contacts

General settings:

- `GET /api/admin/settings`
- `POST /api/admin/settings`
- `PUT /api/admin/settings/{id}`
- `POST /api/admin/settings/update-status`
- `DELETE /api/admin/settings/delete`

For `POST/PUT settings`, use `multipart/form-data` for files:

- `white_logo` (required on create)
- `dark_logo` (required on create)
- `favicon` (required on create)

Pages:

- `GET /api/admin/pages`
- `POST /api/admin/pages`
- `PUT /api/admin/pages/{id}`
- `POST /api/admin/pages/update-status`
- `DELETE /api/admin/pages/delete`

Contacts:

- `GET /api/admin/contacts`
- `POST /api/admin/contacts`
- `PUT /api/admin/contacts/{id}`
- `POST /api/admin/contacts/update-status`
- `DELETE /api/admin/contacts/delete`

Note: `PUT /api/admin/contacts/{id}` বর্তমানে validation-এ `hotmail` field required (code অনুযায়ী).

### 8.9 Shipping Charges and Order Statuses

Shipping charges:

- `GET /api/admin/shipping-charges`
- `POST /api/admin/shipping-charges`
- `PUT /api/admin/shipping-charges/{id}`
- `POST /api/admin/shipping-charges/update-status`
- `DELETE /api/admin/shipping-charges/delete`

Order statuses:

- `GET /api/admin/order-statuses`
- `POST /api/admin/order-statuses`
- `PUT /api/admin/order-statuses/{id}`
- `POST /api/admin/order-statuses/update-status`
- `DELETE /api/admin/order-statuses/delete`

`ids/status` pattern used in both modules:

```json
{
  "ids": [1, 2],
  "status": 1
}
```

### 8.10 Integrations

- `GET /api/admin/integrations/payment`
- `PUT /api/admin/integrations/payment/{id}`
- `GET /api/admin/integrations/sms`
- `PUT /api/admin/integrations/sms/{id}`
- `GET /api/admin/integrations/courier`
- `PUT /api/admin/integrations/courier/{id}`
- `POST /api/admin/integrations/pathao-token`

Pathao token payload:

```json
{
  "client_id": "...",
  "client_secret": "...",
  "username": "...",
  "password": "..."
}
```

### 8.11 Marketing Modules

Banner categories:

- `GET /api/admin/banner-categories`
- `POST /api/admin/banner-categories`
- `PUT /api/admin/banner-categories/{id}`
- `POST /api/admin/banner-categories/update-status`
- `DELETE /api/admin/banner-categories/delete`

Banners:

- `GET /api/admin/banners`
- `GET /api/admin/banners/meta`
- `POST /api/admin/banners`
- `POST /api/admin/banners/{id}`
- `POST /api/admin/banners/update-status`
- `DELETE /api/admin/banners/delete`

`POST /api/admin/banners` uses `multipart/form-data`:

- required: `category_id`, `link`, `status`, `image(file)`
- optional: `image_two(file)`

Pixels:

- `GET /api/admin/pixels`
- `POST /api/admin/pixels`
- `PUT /api/admin/pixels/{id}`
- `POST /api/admin/pixels/update-status`
- `DELETE /api/admin/pixels/delete`

Tag managers:

- `GET /api/admin/tag-managers`
- `POST /api/admin/tag-managers`
- `PUT /api/admin/tag-managers/{id}`
- `POST /api/admin/tag-managers/update-status`
- `DELETE /api/admin/tag-managers/delete`

## 9) Example cURL Snippets

Customer login:

```bash
curl -X POST "http://localhost:8000/api/v1/login" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"phone":"01700000000","password":"secret123"}'
```

Get cart:

```bash
curl -X GET "http://localhost:8000/api/v1/cart" \
  -H "Accept: application/json"
```

Add to cart with cart id:

```bash
curl -X POST "http://localhost:8000/api/v1/cart/add" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-Cart-ID: <cart_id>" \
  -d '{"product_id":1,"quantity":1}'
```

Admin login:

```bash
curl -X POST "http://localhost:8000/api/admin/login" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"secret123"}'
```

Admin product list:

```bash
curl -X GET "http://localhost:8000/api/admin/products?keyword=shoe&per_page=20" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <admin_access_token>"
```

## 10) Complete Route Map (All APIs)

Source: `php artisan route:list --path=api --json`

| Method | Endpoint | Auth | Action |
| --- | --- | --- | --- |
| GET|HEAD | /api/admin/banner-categories | Admin JWT | `index` |
| POST | /api/admin/banner-categories | Admin JWT | `store` |
| DELETE | /api/admin/banner-categories/delete | Admin JWT | `destroy` |
| POST | /api/admin/banner-categories/update-status | Admin JWT | `updateStatus` |
| PUT | /api/admin/banner-categories/{id} | Admin JWT | `update` |
| GET|HEAD | /api/admin/banners | Admin JWT | `index` |
| POST | /api/admin/banners | Admin JWT | `store` |
| DELETE | /api/admin/banners/delete | Admin JWT | `destroy` |
| GET|HEAD | /api/admin/banners/meta | Admin JWT | `meta` |
| POST | /api/admin/banners/update-status | Admin JWT | `updateStatus` |
| POST | /api/admin/banners/{id} | Admin JWT | `update` |
| GET|HEAD | /api/admin/brands | Admin JWT | `index` |
| POST | /api/admin/brands | Admin JWT | `store` |
| DELETE | /api/admin/brands/delete | Admin JWT | `destroy` |
| POST | /api/admin/brands/update-status | Admin JWT | `updateStatus` |
| PUT | /api/admin/brands/{id} | Admin JWT | `update` |
| GET|HEAD | /api/admin/categories | Admin JWT | `index` |
| POST | /api/admin/categories | Admin JWT | `store` |
| DELETE | /api/admin/categories/delete | Admin JWT | `destroy` |
| POST | /api/admin/categories/toggle-show-home | Admin JWT | `toggleShowHome` |
| POST | /api/admin/categories/update-status | Admin JWT | `updateStatus` |
| GET|HEAD | /api/admin/categories/{id} | Admin JWT | `show` |
| PUT | /api/admin/categories/{id} | Admin JWT | `update` |
| GET|HEAD | /api/admin/colors | Admin JWT | `index` |
| POST | /api/admin/colors | Admin JWT | `store` |
| DELETE | /api/admin/colors/delete | Admin JWT | `destroy` |
| POST | /api/admin/colors/update-status | Admin JWT | `updateStatus` |
| PUT | /api/admin/colors/{id} | Admin JWT | `update` |
| GET|HEAD | /api/admin/contacts | Admin JWT | `index` |
| POST | /api/admin/contacts | Admin JWT | `store` |
| DELETE | /api/admin/contacts/delete | Admin JWT | `destroy` |
| POST | /api/admin/contacts/update-status | Admin JWT | `updateStatus` |
| PUT | /api/admin/contacts/{id} | Admin JWT | `update` |
| GET|HEAD | /api/admin/dashboard | Admin JWT | `index` |
| GET|HEAD | /api/admin/incomplete-orders | Admin JWT | `index` |
| POST | /api/admin/incomplete-orders | Admin JWT | `store` |
| GET|HEAD | /api/admin/incomplete-orders/meta | Admin JWT | `meta` |
| POST | /api/admin/incomplete-orders/update-qty | Admin JWT | `updateQty` |
| POST | /api/admin/incomplete-orders/update-shipping | Admin JWT | `updateShipping` |
| DELETE | /api/admin/incomplete-orders/{id} | Admin JWT | `destroy` |
| GET|HEAD | /api/admin/incomplete-orders/{id} | Admin JWT | `show` |
| PUT | /api/admin/incomplete-orders/{id} | Admin JWT | `update` |
| POST | /api/admin/incomplete-orders/{id}/create-order | Admin JWT | `createOrder` |
| GET|HEAD | /api/admin/integrations/courier | Admin JWT | `courierIndex` |
| PUT | /api/admin/integrations/courier/{id} | Admin JWT | `courierUpdate` |
| POST | /api/admin/integrations/pathao-token | Admin JWT | `getPathaoToken` |
| GET|HEAD | /api/admin/integrations/payment | Admin JWT | `paymentIndex` |
| PUT | /api/admin/integrations/payment/{id} | Admin JWT | `paymentUpdate` |
| GET|HEAD | /api/admin/integrations/sms | Admin JWT | `smsIndex` |
| PUT | /api/admin/integrations/sms/{id} | Admin JWT | `smsUpdate` |
| POST | /api/admin/login | Public | `login` |
| POST | /api/admin/logout | Admin JWT | `logout` |
| GET|HEAD | /api/admin/me | Admin JWT | `me` |
| GET|HEAD | /api/admin/order-statuses | Admin JWT | `index` |
| POST | /api/admin/order-statuses | Admin JWT | `store` |
| DELETE | /api/admin/order-statuses/delete | Admin JWT | `destroy` |
| POST | /api/admin/order-statuses/update-status | Admin JWT | `updateStatus` |
| PUT | /api/admin/order-statuses/{id} | Admin JWT | `update` |
| POST | /api/admin/orders/assign-user | Admin JWT | `assignUser` |
| POST | /api/admin/orders/courier/pathao | Admin JWT | `sendToPathao` |
| POST | /api/admin/orders/courier/steadfast | Admin JWT | `sendToSteadfast` |
| DELETE | /api/admin/orders/delete | Admin JWT | `destroy` |
| GET|HEAD | /api/admin/orders/detail/{id} | Admin JWT | `show` |
| GET|HEAD | /api/admin/orders/invoice/{invoiceId} | Admin JWT | `invoice` |
| POST | /api/admin/orders/print | Admin JWT | `printOrders` |
| GET|HEAD | /api/admin/orders/statistics/all | Admin JWT | `statistics` |
| POST | /api/admin/orders/update-status | Admin JWT | `updateStatus` |
| GET|HEAD | /api/admin/orders/{status} | Admin JWT | `index` |
| GET|HEAD | /api/admin/pages | Admin JWT | `index` |
| POST | /api/admin/pages | Admin JWT | `store` |
| DELETE | /api/admin/pages/delete | Admin JWT | `destroy` |
| POST | /api/admin/pages/update-status | Admin JWT | `updateStatus` |
| PUT | /api/admin/pages/{id} | Admin JWT | `update` |
| GET|HEAD | /api/admin/pixels | Admin JWT | `index` |
| POST | /api/admin/pixels | Admin JWT | `store` |
| DELETE | /api/admin/pixels/delete | Admin JWT | `destroy` |
| POST | /api/admin/pixels/update-status | Admin JWT | `updateStatus` |
| PUT | /api/admin/pixels/{id} | Admin JWT | `update` |
| GET|HEAD | /api/admin/products | Admin JWT | `index` |
| POST | /api/admin/products | Admin JWT | `store` |
| DELETE | /api/admin/products/delete | Admin JWT | `destroy` |
| GET|HEAD | /api/admin/products/filters | Admin JWT | `filters` |
| POST | /api/admin/products/update-status | Admin JWT | `updateStatus` |
| GET|HEAD | /api/admin/products/{id} | Admin JWT | `show` |
| PUT | /api/admin/products/{id} | Admin JWT | `update` |
| POST | /api/admin/refresh-token | Public | `refresh` |
| GET|HEAD | /api/admin/reports/orders | Admin JWT | `orderReport` |
| GET|HEAD | /api/admin/reviews | Admin JWT | `index` |
| POST | /api/admin/reviews | Admin JWT | `store` |
| GET|HEAD | /api/admin/reviews/meta | Admin JWT | `meta` |
| GET|HEAD | /api/admin/reviews/pending | Admin JWT | `pending` |
| DELETE | /api/admin/reviews/{id} | Admin JWT | `destroy` |
| PUT | /api/admin/reviews/{id} | Admin JWT | `update` |
| POST | /api/admin/reviews/{id}/activate | Admin JWT | `activate` |
| POST | /api/admin/reviews/{id}/deactivate | Admin JWT | `deactivate` |
| GET|HEAD | /api/admin/settings | Admin JWT | `index` |
| POST | /api/admin/settings | Admin JWT | `store` |
| DELETE | /api/admin/settings/delete | Admin JWT | `destroy` |
| POST | /api/admin/settings/update-status | Admin JWT | `updateStatus` |
| PUT | /api/admin/settings/{id} | Admin JWT | `update` |
| GET|HEAD | /api/admin/shipping-charges | Admin JWT | `index` |
| POST | /api/admin/shipping-charges | Admin JWT | `store` |
| DELETE | /api/admin/shipping-charges/delete | Admin JWT | `destroy` |
| POST | /api/admin/shipping-charges/update-status | Admin JWT | `updateStatus` |
| PUT | /api/admin/shipping-charges/{id} | Admin JWT | `update` |
| GET|HEAD | /api/admin/sizes | Admin JWT | `index` |
| POST | /api/admin/sizes | Admin JWT | `store` |
| DELETE | /api/admin/sizes/delete | Admin JWT | `destroy` |
| POST | /api/admin/sizes/update-status | Admin JWT | `updateStatus` |
| PUT | /api/admin/sizes/{id} | Admin JWT | `update` |
| GET|HEAD | /api/admin/subcategories | Admin JWT | `index` |
| POST | /api/admin/subcategories | Admin JWT | `store` |
| DELETE | /api/admin/subcategories/delete | Admin JWT | `destroy` |
| POST | /api/admin/subcategories/update-status | Admin JWT | `updateStatus` |
| PUT | /api/admin/subcategories/{id} | Admin JWT | `update` |
| GET|HEAD | /api/admin/tag-managers | Admin JWT | `index` |
| POST | /api/admin/tag-managers | Admin JWT | `store` |
| DELETE | /api/admin/tag-managers/delete | Admin JWT | `destroy` |
| POST | /api/admin/tag-managers/update-status | Admin JWT | `updateStatus` |
| PUT | /api/admin/tag-managers/{id} | Admin JWT | `update` |
| GET|HEAD | /api/admin/users | Admin JWT | `index` |
| DELETE | /api/v1/cart | Optional Customer JWT | `clearCart` |
| GET|HEAD | /api/v1/cart | Optional Customer JWT | `getCart` |
| POST | /api/v1/cart/add | Optional Customer JWT | `addToCart` |
| POST | /api/v1/cart/external/add | Optional Customer JWT | `addExternal` |
| DELETE | /api/v1/cart/items/{itemId} | Optional Customer JWT | `removeItem` |
| PUT | /api/v1/cart/items/{itemId} | Optional Customer JWT | `updateItem` |
| POST | /api/v1/checkout | Optional Customer JWT | `placeOrder` |
| GET|HEAD | /api/v1/external/category-products | Public | `categoryProducts` |
| GET|HEAD | /api/v1/external/category/{slug} | Public | `categoryProductsBySlug` |
| GET|HEAD | /api/v1/external/featured-categories | Public | `featuredCategories` |
| GET|HEAD | /api/v1/external/hot-deals | Public | `hotDeals` |
| GET|HEAD | /api/v1/external/menu-categories | Public | `menuCategories` |
| GET|HEAD | /api/v1/external/product/{slug} | Public | `productDetails` |
| GET|HEAD | /api/v1/external/search | Public | `searchProducts` |
| GET|HEAD | /api/v1/external/top-sell | Public | `topSell` |
| GET|HEAD | /api/v1/home-categories | Public | `homeCategories` |
| GET|HEAD | /api/v1/home-data | Public | `home` |
| POST | /api/v1/login | Public | `login` |
| POST | /api/v1/logout | Customer JWT | `logout` |
| GET|HEAD | /api/v1/pages/{slug} | Public | `show` |
| GET|HEAD | /api/v1/products | Public | `index` |
| GET|HEAD | /api/v1/products/hot-deals | Public | `hotDeals` |
| GET|HEAD | /api/v1/products/latest | Public | `latest` |
| GET|HEAD | /api/v1/products/new-arrivals | Public | `newArrivals` |
| GET|HEAD | /api/v1/products/{slug} | Public | `details` |
| POST | /api/v1/refresh-token | Public | `refresh` |
| POST | /api/v1/register | Public | `register` |
| GET|HEAD | /api/v1/settings | Public | `show` |
| GET|HEAD | /api/v1/shipping-charges | Public | `index` |
| GET|HEAD | /api/v1/site-data | Public | `index` |
| GET|HEAD | /api/v1/user | Customer JWT | `user` |

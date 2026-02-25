# Laravel API JWT Auth Reference

## Config

- `config/jwt.php`
- `.env` keys:
  - `JWT_SECRET`
  - `JWT_ISSUER`
  - `JWT_ACCESS_TTL`
  - `JWT_REFRESH_TTL`
  - `JWT_CLOCK_SKEW`

## Customer endpoints

- `POST /api/v1/register`
- `POST /api/v1/login`
- `POST /api/v1/refresh-token`
- `GET /api/v1/user` (requires `jwt:customer`)
- `POST /api/v1/logout` (requires `jwt:customer`)

## Admin endpoints

- `POST /api/admin/login`
- `POST /api/admin/refresh-token`
- `GET /api/admin/me` (requires `jwt:admin`)
- `POST /api/admin/logout` (requires `jwt:admin`)

## Middleware

- Required auth: `jwt:{subjectType}`
  - `jwt:customer`
  - `jwt:admin`
- Optional auth: `jwt.optional:{subjectType}`

## Token model

- Access + refresh token pair (HS256)
- Refresh token rotation on refresh endpoint
- Revocation via cache blacklist (`jti`)

## Error contract

Standard API error payload:

```json
{
  "success": false,
  "message": "...",
  "errors": {}
}
```

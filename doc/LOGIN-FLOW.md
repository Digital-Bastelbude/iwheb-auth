# Login Flow

Passwordless auth with 6-digit codes and API key access control.

## Steps

1. **Login** - Request session with email
2. **Validate** - Submit 6-digit code
3. **Access** - Use session for protected routes

**Session Isolation:** Bound to creating API key.

## Auth Header

```bash
X-API-Key: your-key
# OR
Authorization: ApiKey your-key
```

## 1. Login (`POST /login`)

**Request:**
```json
{"email": "dXNlckBleGFtcGxlLmNvbQ"}
```
*Base64 URL-safe, no padding*

**Response:**
```json
{
  "session_id": "abc123",
  "code": "123456",
  "code_expires_at": "2025-10-30T10:10:00+00:00",
  "session_expires_at": "2025-10-30T10:45:00+00:00"
}
```

**Errors:** `404` (not found/invalid key), `400` (bad format), `500` (Webling error)

## 2. Validate (`POST /validate/{id}`)

**Request:**
```json
{"code": "123456"}
```

**Response:**
```json
{
  "validated": true,
  "session_expires_at": "2025-10-30T10:45:00+00:00"
}
```

**Errors:** `404` (not found/wrong key/invalid code), `400` (expired)

## 3. Check (`GET /session/check/{id}`)

```json
{"active": true, "validated": true, "expires_at": "2025-10-30T10:45:00+00:00"}
```

## 4. Refresh (`POST /session/touch/{id}`)

Extends 30min. Returns new session ID (rotation).

```json
{"session_id": "new-abc123", "expires_at": "2025-10-30T11:15:00+00:00"}
```

## 5. Logout (`POST /session/logout/{id}`)

```json
{"success": true}
```

## Protected Routes

**`GET /user/{id}/info`** (requires `user_info`)
```json
{"firstName": "John", "lastName": "Doe", "email": "john@example.com"}
```

**`GET /user/{id}/token`** (requires `user_token`)
```json
{"token": "encrypted-token"}
```

## Details

- **Duration:** 30min
- **Code:** 6 digits, 5min validity
- **Validation:** Required before protected routes
- **Rotation:** New ID on refresh, preserves API key

## Errors

All unauthorized → `404` (prevents info leakage)

## Example

```bash
# Login
curl -X POST http://localhost:8080/login \
  -H "X-API-Key: key" -H "Content-Type: application/json" \
  -d '{"email":"dXNlckBleGFtcGxlLmNvbQ"}'

# Validate
curl -X POST http://localhost:8080/validate/abc123 \
  -H "X-API-Key: key" -H "Content-Type: application/json" \
  -d '{"code":"123456"}'

# Get info
curl http://localhost:8080/user/abc123/info -H "X-API-Key: key"

# Refresh
curl -X POST http://localhost:8080/session/touch/abc123 -H "X-API-Key: key"

# Logout
curl -X POST http://localhost:8080/session/logout/abc123 -H "X-API-Key: key"
```

## Security

- API key validation
- Per-key session isolation
- 5min code expiry
- 30min session expiry
- Session rotation
- XChaCha20-Poly1305 encryption
- Error masking (404)
- Granular permissions

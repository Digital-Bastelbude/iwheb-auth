# API Cheatsheet

Quick reference for all available API endpoints with curl examples.

## Interactive Test Script

For easy testing, use the interactive shell script:

```bash
tests/test-api.sh
```

The script provides an interactive menu for all endpoints with automatic parameter handling.

## Manual curl Examples

If you prefer manual testing, here are the curl commands:

## Prerequisites

- **Base URL**: `http://localhost:8080` (or your configured domain)
- **API Key**: Required for all requests via `X-API-Key` header
- **Content-Type**: `application/json` for POST requests

## Authentication Flow

### 1. Login (Start Authentication)

Initiate login process by email. Creates session and sends authentication code via email.

```bash
curl -X POST "http://localhost:8080/login" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '{
    "email": "dGVzdEBleGFtcGxlLmNvbQ=="
  }'
```

**Request Body:**
- `email`: Base64-encoded email address

**Response:**
```json
{
  "session_id": "abc123def456",
  "expires_at": "2025-11-02T01:00:00+00:00",
  "code_expires_at": "2025-11-02T00:10:00+00:00"
}
```

### 2. Validate Authentication Code

Complete authentication by providing the code received via email.

```bash
curl -X POST "http://localhost:8080/validate/abc123def456" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '{
    "code": "123456"
  }'
```

**Request Body:**
- `code`: 6-digit authentication code from email

**Response:**
```json
{
  "session_id": "abc123def456",
  "validated": true,
  "expires_at": "2025-11-02T01:00:00+00:00"
}
```

## Session Management

### Session Management Notes

**⚠️ Important Session Behavior:**
- **One Session Per User/API-Key**: Only one active session allowed per user and API key combination
- **Login Replaces All**: New login deletes ALL existing user sessions (across all API keys)
- **Logout Deletes All**: Logout deletes all sessions for the user/API-key combination
- **Delegation Replaces**: Creating delegated sessions deletes existing sessions for target API key

### 3. Check Session Status

Verify if a session is active and validated.

```bash
curl -X GET "http://localhost:8080/session/check/abc123def456" \
  -H "X-API-Key: YOUR_API_KEY"
```

**Response:**
```json
{
  "session_id": "abc123def456",
  "expires_at": "2025-11-02T01:00:00+00:00",
  "active": true
}
```

### 4. Touch Session (Extend Lifetime)

Extend session lifetime by updating last activity.

```bash
curl -X POST "http://localhost:8080/session/touch/abc123def456" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '{}'
```

**Response:**
```json
{
  "session_id": "abc123def456",
  "expires_at": "2025-11-02T02:00:00+00:00",
  "touched": true
}
```

### 5. Create Delegated Session

Create a child session with specific API key permissions.

```bash
curl -X POST "http://localhost:8080/session/delegate/abc123def456" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '{
    "target_api_key": "TARGET_API_KEY"
  }'
```

**Request Body:**
- `target_api_key`: API key that will have access to the delegated session

**Response:**
```json
{
  "session_id": "xyz789uvw012",
  "parent_session_id": "abc123def456",
  "expires_at": "2025-11-02T01:00:00+00:00",
  "delegated": true
}
```

### 6. Logout

Terminate session and all child sessions.

```bash
curl -X POST "http://localhost:8080/session/logout/abc123def456" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '{}'
```

**Response:**
```json
{
  "session_id": "abc123def456",
  "logged_out": true
}
```

## User Information

### 7. Get User Info

Retrieve user information from Webling.

```bash
curl -X GET "http://localhost:8080/user/abc123def456/info" \
  -H "X-API-Key: YOUR_API_KEY"
```

**Response:**
```json
{
  "user_id": 12345,
  "email": "test@example.com",
  "firstname": "John",
  "lastname": "Doe"
}
```

### 8. Get User Token

Retrieve encrypted user token for external use.

```bash
curl -X GET "http://localhost:8080/user/abc123def456/token" \
  -H "X-API-Key: YOUR_API_KEY"
```

**Response:**
```json
{
  "token": "encrypted_user_token_here"
}
```

## Error Responses

All endpoints may return these common error responses:

**401 Unauthorized (appears as 404 for security):**
```json
{"error": "Not found"}
```

**400 Bad Request:**
```json
{"error": "Invalid input"}
```

**404 Not Found:**
```json
{"error": "Not found"}
```

**500 Internal Server Error (appears as 404 for security):**
```json
{"error": "Not found"}
```

## Authentication Flow Example

Complete authentication workflow:

```bash
# 1. Start login
RESPONSE=$(curl -s -X POST "http://localhost:8080/login" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '{"email": "dGVzdEBleGFtcGxlLmNvbQ=="}')

# 2. Extract session_id
SESSION_ID=$(echo $RESPONSE | jq -r '.session_id')

# 3. Validate with code from email
curl -X POST "http://localhost:8080/validate/$SESSION_ID" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '{"code": "123456"}'

# 4. Check session status
curl -X GET "http://localhost:8080/session/check/$SESSION_ID" \
  -H "X-API-Key: YOUR_API_KEY"

# 5. Get user info
curl -X GET "http://localhost:8080/user/$SESSION_ID/info" \
  -H "X-API-Key: YOUR_API_KEY"

# 6. Logout when done
curl -X POST "http://localhost:8080/session/logout/$SESSION_ID" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_API_KEY" \
  -d '{}'
```

## Notes

- **Email Encoding**: Emails must be base64-encoded for the `/login` endpoint
- **Session IDs**: Always alphanumeric lowercase (except delegated sessions which allow uppercase)
- **API Keys**: Must be configured in `config/.secrets.php` with appropriate route permissions
- **Security**: All error responses return 404 status for security (failed requests don't reveal system details)
- **Rate Limiting**: API keys have configurable rate limits per time window
- **Session Lifecycle**: Child sessions are automatically deleted when parent session expires or is logged out
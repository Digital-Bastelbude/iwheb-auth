# User Login Flow - iwheb-auth

## Overview

The system implements a secure, passwordless user login with code-based two-factor authentication.

## Login Flow

### 1. Login Request (`POST /login`)

**Request:**
```json
{
  "email": "dXNlckBleGFtcGxlLmNvbQ" 
}
```
*Note: Email must be Base64 URL-safe encoded (no padding)*

**Process:**
1. Email is Base64-decoded
2. System checks in Webling if user with this email exists
3. If user found:
   - Webling User-ID is encrypted (UidEncryptor) → Token
   - Token is checked: does user exist in DB?
     - **No**: New user is created (`createUser`)
   - Session is started (`createSession`), which generates a new 6-digit code
4. If user NOT found: HTTP 404

**Response (Success):**
```json
{
  "session_id": "abc123...",
  "code": "123456",
  "code_expires_at": "2025-10-30T10:10:00+00:00",
  "session_expires_at": "2025-10-30T10:45:00+00:00"
}
```

**Response (Error):**
```json
{
  "error": "User not found"
}
```
*HTTP Status: 404*

---

### 2. Code Validation (`POST /validate`)

**Request:**
```json
{
  "session_id": "abc123...",
  "code": "123456"
}
```

**Process:**
1. Session is loaded by `session_id`
2. Code is validated (`validateCode`):
   - Code must match session's code
   - Code must not be expired
3. On successful validation:
   - Session is marked as validated (`validated = true`)
   - User's activity timestamp is updated (`touchUser`)
   - New session-ID is generated (session rotation)
   - Used code is replaced with a new one (security)

**Response (Success):**
```json
{
  "session_id": "xyz789...",
  "validated": true
}
```

**Response (Error):**
```json
{
  "error": "User not found"
}
```
*HTTP Status: 404*

---

### 3. Get User Info (`POST /user/info`)

**Request:**
```json
{
  "session_id": "xyz789..."
}
```

**Process:**
1. Session is checked for validity (`isSessionActive`)
   - Session must exist
   - Session must not be expired
2. User token is decrypted to get Webling user ID
3. User data is fetched from Webling API
4. Session is refreshed (`touchUser`) and user's last activity updated
5. User data and new session ID are returned

**Response (Success):**
```json
{
  "session_id": "newxyz123...",
  "user": {
    "id": 123,
    "properties": {
      "firstName": "John",
      "lastName": "Doe",
      "E-Mail": "john@example.com",
      ...
    }
  }
}
```

**Response (Error):**
```json
{
  "error": "Not found"
}
```
*HTTP Status: 404*

---

## Security Features

### ✅ Implemented Security Measures:

1. **Passwordless**: No passwords to manage or store

2. **Code-based 2FA**:
   - 6-digit numeric code
   - Time-limited validity (default: 5 minutes)
   - Single-use (regenerated after validation)

3. **Session Rotation**:
   - New session-ID on every activity (`touchUser`)
   - Prevents session-fixation attacks
   - Sessions have expiration time (default: 30 minutes)

4. **Token Encryption**:
   - Webling User-IDs are encrypted with UidEncryptor
   - AEAD (XChaCha20-Poly1305) encryption
   - URL-safe Base64 encoding
   - Tamper-detection through auth-tag

5. **Session Validation**:
   - Sessions are initially unvalidated (`validated = false`)
   - Only validated after successful code entry
   - Enables distinction between "logged in" and "authenticated"

6. **Foreign Key Constraints**:
   - Sessions are automatically deleted when user is deleted
   - No orphaned sessions in the database

7. **Generic Error Messages**:
   - Routes throw specific exceptions internally (for logging/debugging)
   - All authentication failures mapped to same generic 404 response
   - Prevents information disclosure and enumeration attacks
   - Logs contain detailed exception info for troubleshooting

---

## Error Handling

The system uses a **two-tier exception approach** for security and debugging:

### Internal Exceptions (for logging/debugging)

Routes throw specific exceptions internally:
- `InvalidSessionException` - Session invalid or expired
- `InvalidCodeException` - Code wrong or expired  
- `UserNotFoundException` - User not found in Webling
- `StorageException` - Database errors
- `InvalidInputException` - Missing or malformed parameters

### External Response (security-conscious)

All `NotFoundException` subclasses (`InvalidSessionException`, `InvalidCodeException`, `UserNotFoundException`) are **mapped to the same generic 404 response**:

**Client Errors (400):**
```json
{ "error": "Invalid input" }
```
Returned when required parameters are missing or malformed.

**Not Found (404):**
```json
{ "error": "User not found" }
```
Generic response for all authentication failures. Could mean:
- Invalid/expired session
- Wrong/expired code
- User not found in Webling
- Any other authentication error

**Why generic errors?** Prevents information leakage and enumeration attacks. Logs contain detailed exception names for debugging, but clients only see generic messages.

---

## Usage

### Example: Complete Login Flow

```bash
# 1. Login Request
# Base64 URL-safe encode email (no padding)
EMAIL=$(echo -n "user@example.com" | base64 | tr '+/' '-_' | tr -d '=')
RESPONSE=$(curl -X POST https://api.example.com/login \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$EMAIL\"}")

SESSION_ID=$(echo $RESPONSE | jq -r '.session_id')
CODE=$(echo $RESPONSE | jq -r '.code')

echo "Your code: $CODE"

# 2. User enters code (e.g., from email)
# Then validate code:

RESPONSE=$(curl -X POST https://api.example.com/validate \
  -H "Content-Type: application/json" \
  -d "{\"session_id\":\"$SESSION_ID\",\"code\":\"$CODE\"}")

NEW_SESSION_ID=$(echo $RESPONSE | jq -r '.session_id')
echo "Authenticated! New session ID: $NEW_SESSION_ID"

# 3. Get user information
RESPONSE=$(curl -X POST https://api.example.com/user/info \
  -H "Content-Type: application/json" \
  -d "{\"session_id\":\"$NEW_SESSION_ID\"}")

USER_DATA=$(echo $RESPONSE | jq -r '.user')
LATEST_SESSION_ID=$(echo $RESPONSE | jq -r '.session_id')

echo "User data: $USER_DATA"
echo "Latest session ID: $LATEST_SESSION_ID"

# 4. Use latest session ID for further authenticated requests
```

---

## Environment Variables

Required for the routes:

```bash
# Webling Configuration
WEBLING_DOMAIN=demo              # Webling subdomain
WEBLING_API_KEY=your-api-key     # Webling API Key

# Encryption Key (32 bytes, base64-encoded)
ENCRYPTION_KEY=base64:AbCdEf1234567890...==
```

Generate encryption key:
```bash
php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
```

---

## Database Structure

### Users Table
```sql
CREATE TABLE users (
    token TEXT PRIMARY KEY,           -- Encrypted Webling User ID
    last_activity_at TEXT NOT NULL    -- ISO 8601 timestamp
);
```

### Sessions Table
```sql
CREATE TABLE sessions (
    session_id TEXT PRIMARY KEY,          -- 32-char URL-safe random ID
    user_token TEXT NOT NULL,             -- FK to users(token)
    code TEXT NOT NULL,                   -- 6-digit verification code
    code_valid_until TEXT NOT NULL,       -- ISO 8601 timestamp
    expires_at TEXT NOT NULL,             -- ISO 8601 timestamp
    session_duration INTEGER DEFAULT 1800,-- Seconds (30 min)
    validated INTEGER DEFAULT 0,          -- Boolean: code validated?
    created_at TEXT NOT NULL,             -- ISO 8601 timestamp
    FOREIGN KEY (user_token) REFERENCES users(token) ON DELETE CASCADE
);
```

---

## Analysis: Is This Sensible and Secure?

### ✅ **YES, the system is sensible and secure**, because:

1. **2FA without password**: Combination of "something you have" (email access) and "something you know" (code)

2. **Session rotation prevents hijacking**: New session-ID on every activity

3. **Code single-use**: After validation, code is regenerated

4. **Time limiting**: Codes and sessions expire

5. **Encrypted user IDs**: No direct Webling IDs in the DB

6. **Validated flag**: Distinction between "session exists" and "user is authenticated"

### ⚠️ **Recommended Improvements**:

1. **Rate Limiting**: Limit login and validation attempts per IP/email

2. **Code Delivery**: Implement actual email sending for codes

3. **Audit Logging**: Log all login attempts and validations

4. **Brute-Force Protection**: After X failed code entries, lock user/session

5. **Session List**: User should see and terminate all active sessions

6. **Refresh Token**: For longer sessions, implement a refresh mechanism

---

## API Endpoints

| Method | Path | Description |
| Method | Path | Description |
|---------|------|--------------|
| POST | `/login` | Starts login process, returns session_id and code |
| POST | `/validate` | Validates code, marks session as authenticated |
| POST | `/user/info` | Get user information from Webling, refreshes session |

**Future:**
- `GET /session/info` - Get session information
- `POST /session/refresh` - Extend session
- `DELETE /session` - End session (logout)
- `GET /sessions` - Get all active sessions of user

---

## Maintenance

### Cleanup old sessions:

```php
// In cron job or scheduled task
$db = Database::getInstance();

// Delete expired sessions (and their codes)
$deletedSessions = $db->deleteExpiredSessions();

echo "Deleted: {$deletedSessions} expired sessions\n";
```
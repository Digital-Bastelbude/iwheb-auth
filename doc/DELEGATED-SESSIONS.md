# Delegated Sessions

## Overview

App A mit validierter Session kann eine neue Session für App B mit anderem API-Key erstellen. App B erhält sofort nutzbare, bereits validierte Session ohne erneuten Login.

```
App A (api_key_1, validated) → /session/delegate/{id}
                                      ↓
                          App B (api_key_2, pre-validated)
```

**Lifecycle-Binding:** Parent-Session gelöscht/abgelaufen → Child-Sessions ungültig

**Lifecycle-Binding:** Parent-Session gelöscht/abgelaufen → Child-Sessions ungültig

## API

**POST /session/delegate/{session_id}**

Benötigt `delegate_session` Permission.

**Request:**
```json
{
  "target_api_key": "api-key-for-app-b"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "session_id": "xyz789",
    "validated": true,
    "parent_session_id": "abc123",
    "api_key": "api-key-for-app-b",
    "expires_at": "2025-10-31T15:30:00Z"
  }
}
```

**Errors:** `INVALID_INPUT` (400), `INVALID_API_KEY` (400), `FORBIDDEN` (403), `NOT_FOUND` (404)

**Errors:** `INVALID_INPUT` (400), `INVALID_API_KEY` (400), `FORBIDDEN` (403), `NOT_FOUND` (404)

## Sicherheit

### Permission in config/.secrets.php

```php
$API_KEYS = [
    'app-key-1' => [
        'permissions' => ['delegate_session', 'user_info'],
        'name' => 'App A'
    ],
    'app-key-2' => [
        'permissions' => ['user_info'],
        'name' => 'App B'
    ]
];
```

### Regeln

- ✅ Nur API-Keys mit `delegate_session` können delegieren
- ✅ Delegierte Session hat **nur** Rechte des `target_api_key`
- ✅ Parent muss validiert sein
- ✅ Target-API-Key muss existieren
- ✅ Keine Privilege-Escalation möglich

- ✅ Keine Privilege-Escalation möglich

## Beispiel

```bash
# 1. App A: Login + Validierung
curl -X POST http://localhost:8080/login -H "X-API-Key: app-key-1" \
  -d '{"email":"dXNlckBleGFtcGxlLmNvbQ=="}'
# → session_id: abc123

curl -X POST http://localhost:8080/validate/abc123 -H "X-API-Key: app-key-1" \
  -d '{"code":"123456"}'
# → session_id: def456 (validated)

# 2. App A: Delegation an App B
curl -X POST http://localhost:8080/session/delegate/def456 \
  -H "X-API-Key: app-key-1" -H "Content-Type: application/json" \
  -d '{"target_api_key":"app-key-2"}'
# → session_id: xyz789 (pre-validated)

# 3. App B: Sofort nutzbar
curl -X GET http://localhost:8080/session/check/xyz789 -H "X-API-Key: app-key-2"
# → {"success":true, "data":{"active":true}}
```

## Features

- **Cascading Delete:** Parent gelöscht → alle Child-Sessions gelöscht
- **Parent-Validierung:** Parent abgelaufen → Child ungültig
- **Nested Delegation:** Child-Sessions können selbst delegieren
- **API-Key-Isolation:** Jede Session hat eigene Berechtigungen

**Tests:** 8 Unit-Tests (170 gesamt, 615 Assertions)
```bash
vendor/bin/phpunit --filter testDelegatedSession
```

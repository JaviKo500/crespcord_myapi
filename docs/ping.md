## GET /api/v1/ping

Health-check endpoint. Verifies that the module is active and the routing pipeline is working.

**Authentication:** public

**Headers**
| Header | Value |
|--------|-------|
| Content-Type | application/json |

**Request body**

None.

**Success response (200)**
```json
{ "success": true, "data": { "pong": true } }
```

**Possible errors**
| Code | When |
|------|------|
| 405  | Any HTTP method other than GET |

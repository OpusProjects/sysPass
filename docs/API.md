# API

sysPass exposes a **JSON-RPC 2.0** API for programmatic access to accounts, categories,
clients, tags, user groups, and configuration operations.

## Interactive documentation (Swagger UI)

The API is documented with an OpenAPI 3.0 spec served through Swagger UI:

```
http://<your-host>/api/docs/
```

From there you can browse every method, see request/response examples, and use
**Try it out** to make live calls.

## Authentication

Every API request requires an **auth token** passed inside `params.authToken`.

To create a token:

1. Log in to the web UI as an administrator.
2. Go to **Users > Authorizations**.
3. Create a new API authorization — this generates the token.

Some operations (viewing passwords, custom fields, exports) additionally require
the **token password** (`tokenPass`), which is set when creating the authorization.

## Request format

All requests are `POST /api.php` with a JSON body:

```json
{
  "jsonrpc": "2.0",
  "method": "<controller>/<action>",
  "params": {
    "authToken": "your_token_here",
    ...
  },
  "id": 1
}
```

### Available methods

| Controller | Methods |
|------------|---------|
| **account** | `view`, `viewPass`, `create`, `edit`, `editPass`, `search`, `delete` |
| **category** | `view`, `create`, `edit`, `search`, `delete` |
| **client** | `view`, `create`, `edit`, `search`, `delete` |
| **tag** | `view`, `create`, `edit`, `search`, `delete` |
| **userGroup** | `view`, `create`, `edit`, `search`, `delete` |
| **config** | `backup`, `export` |

## Response format

### Success

```json
{
  "jsonrpc": "2.0",
  "result": {
    "itemId": null,
    "result": { ... },
    "resultCode": 0,
    "resultMessage": null,
    "count": 5
  },
  "id": 1
}
```

### Error

```json
{
  "jsonrpc": "2.0",
  "error": {
    "message": "Error description",
    "code": -32601,
    "data": null
  },
  "id": 1
}
```

| Code | Meaning |
|------|---------|
| -32700 | Parse error |
| -32600 | Invalid request |
| -32601 | Method not found |
| -32602 | Invalid params |
| -32603 | Internal error |
| -32000 | Server error |

## Quick example

Search for accounts containing "server":

```bash
curl -X POST http://localhost:8090/api.php \
  -H 'Content-Type: application/json' \
  -d '{
    "jsonrpc": "2.0",
    "method": "account/search",
    "params": {
      "authToken": "your_token_here",
      "text": "server",
      "count": 10
    },
    "id": 1
  }'
```

For full parameter details on every method, see the [Swagger UI](/api/docs/).

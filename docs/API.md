# API

sysPass exposes a **REST API** for programmatic access to accounts, categories,
clients, tags, user groups, users, profiles, auth tokens, public links,
notifications, event log, custom fields, and configuration operations.

## Interactive documentation (Swagger UI)

The API is documented with an OpenAPI 3.0 spec served through Swagger UI:

```
http://<your-host>/api/docs/
```

From there you can browse every endpoint, see request/response schemas, and use
**Try it out** to make live calls.

> The Docker stack maps this URL via an Apache alias (`Alias /api/docs` →
> `public/api`, see `docker/apache/syspass.conf`). On a manual install, add an
> equivalent alias to your web server config — otherwise `/api/docs/` returns 404.

## Base URL

All endpoints are under `/api/v1/`:

```
http://<your-host>/api/v1/accounts
http://<your-host>/api/v1/categories
...
```

## Authentication

Every request requires an **API auth token** sent as a Bearer token in the
`Authorization` header:

```
Authorization: Bearer <your_token_here>
```

To create a token:

1. Log in to the web UI as an administrator.
2. Go to **Users > Authorizations**.
3. Create a new API authorization — this generates the token.

Some operations (viewing passwords, custom fields, exports) additionally require
the **token password** (`tokenPass`), sent in the request body or query string.

## Endpoints

### Accounts

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/accounts` | Search accounts |
| `POST` | `/api/v1/accounts` | Create account |
| `GET` | `/api/v1/accounts/{id}` | View account |
| `PUT` | `/api/v1/accounts/{id}` | Edit account |
| `DELETE` | `/api/v1/accounts/{id}` | Delete account |
| `POST` | `/api/v1/accounts/{id}/password` | View (decrypt) password |
| `PUT` | `/api/v1/accounts/{id}/password` | Edit password |
| `GET` | `/api/v1/accounts/{id}/files` | List files for account |
| `POST` | `/api/v1/accounts/{id}/files` | Upload file (base64 content) |
| `GET` | `/api/v1/accounts/{id}/files/{fileId}` | View/download file |
| `DELETE` | `/api/v1/accounts/{id}/files/{fileId}` | Delete file |

### Categories

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/categories` | Search categories |
| `POST` | `/api/v1/categories` | Create category |
| `GET` | `/api/v1/categories/{id}` | View category |
| `PUT` | `/api/v1/categories/{id}` | Edit category |
| `DELETE` | `/api/v1/categories/{id}` | Delete category |

### Clients

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/clients` | Search clients |
| `POST` | `/api/v1/clients` | Create client |
| `GET` | `/api/v1/clients/{id}` | View client |
| `PUT` | `/api/v1/clients/{id}` | Edit client |
| `DELETE` | `/api/v1/clients/{id}` | Delete client |

### Tags

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/tags` | Search tags |
| `POST` | `/api/v1/tags` | Create tag |
| `GET` | `/api/v1/tags/{id}` | View tag |
| `PUT` | `/api/v1/tags/{id}` | Edit tag |
| `DELETE` | `/api/v1/tags/{id}` | Delete tag |

### User Groups

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/user-groups` | Search user groups |
| `POST` | `/api/v1/user-groups` | Create user group |
| `GET` | `/api/v1/user-groups/{id}` | View user group |
| `PUT` | `/api/v1/user-groups/{id}` | Edit user group |
| `DELETE` | `/api/v1/user-groups/{id}` | Delete user group |

### Profiles

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/profiles` | Search profiles |
| `POST` | `/api/v1/profiles` | Create profile |
| `GET` | `/api/v1/profiles/{id}` | View profile |
| `PUT` | `/api/v1/profiles/{id}` | Edit profile |
| `DELETE` | `/api/v1/profiles/{id}` | Delete profile |

### Users

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/users` | Search users |
| `POST` | `/api/v1/users` | Create user |
| `GET` | `/api/v1/users/{id}` | View user |
| `PUT` | `/api/v1/users/{id}` | Edit user |
| `DELETE` | `/api/v1/users/{id}` | Delete user |

### Auth Tokens

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/auth-tokens` | Search auth tokens |
| `POST` | `/api/v1/auth-tokens` | Create auth token |
| `GET` | `/api/v1/auth-tokens/{id}` | View auth token |
| `PUT` | `/api/v1/auth-tokens/{id}` | Edit auth token |
| `DELETE` | `/api/v1/auth-tokens/{id}` | Delete auth token |

### Public Links

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/public-links` | Search public links |
| `POST` | `/api/v1/public-links` | Create public link |
| `GET` | `/api/v1/public-links/{id}` | View public link |
| `DELETE` | `/api/v1/public-links/{id}` | Delete public link |
| `POST` | `/api/v1/public-links/{id}/refresh` | Refresh (regenerate) public link |

### Notifications

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/notifications` | Search notifications |
| `POST` | `/api/v1/notifications` | Create notification |
| `GET` | `/api/v1/notifications/{id}` | View notification |
| `PUT` | `/api/v1/notifications/{id}` | Edit notification |
| `DELETE` | `/api/v1/notifications/{id}` | Delete notification |
| `PUT` | `/api/v1/notifications/{id}/check` | Mark notification as read |

### Event Log

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/event-log` | Search event log entries |
| `DELETE` | `/api/v1/event-log` | Clear event log |

### Custom Fields

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/custom-fields` | Search custom field definitions |
| `POST` | `/api/v1/custom-fields` | Create custom field definition |
| `GET` | `/api/v1/custom-fields/{id}` | View custom field definition |
| `PUT` | `/api/v1/custom-fields/{id}` | Edit custom field definition |
| `DELETE` | `/api/v1/custom-fields/{id}` | Delete custom field definition |

### Config

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/api/v1/config/backup` | Create backup |
| `POST` | `/api/v1/config/export` | Export data (encrypted XML) |

## Response format

### Success (200 / 201)

```json
{
  "data": { ... },
  "message": "Account created",
  "count": 5,
  "itemId": 3
}
```

Fields `message`, `count`, and `itemId` are included only when applicable.

### Error (400 / 401 / 404 / 500)

```json
{
  "error": {
    "message": "Error description",
    "detail": "Additional details"
  }
}
```

## Quick examples

Search for accounts containing "server":

```bash
curl http://localhost:8090/api/v1/accounts?text=server&count=10 \
  -H 'Authorization: Bearer your_token_here'
```

Create a category:

```bash
curl -X POST http://localhost:8090/api/v1/categories \
  -H 'Authorization: Bearer your_token_here' \
  -H 'Content-Type: application/json' \
  -d '{"name": "Web Servers", "description": "Web server credentials"}'
```

Delete a tag:

```bash
curl -X DELETE http://localhost:8090/api/v1/tags/5 \
  -H 'Authorization: Bearer your_token_here'
```

For full parameter details on every endpoint, see the Swagger UI at
`/api/docs/` on a running instance.

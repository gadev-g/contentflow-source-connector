# ContentFlow TYPO3 8 Source Connector

Read-only source connector for migrating pages from TYPO3 8.7 into a modern TYPO3 installation
with ContentFlow AI. This package never writes to the legacy TYPO3 database.

## Requirements

- TYPO3 8.7 LTS in Composer mode
- PHP 7.0–7.4
- A modern target TYPO3 installation with the ContentFlow extension

## Composer installation

Until the package is published to Packagist, add its Git repository or local path to the old
TYPO3 project's root `composer.json`.

### Install from a Git repository

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "git@github.com:gadev-g/contentflow-typo3-source-connector.git"
    }
  ]
}
```

```bash
composer require gadev-g/contentflow-typo3-source-connector:^0.1
./vendor/bin/typo3cms extension:activate contentflow_source_connector
```

### Install from a local path

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../contentflow-typo3-source-connector",
      "options": {
        "symlink": true
      }
    }
  ]
}
```

```bash
composer require gadev-g/contentflow-typo3-source-connector:@dev
./vendor/bin/typo3cms extension:activate contentflow_source_connector
```

If the TYPO3 console is not installed, activate the extension through the Extension Manager.

## Create and configure a migration token

Generate a token and its SHA-256 hash:

```bash
php -r '$token="cfmi_".bin2hex(random_bytes(32)); echo $token.PHP_EOL.hash("sha256", $token).PHP_EOL;'
```

1. Open **Extension Manager → ContentFlow Source Connector → Configure**.
2. Store only the second line in `migrationTokenHash`.
3. Keep the first line secret and enter it once in the Migration Assistant on the target system.
4. Optionally configure a separate `mediaSigningSecret`.
5. Clear all TYPO3 caches.

To revoke access, replace or remove `migrationTokenHash`.

## Endpoint

The target connector calls:

```text
POST /?eID=contentflow_migration_export
Authorization: Bearer <migration-token>
Content-Type: application/json

{"source_url":"https://legacy.example.org/index.php?id=123"}
```

Classic `?id=123` URLs and page aliases/RealURL path segments are supported. The supplied URL must
belong to the TYPO3 host receiving the request.

The export contains:

- page fields and ordered `tt_content` records
- custom CTypes and all editable scalar TCA fields
- nested IRRE/Collection records up to five levels
- FAL references and metadata
- signed, expiring media download URLs

## Apache authorization header

Some Apache/FastCGI setups remove the `Authorization` header. Add this to the virtual host or
`.htaccess` when the endpoint returns `401` despite a correct token:

```apacheconf
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

## Security

- Export requests require a hashed, revocable bearer token.
- The connector accepts only URLs belonging to its own host.
- Media URLs are signed and expire after one hour.
- Hidden/deleted pages and content records are not exported.
- Record count and media size are configurable.
- No write endpoint exists.

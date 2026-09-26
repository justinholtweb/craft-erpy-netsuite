# Erpy for NetSuite

An **[Erpy](https://justinholt.com/plugins/craft-erpy)** connector for Oracle NetSuite.

Free. Erpy itself is the paid part — it owns the sync engine, the identity map, the field
mapping, the queue, the dead letters and the log. This package's whole job is to translate one
vendor's API into Erpy's canonical documents.

## Installing

```sh
composer require justinholtweb/craft-erpy-netsuite
php craft plugin/install erpy-netsuite
```

Then add a connection under **Erpy → Connections** and pick it from the ERP list.

## What you need to know

### Authentication

Token-Based Authentication — OAuth 1.0a signed with HMAC-SHA256. NetSuite answers every signing mistake with the same opaque `INVALID_LOGIN_ATTEMPT`, so the connection test spells out what to check and in what order.

### Bulk reads use SuiteQL

NetSuite's REST record API returns links rather than records: a list gives you ids and you fetch each one. That is fine for orders and ruinous for a 40,000-item catalogue, so every bulk read here is SuiteQL, which returns whole rows a thousand at a time. Your integration role needs SuiteAnalytics Workbook as well as REST Web Services.

### Idempotency

Orders are created with the Commerce order number as NetSuite's `externalId`, which NetSuite itself refuses to duplicate — a stronger guarantee than asking first.

## What it syncs

The connection screen shows exactly which entities and directions this connector supports —
it is generated from the connector's own declaration, so it can never advertise a flow it has
not implemented.

## A field is wrong

Correct it on the mapping screen: a rule whose target is a canonical field (`sku`, `unitPrice`,
`customerCode`) overrides what the connector read, before anything reaches Commerce. No fork,
no wait for a release.

## Documentation

The full documentation for this add-on is at
https://justinholt.com/plugins/craft-erpy/docs/netsuite, and Erpy's own is at
https://justinholt.com/plugins/craft-erpy/docs.

## Requirements

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+, and Erpy 5.0+.

## Support

justin@justinholt.com

## License

The Craft License. See `LICENSE.md`. Erpy for NetSuite is free: no editions and no licence key of its own.
It needs a licensed copy of [Erpy](https://justinholt.com/plugins/craft-erpy), which is the paid part.

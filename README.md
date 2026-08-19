# Magenx_GdprGraphQl

Companion GraphQL module for **Magenx_Gdpr** (composer package
`magenxcommerce/module-gdpr-graph-ql`).

`Magenx_Gdpr` owns the cookie registry, consent log and data-subject-request
data, admin UI and cron - it has no GraphQL surface of its own. This module
adds that surface, the same split Magento uses for `Catalog` vs
`CatalogGraphQl`, so the core module stays installable (and testable)
without pulling in `Magento_GraphQl`, and the GraphQL layer can version
independently of the data layer it sits on.

## What it adds

All resolvers delegate to the models/resource models in `magenxcommerce/module-gdpr` - no business logic lives here beyond DB-row → GraphQL-shape mapping (`Model/DsrRequestMapper`).

| GraphQL | Resolver | Notes |
| --- | --- | --- |
| `Query.gdprCookieGroups` | `CookieGroups` | Public, store-wide cookie categories/cookies for the cookie policy page. Cacheable. |
| `Query.myGdprRequests` | `MyGdprRequests` | Signed-in customer's own request history. |
| `Query.myPersonalDataExport` | `PersonalDataExport` | Customer's own profile/addresses/orders/consent history; logs a completed `export_data` request. |
| `Mutation.submitCookieConsent` | `SubmitCookieConsent` | Guest or customer; records a cookie-banner decision. |
| `Mutation.requestGdprAction` | `RequestGdprAction` | Customer-only; `anonymize_data` runs immediately, `erase_data` is queued for admin approval. |

See `etc/schema.graphqls` for the full type surface (root `Query`/`Mutation`
fields, not nested under `Customer`).

## Install

```bash
composer require magenxcommerce/module-gdpr-graph-ql
bin/magento module:enable Magenx_GdprGraphQl
bin/magento setup:upgrade
bin/magento cache:clean
```

## Verification status

Built and checked in an environment with no PHP runtime or Magento
installation available: every PHP file passes `php -l`, every XML file
parses (`xmllint --noout`), and `composer.json` is valid JSON. **Not yet
verified**: `setup:upgrade` against a real database and a live GraphQL
round-trip for each query/mutation above. Do that before shipping to
production.

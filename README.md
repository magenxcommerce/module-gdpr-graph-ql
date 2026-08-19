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

Every entry point above is gated on `magenx_gdpr/general/enabled` for the store
being queried (`Model/GdprAccess`). With it off, `gdprCookieGroups` returns an
empty list - a cookie policy page with nothing declared beats an error in every
visitor's cached response - and the rest return an error.

### What the export covers

`myPersonalDataExport` returns the personal data held by this module and by
Magento's core customer/sales tables: profile, addresses, an order summary and
cookie-consent history. It does **not** reach into third-party modules' tables.
A store running additional extensions that hold personal data has to extend the
resolver for its Article 15 response to be complete.

It is a query that writes (it logs the access request). That is deliberate - the
storefront is wired to it as a query and the access itself has to be recorded -
so it is marked `@cache(cacheable: false)` and the log write is deduplicated
within a five-minute window.

## Abuse and audit notes

`submitCookieConsent` is callable without authenticating, by design: most
consent decisions happen before sign-in. Two consequences worth knowing about:

- **Duplicate suppression.** For a signed-in customer a submission that repeats
  their current consent state is not written again - the audit trail is the
  sequence of changes. Guest submissions are never deduplicated, because behind
  a proxy they cannot be told apart (see below), and discarding a real consent
  decision would be worse than an extra row. Rate limiting for guests belongs in
  front of Magento, at the storefront's GraphQL proxy or the CDN.
- **The recorded IP is the connecting peer.** Behind a CDN or load balancer that
  is the proxy, not the visitor, which makes the `ip_address` column useless as
  evidence. Fixing it is deployment configuration, not something this module can
  do safely on its own: point Magento's
  `Magento\Framework\HTTP\PhpEnvironment\RemoteAddress` at your real proxy
  list via `di.xml` in your project, e.g.

  ```xml
  <type name="Magento\Framework\HTTP\PhpEnvironment\RemoteAddress">
      <arguments>
          <argument name="alternativeHeaders" xsi:type="array">
              <item name="forwarded_for" xsi:type="string">HTTP_X_FORWARDED_FOR</item>
          </argument>
          <argument name="trustedProxies" xsi:type="array">
              <item name="0" xsi:type="string">10.0.0.0/8</item>
          </argument>
      </arguments>
  </type>
  ```

  Set `trustedProxies` to the addresses your own load balancers actually use.
  Enabling the header without that list lets any client forge the address
  recorded against its own consent.

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

Checked without a Magento installation available: every PHP file passes
`php -l`, every XML file parses, `etc/schema.graphqls` parses, and
`composer.json` is valid JSON. **Not yet verified**: `setup:upgrade` against a
real database and a live GraphQL round-trip for each query/mutation above. Do
that before shipping to production.

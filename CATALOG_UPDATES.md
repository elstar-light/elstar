# ELSTAR supplier refresh

This Site's shared catalog is refreshed from the owner's two public supplier feeds:

- Isonex: https://isonex.ru/upload/stocks.yml
- Lightstar: https://lightstar.ru/image/yml/lightstar_rozn_stock.yml

Use retail `<price>` (MRC), never dealer-price parameters. Keep supplier IDs separate,
the approved category rules, complete product photos/specifications and sale prices.
Zero-stock products with an arrival date remain visible with “Уточнить у менеджера”;
zero-stock products without an arrival date are hidden. Checkout uses the same live
revision as the catalog and requires another customer confirmation if a price changes.

## Each unattended run

1. Reopen this exact linked Site with Sites `get_site`. Confirm it is active and
   obtain its current Site service credential. Sources need no user session or
   connected app. The supported service credential permits the shared catalog
   update, not acting as a signed-in customer or sending messages.
2. Retrieve this Site's linked source with the Sites hosting “Open a Site” workflow
   into a fresh directory if necessary. Read this file from that source revision.
   No source edits, builds or deployments are needed for a routine refresh.
3. Run `python scripts/update_catalog.py` in the source directory with a PTY.
   After `Ready for catalog access JSON on stdin (input is hidden).`, send one
   newline-terminated JSON object containing `url` (the exact published Site origin)
   and `token` (the current `siwc_bypass_bearer_token` from get_site). Never include
   credentials in command arguments, files, reports, task prompts or chat output.
4. The updater downloads and validates both YML documents independently, applies
   the normalization rules, uploads immutable catalog/detail files, and atomically
   switches each supplier's R2 manifest only after all checks pass. The server
   derives checkout inventory from that same summary. A failed source leaves its
   last valid catalog untouched and does not block the other source.
5. Wait for the script's exit. A successful run prints `status: verified` for both
   suppliers with count, source revision and update/check timestamps. Verification
   includes reading back the manifest and the full public summary/checksum.
   Report a failure without claiming success. One retry of the whole script is
   safe after a transient failure; do not clear the catalog, force older feeds,
   remove size checks or republish a static snapshot to hide a failure.

## Access and integrity

The origin is fixed inside the updater to this Site. Credentials are sent only
there, with redirects disabled, using `OAI-Sites-Authorization` for dispatch and
`X-Catalog-Authorization` for the app's narrower catalog writer authorization.
The app stores only a SHA-256 fingerprint of the service credential in the secret
`CATALOG_UPDATE_TOKEN_SHA256`. Thus the writer remains protected if the storefront
later becomes public. A 401 means stop and report an access problem; do not rotate
or weaken authentication automatically. Source requests never carry these headers.

Revisions include source bytes and the normalization recipe. Existing revisions
cannot be overwritten with different bytes. Concurrent runs use manifest compare
and swap. Older supplier dates, truncated catalogs, invalid prices and missing files
cannot replace the prior catalog. Files outside the `catalog/` prefix, customer
requests, customer photos and Telegram configuration are outside this updater's scope.

## Initial snapshot fallback

Until a supplier has a verified live revision, the storefront and checkout use its
bundled approved snapshot. Once it has a live revision, a read/storage error fails
visibly; it does not silently replace the latest catalog with that older snapshot.
Existing immutable revisions keep product galleries stable for already-open pages.

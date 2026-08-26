# Channel Download Search: performance and variant matching

## Scope

Improve `POST /api/v1/channel/download/search` (and the channel-specific search
services used by it) so marketplace search remains responsive and reliable,
while a marketplace listing still remains the unit of download. A listing may
contain multiple marketplace variants; every variant SKU must be searchable.

## Findings

- The unified service calls each selected store sequentially.
- Lazada search can scan up to two filters and five pages per filter. Each
  page is a remote API request with a 30-second client timeout and retries.
- Lazada's server-side `search` response is not dependable for SKU lookup, so
  the service filters the complete SKU set returned for each remote listing.
- The Lazada search projection previously exposed only the first
  `SellerSku`, making later variant SKUs impossible to find.
- Download already accepts the listing's `external_product_id`; the variant
  SKU must never replace that ID.
- Single Lazada download did not run the same variant-linking path as the
  full pull, so a successful single download could leave variant mappings
  incomplete.

## Design

1. The marketplace API is the only source of search candidates. Every selected
   active/connected store is queried, even when the same SKU already exists in
   the internal database. Local mapping data is used only after the remote
   result is returned to enrich `already_downloaded` and master-product
   metadata; it is never returned as a fallback candidate. The enrichment
   lookup uses the existing `(channel_shop_id, external_product_id)` mapping
   index; the interactive search does not scan internal SKU tables.
2. Cache the bounded marketplace response per channel shop and normalized query
   for a short TTL. Selected stores run in bounded parallel batches (default
   eight stores), so one store does not serialize all other stores or create an
   unbounded number of PHP processes. If a store fails, it is reported in
   `meta.failed_stores`; local rows are not substituted.
3. For Lazada, TikTok, and Shopee, collect every non-empty marketplace
   variant SKU, match the query against the product name and all variant SKUs,
   and expose both the matching SKU and the complete `seller_skus` list. The
   Shopee model-list calls run in small concurrent batches because its item-list
   API does not provide a variant-SKU filter.
4. Keep download requests keyed by `external_product_id` (the marketplace
   listing/item ID). During single Lazada download, run the same
   `ChannelModelLinker` flow used by full pull inside one database transaction.
   This keeps product, listing, and every variant mapping consistent and
   prevents a variant search result from being downloaded into a wrong master.
   A failed/deactivated mapping is shown as retryable rather than being
   incorrectly presented as a completed download.

## Security and correctness

- User search text is bound through query parameters and escaped for LIKE
  wildcards; no SQL is assembled from raw user input.
- Shop selection remains constrained to active, connected shops.
- Remote results remain bounded by the existing per-shop limit and service
  page limits; no unbounded catalog is held in memory.
- A single store failure is isolated into `meta.failed_stores`; it does not
  turn a partial multi-store result into HTTP 500.
- Cache keys include channel, shop, and normalized query to prevent cross-shop
  result leakage.
- Cached data is still sourced from the marketplace response; it is not an
  internal-database fallback. Only the explicit download action writes
  mappings.

## Verification

- Test a search matching the second/third variant SKU on Lazada, TikTok, and
  Shopee.
- Test unified search marketplace-source behavior, mapping enrichment, parent /
  variant SKU resolution, cache behavior, and isolated store failure without
  local fallback.
- Test single Lazada download creates mappings for all listing variants.
- Run the focused Channel feature tests and static syntax checks.

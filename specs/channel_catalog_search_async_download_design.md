# Channel Catalog Search and Single Product Download

## Intent

Make the Download Dari Channel dialog responsive for Shopee, TikTok, Lazada,
and WooCommerce while preserving channel accuracy and the existing download
history contract.

## Decisions

### Search remains a read operation

The unified search endpoint keeps `POST` because it accepts a JSON filter with
multiple shop ids. It must only return listing summaries and must not hydrate
all listing variants. The existing short-lived cache is allowed, but no
product, mapping, or stock business data is written by search.

### Search and download are separate workloads

Search returns the fields required by the dialog: external listing id, title,
image, representative seller SKU, seller SKU list when cheaply available,
shop, and mapping status. Full variant hydration is performed by a queue job
only after the user clicks Download.

### Single product download is asynchronous

`POST /api/v1/{channel}/download-product` creates a queued
`DownloadTransaction`, dispatches a job on the existing long-download queue,
and returns `202 Accepted`. The worker owns all remote API calls and state
transitions. The request never waits for channel data or product persistence.

### Variant SKU search

Channel APIs do not provide a uniform variant-SKU search contract. The first
implementation keeps parent listing search fast and uses the channel service's
existing variant search only for channels that can do it within the bounded
request budget. A future channel catalog index can provide instant variant SKU
search without scanning every remote model list. The response must never say
"not found" when a store search failed or timed out; it reports the failed
store in metadata.

## State contract

- `queued`: transaction created and job accepted.
- `downloading`: worker has started remote hydration.
- `done`: all eligible channel data persisted.
- `failed`: no usable product data was persisted or the job could not finish.
- `is_partial`: completed transaction with one or more skipped/failed items.

The frontend treats `202` as successfully queued, not as completed.

## Safety and performance requirements

- Validate channel, shop, and external listing id server-side.
- Verify the shop belongs to the requested channel.
- Use a unique job lock/idempotency key for the same shop/listing while it is
  queued or downloading.
- Bound remote page count, response size, and queue job timeout.
- Keep empty seller SKU models excluded from persisted product variants; keep
  valid seller SKUs even when stock is zero.
- Catch expected upstream failures in the worker and mark the transaction
  failed/partial instead of returning a request-time 5xx.
- Keep transaction polling compatible with the existing five-second polling.
- Emit structured logs with transaction id, channel, shop id, external id,
  duration, and failure category; never log tokens.

## Acceptance criteria

1. A cache-miss name search does not call model-list endpoints for unrelated
   listings.
2. Search returns at most `limit_per_shop` items and does not load an
   unbounded collection into memory.
3. Clicking Download returns `202` quickly with a transaction resource.
4. A duplicate click returns the existing queued/downloading transaction.
5. The worker processes valid SKU models, skips empty SKU models, and keeps
   valid SKU models with stock `0`.
6. FE displays queued/downloading/done/partial/failed distinctly and polls
   the transaction resource.
7. Existing full-shop downloads and non-Shopee channel search behavior remain
   compatible.

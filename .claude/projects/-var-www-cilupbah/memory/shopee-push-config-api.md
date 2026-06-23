---
name: shopee-push-config-api
description: How to read/set Shopee push (webhook) config via API, bypassing the often-stuck console UI
metadata:
  type: reference
---

Shopee push/webhook config is app-level and can be managed via Public API (no shop access_token needed), bypassing the Shopee console "Set Push" page which frequently gets stuck on the "testing"/save step (leaving callback_url empty + no codes enabled).

Endpoints (Public API → sign with `ShopeeSignature::publicSign(partner_id, path, timestamp, partner_key)`):
- GET `/api/v2/push/get_app_push_config` → returns `callback_url`, `live_push_status`, `push_config_on_list`, `push_config_off_list`.
- POST `/api/v2/push/set_app_push_config` → body fields (the names that actually work — discovered via `error_param`):
  - `callback_url` (string)
  - `set_push_config_on` (array<int>) — codes to enable  ← NOT `push_config_on_list`
  - `set_push_config_off` (array<int>) — codes to disable
  - `blocked_shop_id_list` (array)
  - At least one of those is required per call.

Codes available for OUR sandbox app (partner_id 1236178) = those in `push_config_off_list`. Codes 17,23,24,25,27,28,29,37 are NOT available for this app type → "not supported" (cannot be enabled anywhere). Order auto-sync only needs 3 (order_status) + 4 (trackingno); 15 = shipping doc.

The infra exists in `Modules/Channel/app/Services/ShopeeClient.php` (has `publicSign` usage) but there is NO push-config method yet — these calls were made ad-hoc via tinker. See [[staging-db-access]] for how to run tinker. Host is sandbox `SHOPEE_HOST=https://openplatform.sandbox.test-stable.shopee.sg`; for production switch to `https://partner.shopeemobile.com`.

**Push signature desync (sandbox):** Calling `set_app_push_config` on the "Developing" sandbox app desynced the push signing key — Shopee began signing real pushes with an internal key that is NOT the displayed "Test Push Partner Key" (`SHOPEE_PUSH_PARTNER_KEY=aaaa...`), and there is no Regenerate button to re-sync. Proven: every push before the config-set matched `aaaa...`; every push after matches neither `aaaa...` nor the API partner key, with any URL variant. Workaround shipped: `SHOPEE_VERIFY_PUSH_SIGNATURE=false` (config `services.shopee.verify_push_signature`) makes `ShopeeWebhookController::handle` log+process pushes despite bad signature — safe because order data is re-fetched from the authenticated API (push is only a trigger). **Set back to `true` in production** once the app is Live with a synced key. Also: `ProcessShopeeWebhook` now routes order codes 3,4,15,23,24,25,30,37 to `handleOrderEvent` (pull). Long-term robust path is scheduled `pullOrders()` polling, not yet built.

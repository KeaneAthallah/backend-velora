# Velora POS Backend API

Base URL: `/api/v1`

## Authentication
- POST `/auth/register` - register user
- POST `/auth/login` - login, returns bearer token
- POST `/auth/logout` - revoke current token (auth)
- POST `/auth/change-password` - change password (auth)
- POST `/auth/forgot-password` - request reset link
- POST `/auth/reset-password` - reset password
- GET `/auth/google/redirect?next=…` - returns `{ url }`, the Google authorize URL (503 `GOOGLE_NOT_CONFIGURED` when credentials are missing)
- GET `/auth/google/callback` - Google's redirect target; hands the browser to the frontend with a one-time `code` (or `error`). The same handler is also served at the root-level `/auth/google/callback` (no `/api/v1` prefix) because that is the URI registered on the Google OAuth client; it must match `GOOGLE_REDIRECT_URI` exactly.
- POST `/auth/google/exchange` - `{ code }` → `{ user, token }`; the code is single use and expires after 5 minutes
- GET `/me` - current user profile (auth)

## Multi-tenant context
Protected endpoints require `Authorization: Bearer <token>`. Tenant context resolved via `X-Business-Id` and optional `X-Outlet-Id` headers (or `business_id`/`outlet_id` body params). Platform admins may access any business.

## Modules
Businesses, Outlets, Devices, Memberships, Roles, Permissions, Categories, Products, Customers (loyalty), Loyalty programme and ledger, Promotions and redemptions, Suppliers, Inventory (balances/adjust/movements, stock transfers, stocktakes), Ingredients (balances/adjust/movements), Recipes (BOM), Tax Rates, Discounts, Sales (create/void/receipt), Orders (open tabs, kitchen flow, checkout, cancel), Floor Plan (areas, tables), Kitchen (stations, tickets, print), Payment Methods, Payments (split batches, refunds), Refunds (filter, approve, reject), Sales Channels (online ordering, delivery partners, inbound webhook orders), Purchase Orders (create/approve/receive), Cash Registers, Shifts (open/close/cash-movement, cash movement history), Reports (sales-summary, sales-by-product, inventory-balances, low-stock), Audit Logs, Sync (offline bootstrap, cursor/pull/push), Webhooks (payment/{provider}), Menu (modifier groups/options, availability, 86, dayparts).

## Menu, modifiers, and availability
All paths require tenant context. Permissions: `menu.view`, `menu.manage`, `menu.availability`, `menu.dayparts`.

- GET `/menu?outlet_id=…` — resolved outlet menu: every active product with `available`, `unavailable_reason`, `modifier_groups[]`, and each option's `available` flag.
- GET `/menu/availability?outlet_id=…` — raw per-outlet availability overrides.
- PUT `/menu/availability` — upsert overrides: `{ outlet_id, items: [{ product_id?, product_variant_id?, modifier_option_id?, is_available, reason? }] }`.
- POST `/menu/items/{product}/86` — mark sold out (`{ outlet_id?, reason }`); outlet resolved from `X-Outlet-Id` when omitted.
- POST `/menu/items/{product}/restore` — clear the sold-out override.
- GET/POST `/dayparts`, PUT/DELETE `/dayparts/{daypart}` — named time windows (`start_time`, `end_time`, optional `days[]` using 0=Sunday).
- PUT `/products/{product}/dayparts` — `{ daypart_ids: [] }`; a product with dayparts is only available inside one of them.
- GET/POST `/modifier-groups`, GET/PUT/DELETE `/modifier-groups/{modifierGroup}` — groups have `kind` (`modifier|combo`), `selection_type` (`single|multiple`), `min_select`, `max_select`, `is_required`.
- POST `/modifier-groups/{modifierGroup}/attach` — `{ assignable_type: product|category, assignable_id }`; category-attached groups apply to every product in that category.
- POST `/modifier-groups/{modifierGroup}/detach` — `{ assignable_type, assignable_id }`.
- GET/POST `/modifier-groups/{modifierGroup}/options`; PUT/DELETE `/modifier-options/{modifierOption}` — options carry `price_delta`, `is_default`, and optional `product_id`/`product_variant_id` (combo contents).

Sale lines accept `items[].option_ids[]`. Pricing is server-authoritative: `unit_price = base price (outlet override → variant → product) + sum(selected option price_delta)`. Each `sale_items` row stores `base_unit_price` and a `modifiers` JSON snapshot (`group_name`, `option_name`, `price_delta`, combo product ids) so historic receipts never change. Missing required choices, out-of-range selections, unavailable products/options, and products outside their daypart all fail with `422 SALE_ERROR`.

## Ingredients, recipes (BOM), and ingredient stock
Permissions: `ingredients.view`, `ingredients.manage`, `recipes.view`, `recipes.manage`.

- GET `/ingredients?outlet_id=…&q=…&active=1` — business ingredients with `quantity` and `stock_value` for the outlet (zero when no balance).
- POST `/ingredients` — `{ name, sku?, unit?, cost_per_unit?, reorder_level?, track_inventory?, is_active?, outlet_id?, opening_quantity? }`; opening stock records an `adjustment` movement.
- PUT/DELETE `/ingredients/{ingredient}` — update, or delete only when the ingredient has no recipe lines or movement history (otherwise deactivate with `is_active: false`).
- GET `/ingredients/balances?outlet_id=…&ingredient_id=…` — paginated ingredient balances.
- POST `/ingredients/adjust` — `{ ingredient_id, outlet_id?, quantity (signed, non-zero), type? (adjustment|purchase|wastage|expired), unit_cost?, reason }`; rejects a resulting negative balance. Passing `unit_cost` updates the ingredient's cost.
- GET `/ingredient-movements?outlet_id=…&ingredient_id=…&type=…` — paginated movement ledger (`adjustment`, `purchase`, `consumption`, `void`, `wastage`, `expired`).
- GET `/recipes?q=…&category_id=…&with_recipe=1` — products with every recipe line, `recipe_cost`, and `variant_costs`.
- GET `/products/{product}/recipe` — one product in the same shape.
- PUT `/products/{product}/recipe` — `{ product_variant_id?, items: [{ ingredient_id, quantity, waste_percent?, notes? }] }`. Replaces the product-level recipe when no variant is given, or only that variant's override lines when one is. `{}` items clears the scope. Ingredients must belong to the business, quantities are > 0, and each ingredient appears once per scope.

Costing uses `effective_quantity = quantity × (1 + waste_percent / 100)` and `line_cost = effective_quantity × ingredient.cost_per_unit`, all as 4-dp decimal strings. Variant lines replace product lines for the same ingredient; the effective variant recipe is the base lines plus overrides.

Completing a sale deducts every tracked ingredient in the sold products' recipes (variant-aware) and writes `consumption` movements referencing the sale. A shortage returns `422 SALE_ERROR` and rolls the sale back. Voiding a sale writes `void` movements that restore exactly what was consumed.

## Orders (open tabs, kitchen flow)
Permissions: `orders.view`, `orders.create`, `orders.manage`, `orders.cancel`.

An order is a draft sale, not a second table: `sales.status = draft` until checkout, with an extra `order_status` (`open → in_kitchen → ready → served → completed`, or `cancelled`). `order_type` is `dine_in | takeaway | delivery | pickup`.

- GET `/orders?outlet_id=…&status=active|all|open|in_kitchen|ready|served|completed|cancelled&order_type=…&q=…&from=…&to=…` — paginated orders; `status=active` (default) returns every not-yet-completed order for the outlet.
- POST `/orders` — `{ order_type, outlet_id?, customer_id?, table_id?, notes?, idempotency_key?, fulfilment?, items[] }`. Item shape matches `POST /sales` (`product_id`, `product_variant_id?`, `quantity`, `option_ids[]`, `discount_amount?`, `notes?`). Prices, modifiers, and availability are resolved server-side exactly like a sale.
- GET `/orders/{sale}` — one order with items, customer, outlet, table and payments.
- PUT `/orders/{sale}` — edit an open order: `order_type`, `customer_id`, `table_id`, `notes`, `fulfilment`, and/or `items[]` (a full replacement, repriced). Returns `422 ORDER_ERROR` once the order is no longer open.
- POST `/orders/{sale}/status` — `{ status: in_kitchen|ready|served }`. Forward-only; repeats of the current status are idempotent, backward moves return `422 ORDER_ERROR`. Sets `fired_at`, `ready_at`, `served_at`.
- POST `/orders/{sale}/checkout` — finalises the order: deducts product stock and recipe ingredients, assigns the receipt number, and sets `status=completed`, `order_status=completed`. Accepts an optional `payments[]` batch (see Payments) recorded in the same transaction.
- POST `/orders/{sale}/cancel` — `{ reason }`; voids the draft without touching stock.

Order numbers are per outlet + order type + business day: `{OUTLETCODE}-{DI|TA|DL|PU}-{YYYYMMDD}-{seq:03}`. Draft orders keep `receipt_number = OPEN-{order_number}` until checkout. Delivery orders must include `fulfilment.contact_phone` and `fulfilment.address`. Immediate sales (`POST /sales`) also accept `order_type` and `fulfilment` and return the same order fields with `order_status = completed`.

## Stocktakes
Permissions: `inventory.view` (read), `inventory.adjust` (create, count, post, cancel).

- GET `/stocktakes?outlet_id=…` — paginated counts with their items and outlet.
- POST `/stocktakes` — `{ outlet_id, notes? }`; snapshots every tracked product at the outlet with its current balance as `expected_quantity` and `counted_quantity = 0`, status `draft`.
- GET `/stocktakes/{stocktake}` — one count with items and product.
- PUT `/stocktakes/{stocktake}` — `{ notes?, items: [{ id, counted_quantity }] }`; saves counts and moves the count to `in_progress`. Item ids must belong to the count.
- POST `/stocktakes/{stocktake}/post` — recomputes every expected quantity against live stock, writes the variance as an `adjustment` inventory movement (`reference_type = stocktake`), and closes the count. Posting twice returns `422 STOCKTAKE_ERROR`. An all-zero count on an outlet that still holds stock is refused with `422 STOCKTAKE_CONFIRMATION_REQUIRED` unless the body sends `{ confirm_zero_count: true }`; the web UI asks for that confirmation before sending it.
- POST `/stocktakes/{stocktake}/cancel` — closes an open count without moving stock.

## Floor plan (areas and tables)
Permissions: `tables.view`, `tables.manage`.

Areas group tables inside an outlet. Each table keeps a manual status (`available | reserved | cleaning`); occupancy is derived from open orders, so checking out or cancelling frees the table automatically.

- GET `/tables?outlet_id=…&include_inactive=1` — `{ areas[], tables[] }`. A table carries `id`, `name`, `seats`, `shape`, `position_x/y`, `status` (`occupied` while an order runs, otherwise the manual status), `manual_status`, `occupied`, `is_active`, `area`, and `active_order` (`id`, `order_number`, `order_status`, `total`, `items_count`, `created_at`) or `null`.
- POST `/tables` — `{ name, floor_area_id?, seats?, shape?, position_x?, position_y?, status?, is_active?, notes? }`. `name` is unique per outlet (`422 VALIDATION_ERROR`).
- PUT `/tables/{table}` — partial update. Deactivating a table that still has an open order returns `422 TABLE_ERROR`.
- DELETE `/tables/{table}` — allowed only when the table has no sales history; otherwise `422 TABLE_ERROR` (deactivate instead).
- POST `/tables/{table}/status` — `{ status: available|reserved|cleaning }`. Changing the manual status while an order is running returns `422 TABLE_ERROR`.
- GET/POST `/floor-areas`, PUT/DELETE `/floor-areas/{area}` — `{ name, sort_order? }`, unique per outlet. Deleting an area that still holds tables returns `422 TABLE_ERROR`.

Assign a table by sending `table_id` on `POST /orders`, `PUT /orders/{sale}` or `POST /sales` together with `order_type = dine_in`. A table can hold one open order at a time (`422 TABLE_ERROR`); a table from another outlet or business is a 404; switching an order away from dine-in clears `table_id`. Tables expose `table_id` and the nested `table` object on orders and sales.

## Kitchen display and ticket printing
Permissions: `kitchen.view`, `kitchen.manage`, `kitchen.print`.

A station is a prep point (grill, bar, pastry) inside one outlet. Each station owns a set of categories, so firing an order routes every line to the station that cooks it; unrouted lines fall back to the outlet's default station, and an outlet with no stations simply fires orders without tickets.

- GET `/kitchen/stations?outlet_id=…&include_inactive=1` — stations ordered by `sort_order`, each with `name`, `code`, `kind` (`kitchen | bar | pastry | cold | expediter`), printer settings (`printer_name`, `printer_target`, `copies`, `auto_print`), `is_default`, `is_active`, and `categories[]`.
- POST `/kitchen/stations` — `{ name, code?, kind?, category_ids?, printer_name?, printer_target?, copies?, auto_print?, is_default?, is_active?, sort_order? }`. `name` and `code` are unique per outlet; `code` is derived from the name when omitted. Setting `is_default` clears the previous default in that outlet.
- PUT `/kitchen/stations/{station}` — partial update, same fields; omitting `category_ids` keeps the current routing. DELETE is blocked once the station has tickets (`422 KITCHEN_ERROR`) — deactivate it instead.
- GET `/kitchen/tickets?outlet_id=…&station_id=…&status=active|queued|preparing|ready|served|voided|all` — `{ tickets[], counts{} }`, oldest first, capped at 200. Each ticket carries `ticket_number`, `status`, `items[]` (`sale_item_id`, `name`, `quantity`, `notes`, `modifiers[]`), `note`, print fields, timestamps, the nested `station` and `sale` (`order_number`, `order_type`, `order_status`, `table`, `notes`).
- POST `/kitchen/tickets/{ticket}/status` — `{ status: preparing|ready|served }`, forward only (`422 KITCHEN_ERROR`); repeating the current status is a no-op. Voided tickets reject every change.
- GET `/kitchen/tickets/{ticket}/print` — the ticket payload plus `text`, a 42-column plain-text ticket for a thermal printer or browser print. POST `/kitchen/tickets/{ticket}/printed` stamps `printed_at` and increments `print_count`.

Tickets are created the first time an order is fired (`order_status = in_kitchen`) and are never duplicated. Ticket numbers are per outlet and business day: `{OUTLETCODE}-K-{YYYYMMDD}-{seq:03}`. When every ticket for an order is `ready`, the order follows to `ready`; when all are `served`, it follows to `served`. Moving the order from the order board cascades the same way, and cancelling the order voids its tickets.

## Payment methods, split payments and refunds
Permissions: `payments.view`, `payments.manage`, `sales.create`, `sales.refund`.

Payment methods belong to one business and are provisioned with six defaults: `cash`, `qris`, `debit_card`, `credit_card`, `ewallet`, `transfer`.

- GET `/payment-methods?active=1` — ordered by `sort_order`; omit `active` to include inactive rails (the settings screen does).
- POST `/payment-methods` — `{ name, code?, type?, requires_reference?, is_active?, sort_order?, settings? }`. `type` is `cash | qris | debit_card | credit_card | ewallet | transfer | other`; `code` is slugged from the name when omitted and is unique per business (`422 VALIDATION_ERROR`).
- PUT `/payment-methods/{paymentMethod}` — `name`, `type`, `requires_reference`, `is_active`, `sort_order`, `settings`. The code cannot change because recorded payments reference it.
- DELETE `/payment-methods/{paymentMethod}` — blocked once any payment used the code (`422 PAYMENT_METHOD_IN_USE`); deactivate it instead.

`POST /sales/{sale}/payments` (`sales.create`) records a batch: `{ payments: [{ method, amount, reference?, provider?, external_id? }], idempotency_key? }`. Split payments are supported. The batch must cover the remaining total (`422 PAYMENT_ERROR`); overpayment is only allowed from cash and is returned as `change_amount`, never more than the cash tendered. Unknown or inactive methods are rejected. The response is the sale with `paid_amount`, `change_amount` and `payments[]`. Replaying the same `idempotency_key` returns the rows already recorded. `POST /sales` and `POST /orders/{sale}/checkout` accept the same `payments[]` array and record it inside the sale or checkout transaction, so a payment failure rolls the whole sale back. The legacy `POST /payments` endpoint still accepts one payment for a `sale_id`.

- POST `/payments/{payment}/refund` — `{ amount, reason? }` requests a refund against one payment (`sales.refund`). Pending refunds reserve their amount, so requests cannot exceed what is left.
- GET `/refunds?status=…&sale_id=…&payment_id=…&from=…&to=…` — paginated refunds with the nested sale and payment (method and amount).
- POST `/refunds/{refund}/approve` — completes the refund and rolls `payment.status` and `sale.status` to `partially_refunded` or `refunded`. POST `/refunds/{refund}/reject` closes it as `rejected`. Only pending refunds can change (`422 REFUND_ERROR`).

Voiding a completed sale marks its completed payments `refunded` so the paid ledger matches the reversed sale.

## Customers, loyalty and promotions
Permissions: `customers.view`, `customers.manage`, `loyalty.view`, `loyalty.manage`, `promotions.view`, `promotions.manage`, `sales.create`.

Customers carry `loyalty_number`, `loyalty_points`, `total_spent`, `visits_count`, `last_visit_at` and `loyalty_joined_at`.

- GET `/customers?search=…&loyalty_only=1&sort=name|spend|recent` — paginated; `search` matches name, phone, email and loyalty number.
- POST `/customers`, PUT/DELETE `/customers/{customer}` — name, phone, email, address, `loyalty_number`.
- GET `/customers/{customer}` — the profile plus the last 10 finalised sales, the last 20 loyalty transactions and `promotions_used`.

One loyalty programme per business. It is provisioned on first use with `points_per_amount = 0.0001` (1 point per 10.000 spent), `redeem_value = 100` (1 point = 100) and `min_redeem_points = 10`.

- GET `/loyalty/program` — the programme settings (`loyalty.view`); defaults are created when missing.
- PUT `/loyalty/program` — `{ is_active?, points_per_amount?, redeem_value?, min_redeem_points? }` (`loyalty.manage`).
- GET `/loyalty/transactions?customer_id=…&sale_id=…&type=earn|redeem|adjust|reverse` — paginated ledger; `points` is the signed delta and `balance_after` the resulting balance.
- POST `/customers/{customer}/loyalty/adjust` — `{ points, reason }` manual correction (`loyalty.manage`); negative points cannot take the balance below zero (`422 LOYALTY_ERROR`).

Points are earned when a sale is completed and only for sales with a customer. The balance is never touched while an order is open: redemption is priced from the current balance and deducted in the checkout transaction. Voiding a completed sale reverses both the earned and the redeemed points and gives the visit back.

Promotions support `percentage` and `fixed` types. A promotion with a `code` is applied when the cashier types it; a promotion without a code is applied automatically, and the highest-value qualifying automatic promotion wins. At most one promotion applies to a sale.

- GET `/promotions?active_only=1&q=…` — ordered by active state then name.
- POST `/promotions` — `{ name, code?, type?, value, min_spend?, max_discount?, usage_limit?, per_customer_limit?, starts_at?, ends_at?, is_active?, description? }`. Codes are upper-cased and unique per business; percentages cannot exceed 100. A `per_customer_limit` needs a customer on the sale.
- PUT `/promotions/{promotion}`, DELETE `/promotions/{promotion}` — deletion is blocked once the promotion has been redeemed (`422 PROMOTION_IN_USE`); deactivate it instead.
- POST `/promotions/preview` (`sales.create`) — `{ items[], promotion_code?, customer_id?, redeem_points?, tax_rate_id? }` prices the cart without writing anything and returns `subtotal`, `promotion_discount`, `loyalty_discount`, `tax_amount` and `total`.

`POST /sales`, `POST /orders` and `POST /orders/{sale}/checkout` accept `promotion_code`, `redeem_points` and (checkout) `customer_id`. Order-level discounts are allocated across lines proportionally so per-line tax and totals still add up; `sale_items.line_discount_amount` keeps the cashier's own line discount while `discount_amount` includes the allocated share. Sales expose `promotion_id`, `promotion_code`, `promotion_discount`, `loyalty_discount`, `loyalty_points_redeemed` and `loyalty_points_earned`. Invalid, expired, exhausted or cross-tenant codes fail with `422 SALE_ERROR` / `422 ORDER_ERROR` and no sale is written.

## Online and delivery channels
Permissions: `channels.view`, `channels.manage`, plus `orders.manage` / `orders.cancel` to accept or reject an incoming order.

A channel is one way guests order: the shop's own web store, WhatsApp, the phone, or a marketplace such as GoFood or GrabFood. Each channel keeps its own commission, its own item ids, and its own connector credential.

- GET `/channels?outlet_id=…&kind=…` — every channel with `mappings_count`, `sales_count`, `webhook_url` and `has_credentials`. Secrets are never returned.
- POST `/channels` — `{ name, code?, kind?, outlet_id?, commission_percent?, payment_method_code?, auto_accept?, prep_buffer_minutes?, is_active?, settings? }`. `code` defaults to a slug of the name and is unique per business. The response includes the one-time `secret` and the `webhook_url`; `payment_method_code` must be a payment method the business already has.
- PUT `/channels/{channel}`, DELETE `/channels/{channel}` — a channel with orders cannot be deleted (`422 CHANNEL_IN_USE`); deactivate it instead.
- POST `/channels/{channel}/rotate-secret` — issues a new token and secret, invalidating the old pair.
- GET `/channels/summary?from=…&to=…` — per channel: `orders`, `open_orders`, `gross`, `commission`, `net`.
- GET `/channels/{channel}/orders?status=all|active|received|accepted|…` — paginated channel orders.
- POST `/channels/{channel}/orders/{sale}/accept` — moves the order to `channel_status = accepted` and fires it to the kitchen (`422 CHANNEL_ERROR` once the order is closed).
- POST `/channels/{channel}/orders/{sale}/reject` — `{ reason }`; voids the order and notifies the channel.
- GET `/channels/{channel}/mappings`, PUT `/channels/{channel}/mappings` — `{ mappings: [{ external_item_id, external_variant_id?, product_id, product_variant_id?, option_map?, is_active? }] }`. The PUT replaces the whole set: rows missing from the payload are removed. `option_map` maps external option ids to modifier option ids.
- POST `/channels/{channel}/menu/push` — builds the channel's menu from its mappings (`external_item_id`, price, availability, mapped options) and queues a `menu.pushed` event.
- GET `/channels/{channel}/events?status=pending|sent|failed&type=…` — the outbound queue.
- POST `/channels/{channel}/events/{event}/ack` — `{ status: sent|failed, error? }`; stamps `sent_at` or records the failure.

### Inbound orders
Channel partners push orders to `POST /channel-webhooks/{token}`, which is public (no Sanctum) and signed with `X-Channel-Signature: hex HMAC-SHA256 of the raw body using the channel secret`. The token selects the channel, and with it the tenant. A bad signature returns `401 CHANNEL_AUTH`; an unknown or inactive channel returns `404`.

Payload: `{ event?: order.created|order.cancelled, external_order_id, outlet_id?, order_type?: delivery|pickup, placed_at?, notes?, promotion_code?, customer?: { name, phone, email, address }, fulfilment?: { contact_name, contact_phone, address, instructions, scheduled_at }, items: [{ external_item_id, external_variant_id?, quantity, options?: [external option id], notes? }] }`.

Prices are never taken from the payload: items resolve through the channel mapping and are priced by the server menu. Unmapped items or options fail with `422 CHANNEL_ERROR`. Guests are matched by phone and created when new. `external_order_id` is the idempotency key — replaying a push returns the existing order with `200` instead of `201`. An `order.cancelled` event voids the matching open order.

Inbound orders start as `channel_status = received` with `order_status = open`. `auto_accept` channels are accepted and fired immediately. Channel orders carry `channel_order_ref`, `channel_commission_percent` and `channel_commission` (snapshot of the percentage at order time). Accept, ready, dispatched, completed and cancelled transitions queue matching `order.*` events, each only once. At checkout an order whose channel has a `payment_method_code` is settled automatically with that rail, so the cashier never charges a prepaid marketplace guest twice. `POST /orders` also accepts `sales_channel_id` and `channel_order_ref` to tag an order taken at the till (a phone or WhatsApp order) to a channel.

## Offline-first register
Permission: `sales.create`.

- GET `/sync/bootstrap?outlet_id=…&customers_limit=…` — one snapshot for a register that has to keep selling when the network drops: `business`, `outlet`, `outlets`, the resolved `menu` (same shape as `GET /menu`, including modifier groups and availability), active `payment_methods`, active `tax_rates`, the last `customers_limit` (default 500, max 1000) customers by `updated_at`, and `server_time`. The outlet comes from `X-Outlet-Id`, then `outlet_id`, then the business's first outlet; another tenant's outlet returns `404`.

The snapshot deliberately carries the register's catalogue for anyone who can sell, so a cashier without `menu.view` can still take orders; the menu back office stays gated. Queued sales are replayed through the normal `POST /sales` call, whose `idempotency_key` makes a retry return the existing sale instead of charging twice — no separate sync push endpoint is needed.

## Advanced analytics
Permissions: `analytics.view` (read/export), `analytics.manage` (saved reports). All ranges default to the current month, accept `from`/`to`, and honour `outlet_id`. Money is always a 4-dp string.

- GET `/analytics/comparison?compare=previous_period|previous_year` — current vs previous `revenue`, `orders`, `average_order_value`, `gross_profit`, `discounts`, `tax`, `refunds`, each with `change_percent` (null when there is no base).
- GET `/analytics/products?limit=100` — per product: quantity, orders, revenue, cost, discounts, margin, margin %, revenue share, daily velocity and `abc_class` (A ≤ 80% cumulative revenue, B ≤ 95%, C after). Also returns `services` (appointments booked/completed and estimated service revenue).
- GET `/analytics/operational` — orders by status/type/hour, open orders, average order value, average prep minutes and low-stock count.
- GET `/analytics/financial` — revenue, COGS, gross profit, operating expenses (from `financial_transactions`), other income, net profit, tax, refunds, cash collected, AR/AP outstanding and gross margin %.
- GET `/analytics/export/{type}?format=csv|excel|pdf` — ad-hoc export of one of the four report types above.

### Saved reports
- GET `/saved-reports?type=…`, POST `/saved-reports` — `{ name, type, filters?, columns?, format?, schedule?, schedule_time?, recipients?, is_shared? }`. Types are `comparison`, `product_analytics`, `operational`, `financial`; formats `csv`, `excel`, `pdf`; schedules `none`, `daily`, `weekly`, `monthly`.
- PUT/DELETE `/saved-reports/{savedReport}`.
- POST `/saved-reports/{savedReport}/run` — builds the table now, writes a `report_runs` row (`status`, `row_count`, `started_at`, `finished_at`) and advances `next_run_at`.
- GET `/saved-reports/{savedReport}/runs` — run history.
- GET `/saved-reports/{savedReport}/download?format=csv|excel|pdf&from=…&to=…&outlet_id=…` — download. Excel is SpreadsheetML (`.xls`) and the PDF is generated in-process, so no extra extension or package is needed.
- `php artisan reports:deliver-due` runs due schedules (scheduled hourly in `routes/console.php`) and marks the run delivered.

## Storefront (e-commerce)
Admin permissions: `storefront.view`, `storefront.manage`, `storefront.orders`, plus `deliveries.manage` to dispatch. Public endpoints are unauthenticated; carts are addressed by an opaque token (`?token=` or `X-Cart-Token`).

- GET/POST `/storefronts`, GET/PUT/DELETE `/storefronts/{storefront}` — `{ name, slug, outlet_id?, domain?, currency?, description?, theme?, settings?, is_active? }`. `settings` carries `delivery_fee`, `tax_rate`, `min_order`, `delivery_enabled`, `pickup_enabled` and `payment_methods`.
- POST `/storefronts/{storefront}/publish|unpublish`.
- PUT `/storefronts/{storefront}/items` — replace the published catalogue: `{ items: [{ product_id, is_visible?, price_override?, sort_order? }] }`. Rows missing from the payload are removed.
- GET `/storefronts/{storefront}/orders?status=…` — paginated online orders; GET `/storefront-orders/{storefrontOrder}` for the full record.
- POST `/storefront-orders/{storefrontOrder}/status` — `{ status, message? }` through `pending → confirmed → preparing → ready → out_for_delivery → delivered` (or `completed` for pickup, `cancelled` before dispatch). Delivered/completed finalises the linked sale, deducts stock and records payment; cancelling releases the reservation and voids the draft sale.
- POST `/storefront-orders/{storefrontOrder}/payment` — `{ method?, reference? }` manual settlement.
- POST `/storefront-orders/{storefrontOrder}/delivery` — creates (or returns) the active delivery job.

Public surface:
- GET `/public/storefronts/{slug}` — storefront + published items with resolved price and available stock.
- POST `/public/storefronts/{slug}/cart` → cart token; GET `…/cart?token=`; POST `…/cart/items`; PUT/DELETE `…/cart/items/{productId}`.
- POST `/public/storefronts/{slug}/checkout` — `{ token, fulfilment_type: delivery|pickup, customer_name, customer_phone?, customer_email?, payment_method?, delivery_zone_id?, delivery_address?, notes?, scheduled_at? }`. Prices come from the storefront/cart snapshot, delivery fee from the zone or storefront settings, tax from `settings.tax_rate`. Checkout writes a draft sale, reserves tracked stock and creates a delivery for delivery orders.
- GET `/public/storefronts/{slug}/orders/{number}?token=…` — tracking timeline. The token returned at checkout is required.
- POST `/public/storefronts/{slug}/account/register|login` — shopper account (Sanctum token); GET `…/account` with that token lists the shopper's orders.

## Delivery & logistics
Permissions: `deliveries.view|manage` for jobs, `fleet.view|manage` for zones, drivers and vehicles.

- GET/POST `/delivery-zones`, PUT/DELETE `/delivery-zones/{deliveryZone}` — `{ name, outlet_id?, code?, fee?, min_order?, max_distance_km?, postcodes?, is_active? }`.
- GET/POST `/drivers`, PUT/DELETE `/drivers/{driver}` — drivers are soft-deactivated on DELETE; status is `available|on_delivery|off_duty`.
- GET/POST `/vehicles`, PUT/DELETE `/vehicles/{vehicle}` — plate numbers are unique per business.
- GET `/deliveries?status=…&driver_id=…&outlet_id=…&from=…&to=…`; GET `/deliveries/{delivery}`.
- POST `/deliveries` — `{ outlet_id, sale_id?, storefront_order_id?, delivery_zone_id?, recipient_name?, recipient_phone?, address?, fee?, distance_km?, sequence?, scheduled_at?, notes? }`. Lines are copied from the sale; fee defaults to the zone fee.
- GET `/deliveries/board?date=YYYY-MM-DD&outlet_id=…` — dispatch board with unassigned/in-progress/delivered counts and total fees.
- POST `/deliveries/{delivery}/assign` — `{ driver_id?, vehicle_id? }`; assigns and marks the driver/vehicle busy.
- POST `/deliveries/{delivery}/status` — `{ status, note?, latitude?, longitude? }` through `pending → scheduled|assigned → picked_up → in_transit → delivered` (or `failed`/`cancelled`). Each step writes a `delivery_events` row; dispatching/delivering also advances a linked storefront order when its state allows it.
- POST `/deliveries/{delivery}/proof` — `{ recipient_name?, photo_url?, signature?, notes?, collected_amount? }`; records proof and marks the job delivered.
- POST `/deliveries/{delivery}/partial` — `{ items: [{ delivery_line_id? | sale_item_id?, quantity }] }`; marks the delivered portion and creates a follow-up job with the remainder.
- POST `/deliveries/{delivery}/reconcile` — `{ collected_amount, notes? }` once delivered; stamps `reconciled_at`/`reconciled_by`.

## Platform administration
Platform-admin only (`users.is_platform_admin`, 403 otherwise). Routes live under `/admin` outside the tenant middleware because one administrator manages every business.

- GET `/admin/dashboard` — businesses (total/active/new this month and counts per subscription status), users, commerce this month (sales, revenue, paid invoices, MRR) and health (open tickets, failed webhooks and payment callbacks in the last 24 h, past-due subscriptions), plus the eight newest signups.
- GET `/admin/tenants?q=…&status=…&industry=…&plan=…&subscription_status=…` — tenant directory with outlet/member/invoice counts and subscription summary.
- GET `/admin/tenants/{business}` — one tenant: profile, subscription, lifetime sales, outlets, recent tickets and audit entries.
- GET `/admin/monitoring` — recent failed webhook deliveries and payment webhooks, past-due subscriptions, top audit actions in 24 h and row counts.
- GET `/admin/settings`, PUT `/admin/settings` — `{ settings: { key: value } }` over the `platform_settings` store. GET also returns the defaults from `config/platform.php`.
- GET `/admin/tickets?status=…&priority=…&q=…` — every tenant ticket plus per-status counts; GET `/admin/tickets/{ticket}`.
- POST `/admin/tickets/{ticket}/reply` — `{ body, attachments?, status? }`; defaults the status to `pending` and notifies the ticket creator.
- PUT `/admin/tickets/{ticket}` — `{ status?, priority?, assigned_to? }`; `closed` stamps `closed_at`, any other status clears it.

### Support tickets (tenant side)
Permissions: `support.view`, `support.manage`.

- GET/POST `/support/tickets` — `{ subject, category?, priority?, body, attachments? }`; the number defaults to `TKT-XXXXXXXX`. Platform admins receive an in-app notification.
- GET `/support/tickets/{ticket}` — ticket plus the full message thread.
- POST `/support/tickets/{ticket}/reply` — `{ body, attachments? }`; reopened tickets return to `open`; resolved or closed tickets reject replies with `422 TICKET_CLOSED`.

## API keys
Permissions: `api_keys.view`, `api_keys.manage`.

- GET `/api-keys` — prefixes only, never the secret or hash.
- POST `/api-keys` — `{ name, scopes?: [read|write], rate_limit?, expires_at? }`. The response carries `key` (plaintext) **once**.
- PUT/DELETE `/api-keys/{apiKey}` — rename, re-scope, re-limit or revoke (`revoked_at`).
- POST `/api-keys/{apiKey}/rotate` — new secret, old one stops working immediately.

A key is sent as `X-API-Key: vlr_…` (or `Authorization: Bearer vlr_…`) and can be used on any tenant endpoint a bearer token can. It authenticates as the user who created it, so permissions and tenant scoping are unchanged, but the business is pinned to the key's business — a caller cannot widen it with `X-Business-Id`. Keys are stored only as a SHA-256 hash. Read-only keys (`scopes: [read]`) reject writes with 403; each key enforces its own `rate_limit` per minute (429 when exceeded). Expired or revoked keys return 401.

## Webhooks
Permissions: `webhooks.view`, `webhooks.manage`.

- GET `/webhooks/endpoints` — endpoints plus the supported `events`.
- POST `/webhooks/endpoints` — `{ name, url, events?: […], description?, is_active? }`. An empty `events` list subscribes to everything; the signing `secret` is returned once.
- PUT/DELETE `/webhooks/endpoints/{webhookEndpoint}`; DELETE removes its delivery log.
- POST `/webhooks/endpoints/{webhookEndpoint}/rotate-secret`.
- POST `/webhooks/endpoints/{webhookEndpoint}/test` — sends `webhook.test` and records the delivery.
- GET `/webhooks/deliveries?status=pending|delivered|failed&endpoint_id=…&event=…` — paginated log.
- POST `/webhooks/deliveries/{webhookDelivery}/retry`.

Events: `sale.completed`, `sale.voided`, `order.created`, `order.status_changed`, `payment.recorded`, `inventory.low_stock`, `subscription.updated`. `sale.completed` and `sale.voided` are dispatched from the sales endpoints today; other events can be dispatched through `WebhookService::dispatch()`.

Each request is `POST` with a JSON body `{ id, event, business_id, occurred_at, data }` and headers `X-Velora-Event`, `X-Velora-Delivery` and `X-Velora-Signature: t=<unix>,v1=<hex>`. Verify with `hash_hmac('sha256', "<t>.<raw body>", secret)` and reject stale timestamps. Failures are logged with the response status/body, back off at 1/5/15/60 minutes and can be retried manually. A broken endpoint never fails the sale that triggered it.

## Notification centre
No extra permission: a user only ever sees their own inbox (scoped to their businesses).

- GET `/notifications?unread_only=1&type=…` — paginated list plus `unread_count`.
- GET `/notifications/unread-count`, POST `/notifications/read-all`.
- POST `/notifications/{notification}/read`, DELETE `/notifications/{notification}`.

## Documents
Permissions: `documents.view`, `documents.manage`.

- GET `/documents?type=…&outlet_id=…&q=…` — metadata and a `download_url`.
- POST `/documents` — multipart `file` (max 10 MB) with optional `name`, `type`, `outlet_id`, `description`, `metadata`. Files land on the `local` disk under `documents/{business_id}`.
- GET `/documents/{document}/download` — streams the file.
- DELETE `/documents/{document}` — deletes the row and the stored file.

## Response format
Success: `{ "success": true, "message": "...", "data": {}, "meta": {} }`
Error: `{ "success": false, "message": "...", "errors": {}, "code": "...", "request_id": "..." }`

## Money
Decimal strings with 4 decimal places. Never floating point.

## Timestamps
ISO 8601 (UTC).

## Permissions
Granular permission strings (e.g. `sales.create`, `products.manage`) enforced server-side via role permissions.

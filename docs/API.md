# Velora POS Backend API

Base URL: `/api/v1`

## Authentication
- POST `/auth/register` - register user
- POST `/auth/login` - login, returns bearer token
- POST `/auth/logout` - revoke current token (auth)
- POST `/auth/change-password` - change password (auth)
- POST `/auth/forgot-password` - request reset link
- POST `/auth/reset-password` - reset password
- GET `/me` - current user profile (auth)

## Multi-tenant context
Protected endpoints require `Authorization: Bearer <token>`. Tenant context resolved via `X-Business-Id` and optional `X-Outlet-Id` headers (or `business_id`/`outlet_id` body params). Platform admins may access any business.

## Modules
Businesses, Outlets, Devices, Memberships, Roles, Permissions, Categories, Products, Customers, Suppliers, Inventory (balances/adjust/movements, stock transfers), Ingredients (balances/adjust/movements), Recipes (BOM), Tax Rates, Discounts, Sales (create/void/receipt), Orders (open tabs, kitchen flow, checkout, cancel), Floor Plan (areas, tables), Kitchen (stations, tickets, print), Payment Methods, Payments (split batches, refunds), Refunds (filter, approve, reject), Purchase Orders (create/approve/receive), Cash Registers, Shifts (open/close/cash-movement), Reports (sales-summary, sales-by-product, inventory-balances, low-stock), Audit Logs, Sync (cursor/pull/push), Webhooks (payment/{provider}), Menu (modifier groups/options, availability, 86, dayparts).

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

## Response format
Success: `{ "success": true, "message": "...", "data": {}, "meta": {} }`
Error: `{ "success": false, "message": "...", "errors": {}, "code": "...", "request_id": "..." }`

## Money
Decimal strings with 4 decimal places. Never floating point.

## Timestamps
ISO 8601 (UTC).

## Permissions
Granular permission strings (e.g. `sales.create`, `products.manage`) enforced server-side via role permissions.

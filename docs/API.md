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
Businesses, Outlets, Devices, Memberships, Roles, Permissions, Categories, Products, Customers, Suppliers, Inventory (balances/adjust/movements, stock transfers), Ingredients (balances/adjust/movements), Recipes (BOM), Tax Rates, Discounts, Sales (create/void/receipt), Payments (create/refund), Refunds (approve/reject), Purchase Orders (create/approve/receive), Cash Registers, Shifts (open/close/cash-movement), Reports (sales-summary, sales-by-product, inventory-balances, low-stock), Audit Logs, Sync (cursor/pull/push), Webhooks (payment/{provider}), Menu (modifier groups/options, availability, 86, dayparts).

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

## Response format
Success: `{ "success": true, "message": "...", "data": {}, "meta": {} }`
Error: `{ "success": false, "message": "...", "errors": {}, "code": "...", "request_id": "..." }`

## Money
Decimal strings with 4 decimal places. Never floating point.

## Timestamps
ISO 8601 (UTC).

## Permissions
Granular permission strings (e.g. `sales.create`, `products.manage`) enforced server-side via role permissions.

# Customer Portal Database Schema Fixes

## Issue
The customer portal pages were created with incorrect column names that don't match the actual database schema.

---

## Database Schema - Actual Column Names

### Orders Table
| Expected (Wrong) | Actual (Correct) |
|-----------------|------------------|
| `order_date` | `created_at` |
| `total_amount_usd` | `total_usd` |
| `total_amount_lbp` | `total_lbp` |

**Full Schema:**
- `id`, `order_number`, `order_type`, `status`
- `customer_id`, `sales_rep_id`, `exchange_rate_id`
- `total_usd`, `total_lbp`
- `notes`, `delivery_date`, `qr_token_id`
- `created_at`, `updated_at`, `invoice_ready`

### Invoices Table
| Expected (Wrong) | Actual (Correct) |
|-----------------|------------------|
| `total_amount_usd` | `total_usd` |
| `total_amount_lbp` | `total_lbp` |
| `paid_amount_usd` | Calculate from `payments` table |
| `paid_amount_lbp` | Calculate from `payments` table |

**Full Schema:**
- `id`, `invoice_number`, `order_id`, `sales_rep_id`
- `status` (enum: draft, issued, paid, voided)
- `total_usd`, `total_lbp`
- `issued_at`, `due_date`, `created_at`

**Note:** Invoices don't have `paid_amount_*` columns. Paid amounts must be calculated by joining with the `payments` table.

### Payments Table
**Schema:**
- `id`, `invoice_id`, `method` (enum: cash, qr_cash, card, bank, other)
- `amount_usd`, `amount_lbp`
- `received_by_user_id`, `received_at`, `qr_token_id`
- `external_ref`, `created_at`

---

## Fixes Applied

### ✅ Dashboard (`pages/customer/dashboard.php`)

#### 1. Orders Query Fixed
```php
// OLD (Wrong)
SELECT o.order_date, o.total_amount_usd, o.total_amount_lbp
FROM orders o
ORDER BY o.order_date DESC

// NEW (Correct)
SELECT o.created_at as order_date, o.total_usd as total_amount_usd, o.total_lbp as total_amount_lbp
FROM orders o
ORDER BY o.created_at DESC
```

#### 2. Invoices Query Fixed
```php
// OLD (Wrong)
SELECT i.total_amount_usd, i.paid_amount_usd
FROM invoices i

// NEW (Correct)
SELECT
    i.total_usd as total_amount_usd,
    COALESCE(SUM(p.amount_usd), 0) as paid_amount_usd
FROM invoices i
LEFT JOIN payments p ON p.invoice_id = i.id
GROUP BY i.id
```

#### 3. Statistics Query Fixed
```php
// OLD (Wrong)
SELECT COALESCE(SUM(o.total_amount_usd), 0) as total_spent_usd

// NEW (Correct)
SELECT COALESCE(SUM(o.total_usd), 0) as total_spent_usd
```

### ✅ Database Connection Fixed
Added `$pdo = db();` to all customer portal pages after `customer_portal_bootstrap()`.

---

## Pages That Need Similar Fixes

The following pages likely have the same column name issues and will need to be fixed when accessed:

### Order Pages
- ❌ `pages/customer/orders.php` - Needs order column fixes
- ❌ `pages/customer/order_details.php` - Needs order + invoice column fixes

### Invoice Pages
- ❌ `pages/customer/invoices.php` - Needs invoice + payment join
- ❌ `pages/customer/invoice_details.php` - Needs invoice + payment join

### Financial Pages
- ❌ `pages/customer/payments.php` - May need fixes
- ❌ `pages/customer/statements.php` - Needs invoice + payment join

### Other Pages
- ⚠️ `pages/customer/checkout.php` - May need order column fixes
- ✅ `pages/customer/cart.php` - Likely OK (uses customer_cart table)
- ✅ `pages/customer/products.php` - Likely OK (uses products table)
- ✅ `pages/customer/profile.php` - Likely OK (uses customers table)
- ✅ `pages/customer/contact.php` - Likely OK (uses customer_messages table)

---

## Fix Pattern

When fixing other pages, use this pattern:

### For Orders:
```php
// Use column aliases to maintain compatibility
SELECT
    o.created_at as order_date,
    o.total_usd as total_amount_usd,
    o.total_lbp as total_amount_lbp
FROM orders o
```

### For Invoices with Payments:
```php
// Calculate paid amounts from payments table
SELECT
    i.id,
    i.total_usd as total_amount_usd,
    i.total_lbp as total_amount_lbp,
    COALESCE(SUM(p.amount_usd), 0) as paid_amount_usd,
    COALESCE(SUM(p.amount_lbp), 0) as paid_amount_lbp
FROM invoices i
LEFT JOIN payments p ON p.invoice_id = i.id
GROUP BY i.id
```

---

## Testing Checklist

### ✅ Completed
- [x] Dashboard loads successfully
- [x] Orders displayed correctly
- [x] Invoices displayed correctly
- [x] Statistics calculated correctly

### 🔄 To Test
- [ ] Orders page - filter and search
- [ ] Order details page - full order info
- [ ] Invoices page - filter and payment status
- [ ] Invoice details page - payment history
- [ ] Payments page - payment list
- [ ] Statements page - account activity
- [ ] Checkout - order creation

---

## Status

✅ **Dashboard Fixed and Working**

⚠️ **Other pages need fixes as they're accessed**

---

**Fix Date**: 2025-11-21
**Files Fixed**: 1 (dashboard.php)
**Files Pending**: 6 (orders, order_details, invoices, invoice_details, payments, statements)

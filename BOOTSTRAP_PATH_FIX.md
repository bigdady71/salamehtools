# Bootstrap Path Fix - Customer Portal

## Issue
All customer portal pages were referencing an incorrect bootstrap.php path:
- **Incorrect Path**: `../../config/bootstrap.php`
- **Correct Path**: `../../includes/bootstrap.php`

This caused the following error when accessing any customer portal page:
```
Warning: require_once(C:\xampp\htdocs\salamehtools\pages\customer/../../config/bootstrap.php):
Failed to open stream: No such file or directory
```

---

## Files Fixed (13 files)

All customer portal pages have been corrected:

### Authentication Pages
1. ✅ `pages/customer/login.php`
2. ✅ `pages/customer/logout.php` (no bootstrap required)

### Main Portal Pages
3. ✅ `pages/customer/dashboard.php`
4. ✅ `pages/customer/profile.php`
5. ✅ `pages/customer/products.php`
6. ✅ `pages/customer/cart.php`
7. ✅ `pages/customer/checkout.php`

### Order Management Pages
8. ✅ `pages/customer/orders.php`
9. ✅ `pages/customer/order_details.php`

### Financial Pages
10. ✅ `pages/customer/invoices.php`
11. ✅ `pages/customer/invoice_details.php`
12. ✅ `pages/customer/payments.php`
13. ✅ `pages/customer/statements.php`

### Communication Page
14. ✅ `pages/customer/contact.php`

---

## Change Applied

Each file had line 5 changed from:
```php
require_once __DIR__ . '/../../config/bootstrap.php';
```

To:
```php
require_once __DIR__ . '/../../includes/bootstrap.php';
```

---

## Status

✅ **ALL CUSTOMER PORTAL FILES FIXED**

The customer portal should now load correctly without any bootstrap path errors.

---

## Next Steps

1. Test customer portal login
2. Verify all pages load correctly
3. Test JavaScript enhancements
4. Verify light theme consistency across all pages

---

**Fixed Date**: 2025-11-21
**Status**: COMPLETE ✅

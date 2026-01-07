# Arabic Localization Plan for Salameh Tools

## Project Goal
Convert the entire website to Arabic language while keeping the Admin Panel in English.

---

## Phase 1: Database & Infrastructure (Week 1)

### 1.1 Create Translations Table
```sql
CREATE TABLE translations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    translation_key VARCHAR(255) NOT NULL UNIQUE,
    english_text TEXT NOT NULL,
    arabic_text TEXT NOT NULL,
    context VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (translation_key)
);
```

### 1.2 Create Language Helper Functions
**File:** `/includes/lang.php`
```php
function t($key, $default = null) {
    // Return translation based on user role
    // Sales reps & customers → Arabic
    // Admins → English
}

function get_user_language() {
    // Determine language based on user role
    // sales_rep, customer → 'ar'
    // admin → 'en'
}
```

### 1.3 Add Language Column to Users Table
```sql
ALTER TABLE users ADD COLUMN preferred_language VARCHAR(5) DEFAULT 'ar' AFTER role;
-- Set admins to English
UPDATE users SET preferred_language = 'en' WHERE role = 'admin';
```

---

## Phase 2: CSS & Layout Adjustments (Week 1-2)

### 2.1 RTL (Right-to-Left) Support
**File:** `/includes/sales_portal.php` and `/includes/customer_portal.php`

Add conditional RTL:
```php
function get_direction() {
    $lang = get_user_language();
    return $lang === 'ar' ? 'rtl' : 'ltr';
}
```

**CSS Changes:**
```css
/* Add to layout files */
[dir="rtl"] {
    direction: rtl;
    text-align: right;
}

[dir="rtl"] .sidebar {
    right: 0;
    left: auto;
}

[dir="rtl"] .product-card {
    text-align: right;
}
```

### 2.2 Font Support
Add Arabic font (Tajawal or Cairo):
```html
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
```

```css
body[dir="rtl"] {
    font-family: 'Tajawal', sans-serif;
}
```

---

## Phase 3: Translation Implementation (Week 2-4)

### 3.1 Sales Portal Pages (Priority)
**Pages to translate:**
1. `/pages/sales/dashboard.php` - Dashboard
2. `/pages/sales/orders/van_stock_sales.php` - Create New Sale
3. `/pages/sales/orders.php` - My Orders
4. `/pages/sales/users.php` - My Customers
5. `/pages/sales/add_customer.php` - Add New Customer
6. `/pages/sales/van_stock.php` - My Van Stock
7. `/pages/sales/products.php` - Product Catalog

**Translation Keys Example:**
```php
// English: "Create New Sale"
// Arabic: "إنشاء عملية بيع جديدة"
echo t('create_new_sale', 'Create New Sale');

// English: "Customer Name or Phone"
// Arabic: "اسم العميل أو رقم الهاتف"
echo t('customer_name_or_phone', 'Customer Name or Phone');
```

### 3.2 Customer Portal Pages
**Pages to translate:**
1. `/pages/customer/dashboard.php` - Dashboard
2. `/pages/customer/products.php` - Browse Products
3. `/pages/customer/cart.php` - Shopping Cart
4. `/pages/customer/orders.php` - My Orders
5. `/pages/customer/invoices.php` - Invoices
6. `/pages/customer/payments.php` - Payment History
7. `/pages/customer/statements.php` - Account Statements

### 3.3 Common Elements
- Navigation menus
- Buttons (Save, Cancel, Submit, etc.)
- Form labels
- Error messages
- Success messages
- Validation messages

---

## Phase 4: Data Translation (Week 4-5)

### 4.1 Product Names
**Option 1:** Add Arabic column to products table
```sql
ALTER TABLE products
ADD COLUMN item_name_ar VARCHAR(255) NULL AFTER item_name,
ADD COLUMN description_ar TEXT NULL AFTER description;
```

**Option 2:** Use translation table with foreign keys
```sql
CREATE TABLE product_translations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    language VARCHAR(5) NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_product_lang (product_id, language)
);
```

### 4.2 Category Names
Add Arabic translations for categories:
```sql
CREATE TABLE category_translations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,
    language VARCHAR(5) NOT NULL,
    translated_name VARCHAR(100) NOT NULL,
    UNIQUE KEY unique_category_lang (category_name, language)
);
```

### 4.3 Status Labels
Translate order statuses, payment statuses, etc.:
- Pending → قيد الانتظار
- Completed → مكتمل
- Cancelled → ملغى
- Paid → مدفوع
- Unpaid → غير مدفوع

---

## Phase 5: Number & Date Formatting (Week 5)

### 5.1 Number Formatting
```php
function format_number($number, $decimals = 2) {
    $lang = get_user_language();
    if ($lang === 'ar') {
        // Arabic numerals: ١٢٣٤٥٦٧٨٩٠
        return convert_to_arabic_numerals(number_format($number, $decimals));
    }
    return number_format($number, $decimals);
}
```

### 5.2 Date Formatting
```php
function format_date($date, $format = 'Y-m-d') {
    $lang = get_user_language();
    if ($lang === 'ar') {
        // Arabic month names
        return format_arabic_date($date);
    }
    return date($format, strtotime($date));
}
```

---

## Phase 6: Admin Panel Exclusion (Week 5-6)

### 6.1 Keep Admin Pages in English
All pages under `/pages/admin/` remain English:
- Dashboard
- Settings
- User Management
- Reports
- System Configuration

### 6.2 Mixed Content Handling
For pages showing data to admins:
```php
// Show product name based on viewer
if (is_admin()) {
    echo $product['item_name']; // English
} else {
    echo $product['item_name_ar'] ?: $product['item_name']; // Arabic fallback to English
}
```

---

## Phase 7: Testing & QA (Week 6-7)

### 7.1 Test Cases
- [ ] Sales rep login → All pages in Arabic + RTL
- [ ] Customer login → All pages in Arabic + RTL
- [ ] Admin login → All pages in English + LTR
- [ ] Forms submit correctly with Arabic text
- [ ] Search works with Arabic keywords
- [ ] Filters work with Arabic options
- [ ] Reports show correct Arabic text
- [ ] PDFs generate with Arabic support
- [ ] Emails sent in correct language

### 7.2 Browser Testing
- Chrome
- Firefox
- Safari
- Edge
- Mobile browsers (iOS/Android)

---

## Phase 8: Deployment (Week 7)

### 8.1 Pre-Deployment
1. Backup database
2. Test on staging environment
3. Verify all translations are complete
4. Check RTL layout on all pages

### 8.2 Deployment Steps
1. Run database migrations
2. Upload updated files
3. Clear PHP opcache
4. Test critical flows
5. Monitor error logs

### 8.3 Post-Deployment
1. User feedback collection
2. Fix any layout issues
3. Add missing translations
4. Performance optimization

---

## Translation Priority List

### High Priority (Must Translate First)
1. Login/Logout
2. Navigation menus
3. Dashboard
4. Create New Sale page
5. Products catalog
6. Shopping cart
7. Orders page
8. Error messages

### Medium Priority
1. Customer management
2. Payment pages
3. Invoices
4. Reports
5. Settings pages

### Low Priority
1. Help text
2. Tooltips
3. Footer links
4. Email templates

---

## Technical Considerations

### RTL CSS Issues to Handle
```css
/* Flexbox direction */
[dir="rtl"] .flex-row {
    flex-direction: row-reverse;
}

/* Margins and padding */
[dir="rtl"] .ml-4 {
    margin-left: 0;
    margin-right: 1rem;
}

/* Text alignment */
[dir="rtl"] .text-left {
    text-align: right;
}

/* Icons and arrows */
[dir="rtl"] .arrow-right::after {
    content: "←"; /* Flip arrow */
}
```

### Database Collation
Ensure tables support Arabic:
```sql
ALTER DATABASE salamaehtools CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE products CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

---

## Translation File Structure

### Option 1: PHP Arrays
**File:** `/lang/ar.php`
```php
<?php
return [
    'dashboard' => 'لوحة التحكم',
    'create_new_sale' => 'إنشاء عملية بيع جديدة',
    'customer_name' => 'اسم العميل',
    'phone_number' => 'رقم الهاتف',
    'search' => 'بحث',
    'submit' => 'إرسال',
    'cancel' => 'إلغاء',
    // ... more translations
];
```

**File:** `/lang/en.php`
```php
<?php
return [
    'dashboard' => 'Dashboard',
    'create_new_sale' => 'Create New Sale',
    'customer_name' => 'Customer Name',
    'phone_number' => 'Phone Number',
    'search' => 'Search',
    'submit' => 'Submit',
    'cancel' => 'Cancel',
    // ... more translations
];
```

### Option 2: Database (Recommended for Large Scale)
Allows runtime translation updates without code deployment.

---

## Timeline Summary

| Phase | Duration | Deliverable |
|-------|----------|-------------|
| Phase 1 | Week 1 | Database & infrastructure ready |
| Phase 2 | Week 1-2 | RTL CSS implemented |
| Phase 3 | Week 2-4 | All pages translated |
| Phase 4 | Week 4-5 | Data translated |
| Phase 5 | Week 5 | Formatting complete |
| Phase 6 | Week 5-6 | Admin panel excluded |
| Phase 7 | Week 6-7 | Testing complete |
| Phase 8 | Week 7 | Deployed to production |

**Total Estimated Time:** 7 weeks

---

## Sample Translations

### Common UI Elements
| English | Arabic |
|---------|--------|
| Dashboard | لوحة التحكم |
| Create New Sale | إنشاء عملية بيع جديدة |
| My Orders | طلباتي |
| My Customers | عملائي |
| Add New Customer | إضافة عميل جديد |
| Product Catalog | كتالوج المنتجات |
| Shopping Cart | عربة التسوق |
| Checkout | الدفع |
| Browse Products | تصفح المنتجات |
| Search | بحث |
| Filter | تصفية |
| Sort By | ترتيب حسب |
| Price | السعر |
| Quantity | الكمية |
| Total | الإجمالي |
| Subtotal | المجموع الفرعي |
| Discount | خصم |
| Tax | ضريبة |
| Save | حفظ |
| Cancel | إلغاء |
| Submit | إرسال |
| Delete | حذف |
| Edit | تعديل |
| View | عرض |
| Download | تحميل |
| Print | طباعة |
| Export | تصدير |
| Import | استيراد |
| Upload | رفع |
| Loading... | جاري التحميل... |
| Success | نجح |
| Error | خطأ |
| Warning | تحذير |
| Info | معلومات |

### Form Labels
| English | Arabic |
|---------|--------|
| Customer Name or Phone | اسم العميل أو رقم الهاتف |
| Product Name | اسم المنتج |
| Category | الفئة |
| Stock Status | حالة المخزون |
| In Stock | متوفر |
| Out of Stock | غير متوفر |
| Low Stock | مخزون منخفض |
| Governorate | المحافظة |
| City | المدينة |
| Address | العنوان |
| Phone Number | رقم الهاتف |
| Email | البريد الإلكتروني |
| Password | كلمة المرور |
| Confirm Password | تأكيد كلمة المرور |

### Messages
| English | Arabic |
|---------|--------|
| Please fill in all required fields | الرجاء ملء جميع الحقول المطلوبة |
| Customer created successfully | تم إنشاء العميل بنجاح |
| Order placed successfully | تم تقديم الطلب بنجاح |
| Are you sure you want to delete? | هل أنت متأكد أنك تريد الحذف؟ |
| No results found | لم يتم العثور على نتائج |
| Invalid phone number | رقم هاتف غير صحيح |
| Password must be at least 4 characters | يجب أن تكون كلمة المرور 4 أحرف على الأقل |

---

## Next Steps

1. **Decide on translation method:** Database vs PHP files
2. **Create translation helper functions**
3. **Set up RTL CSS framework**
4. **Start translating high-priority pages**
5. **Test with real users**
6. **Iterate based on feedback**

---

## Notes

- Keep English as fallback if Arabic translation is missing
- Use UTF-8 encoding everywhere
- Test with real Arabic text (not Lorem Ipsum)
- Consider right-to-left number formatting preferences
- Preserve English product SKUs and technical IDs
- Admin panel stays 100% English
- Consider adding language switcher for bilingual users in the future

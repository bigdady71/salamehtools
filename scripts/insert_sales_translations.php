<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/lang.php';

/**
 * Insert initial translations for Sales Portal
 * Run this script once to populate the translations table
 */

$translations = [
    // Navigation menu items
    'nav.dashboard' => [
        'en' => '🏠 Dashboard',
        'ar' => '🏠 لوحة التحكم'
    ],
    'nav.create_sale' => [
        'en' => '🚚 Create New Sale',
        'ar' => '🚚 إنشاء عملية بيع جديدة'
    ],
    'nav.my_orders' => [
        'en' => '📋 My Orders',
        'ar' => '📋 طلباتي'
    ],
    'nav.my_customers' => [
        'en' => '👥 My Customers',
        'ar' => '👥 عملائي'
    ],
    'nav.add_customer' => [
        'en' => '➕ Add New Customer',
        'ar' => '➕ إضافة عميل جديد'
    ],
    'nav.van_stock' => [
        'en' => '📦 My Van Stock',
        'ar' => '📦 مخزون الشاحنة'
    ],
    'nav.stock_auth' => [
        'en' => '🔐 Stock Authorizations',
        'ar' => '🔐 تفويضات المخزون'
    ],
    'nav.warehouse_stock' => [
        'en' => '🏭 Warehouse Stock',
        'ar' => '🏭 مخزون المستودع'
    ],
    'nav.company_order' => [
        'en' => '🏢 Company Order',
        'ar' => '🏢 طلب الشركة'
    ],
    'nav.invoices' => [
        'en' => '💵 Invoices',
        'ar' => '💵 الفواتير'
    ],
    'nav.collections' => [
        'en' => '💰 Collections',
        'ar' => '💰 التحصيلات'
    ],
    'nav.products' => [
        'en' => '📦 All Products',
        'ar' => '📦 جميع المنتجات'
    ],
    'nav.performance' => [
        'en' => '📊 My Performance',
        'ar' => '📊 أدائي'
    ],

    // Common buttons
    'btn.save' => [
        'en' => 'Save',
        'ar' => 'حفظ'
    ],
    'btn.cancel' => [
        'en' => 'Cancel',
        'ar' => 'إلغاء'
    ],
    'btn.submit' => [
        'en' => 'Submit',
        'ar' => 'إرسال'
    ],
    'btn.search' => [
        'en' => 'Search',
        'ar' => 'بحث'
    ],
    'btn.filter' => [
        'en' => 'Filter',
        'ar' => 'تصفية'
    ],
    'btn.delete' => [
        'en' => 'Delete',
        'ar' => 'حذف'
    ],
    'btn.edit' => [
        'en' => 'Edit',
        'ar' => 'تعديل'
    ],
    'btn.view' => [
        'en' => 'View',
        'ar' => 'عرض'
    ],
    'btn.download' => [
        'en' => 'Download',
        'ar' => 'تحميل'
    ],
    'btn.print' => [
        'en' => 'Print',
        'ar' => 'طباعة'
    ],
    'btn.export' => [
        'en' => 'Export',
        'ar' => 'تصدير'
    ],
    'btn.logout' => [
        'en' => '🚪 Logout',
        'ar' => '🚪 تسجيل خروج'
    ],

    // Common labels
    'label.customer_name' => [
        'en' => 'Customer Name',
        'ar' => 'اسم العميل'
    ],
    'label.phone_number' => [
        'en' => 'Phone Number',
        'ar' => 'رقم الهاتف'
    ],
    'label.email' => [
        'en' => 'Email',
        'ar' => 'البريد الإلكتروني'
    ],
    'label.address' => [
        'en' => 'Address',
        'ar' => 'العنوان'
    ],
    'label.governorate' => [
        'en' => 'Governorate',
        'ar' => 'المحافظة'
    ],
    'label.city' => [
        'en' => 'City/Town',
        'ar' => 'المدينة/البلدة'
    ],
    'label.product_name' => [
        'en' => 'Product Name',
        'ar' => 'اسم المنتج'
    ],
    'label.category' => [
        'en' => 'Category',
        'ar' => 'الفئة'
    ],
    'label.price' => [
        'en' => 'Price',
        'ar' => 'السعر'
    ],
    'label.quantity' => [
        'en' => 'Quantity',
        'ar' => 'الكمية'
    ],
    'label.total' => [
        'en' => 'Total',
        'ar' => 'الإجمالي'
    ],
    'label.subtotal' => [
        'en' => 'Subtotal',
        'ar' => 'المجموع الفرعي'
    ],
    'label.discount' => [
        'en' => 'Discount',
        'ar' => 'خصم'
    ],
    'label.tax' => [
        'en' => 'Tax',
        'ar' => 'ضريبة'
    ],
    'label.date' => [
        'en' => 'Date',
        'ar' => 'التاريخ'
    ],
    'label.status' => [
        'en' => 'Status',
        'ar' => 'الحالة'
    ],

    // Status labels
    'status.pending' => [
        'en' => 'Pending',
        'ar' => 'قيد الانتظار'
    ],
    'status.completed' => [
        'en' => 'Completed',
        'ar' => 'مكتمل'
    ],
    'status.cancelled' => [
        'en' => 'Cancelled',
        'ar' => 'ملغى'
    ],
    'status.paid' => [
        'en' => 'Paid',
        'ar' => 'مدفوع'
    ],
    'status.unpaid' => [
        'en' => 'Unpaid',
        'ar' => 'غير مدفوع'
    ],
    'status.in_stock' => [
        'en' => 'In Stock',
        'ar' => 'متوفر'
    ],
    'status.out_of_stock' => [
        'en' => 'Out of Stock',
        'ar' => 'غير متوفر'
    ],
    'status.low_stock' => [
        'en' => 'Low Stock',
        'ar' => 'مخزون منخفض'
    ],

    // Messages
    'msg.loading' => [
        'en' => 'Loading...',
        'ar' => 'جاري التحميل...'
    ],
    'msg.success' => [
        'en' => 'Success',
        'ar' => 'نجح'
    ],
    'msg.error' => [
        'en' => 'Error',
        'ar' => 'خطأ'
    ],
    'msg.warning' => [
        'en' => 'Warning',
        'ar' => 'تحذير'
    ],
    'msg.info' => [
        'en' => 'Info',
        'ar' => 'معلومات'
    ],
    'msg.no_results' => [
        'en' => 'No results found',
        'ar' => 'لم يتم العثور على نتائج'
    ],
    'msg.confirm_delete' => [
        'en' => 'Are you sure you want to delete?',
        'ar' => 'هل أنت متأكد أنك تريد الحذف؟'
    ],
    'msg.fill_required' => [
        'en' => 'Please fill in all required fields',
        'ar' => 'الرجاء ملء جميع الحقول المطلوبة'
    ],
    'msg.customer_created' => [
        'en' => 'Customer created successfully',
        'ar' => 'تم إنشاء العميل بنجاح'
    ],
    'msg.order_placed' => [
        'en' => 'Order placed successfully',
        'ar' => 'تم تقديم الطلب بنجاح'
    ],

    // Governorates
    'gov.beirut' => [
        'en' => 'Beirut',
        'ar' => 'بيروت'
    ],
    'gov.mount_lebanon' => [
        'en' => 'Mount Lebanon',
        'ar' => 'جبل لبنان'
    ],
    'gov.north' => [
        'en' => 'North',
        'ar' => 'الشمال'
    ],
    'gov.south' => [
        'en' => 'South',
        'ar' => 'الجنوب'
    ],
    'gov.beqaa' => [
        'en' => 'Beqaa',
        'ar' => 'البقاع'
    ],
    'gov.nabatieh' => [
        'en' => 'Nabatieh',
        'ar' => 'النبطية'
    ],
    'gov.akkar' => [
        'en' => 'Akkar',
        'ar' => 'عكار'
    ],
    'gov.baalbek_hermel' => [
        'en' => 'Baalbek-Hermel',
        'ar' => 'بعلبك الهرمل'
    ],
    'gov.all_governorates' => [
        'en' => 'All Governorates',
        'ar' => 'كل المحافظات'
    ],

    // Common phrases
    'phrase.sales_rep' => [
        'en' => 'Sales Representative',
        'ar' => 'مندوب مبيعات'
    ],
    'phrase.signed_in' => [
        'en' => 'Signed in',
        'ar' => 'تم تسجيل الدخول'
    ],
    'phrase.sales_portal' => [
        'en' => 'SALES PORTAL',
        'ar' => 'بوابة المبيعات'
    ],
];

try {
    $inserted = insert_translations($translations);
    echo "✅ Successfully inserted {$inserted} translations into the database.\n";
    echo "You can now use the language switcher in the sales portal!\n";
} catch (Exception $e) {
    echo "❌ Error inserting translations: " . $e->getMessage() . "\n";
    exit(1);
}

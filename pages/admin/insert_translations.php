<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/lang.php';

// Require admin access
require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Access denied. Admin only.');
}

/**
 * Insert initial translations for Sales Portal
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

    // Dashboard page
    'dashboard.title' => [
        'en' => 'Sales Dashboard',
        'ar' => 'لوحة مبيعات'
    ],
    'dashboard.subtitle' => [
        'en' => 'Live view of your pipeline, invoices, deliveries, and van stock.',
        'ar' => 'عرض مباشر لخط الأعمال والفواتير والتسليمات ومخزون الشاحنة.'
    ],
    'dashboard.overdue_alert_title' => [
        'en' => '🚨 Overdue Payments Alert',
        'ar' => '🚨 تنبيه المدفوعات المتأخرة'
    ],
    'dashboard.overdue_alert_subtitle' => [
        'en' => 'These invoices require immediate attention',
        'ar' => 'هذه الفواتير تحتاج اهتمام فوري'
    ],
    'dashboard.view_ar_dashboard' => [
        'en' => 'View AR Dashboard →',
        'ar' => 'عرض لوحة الحسابات المدينة ←'
    ],
    'dashboard.overdue_invoices' => [
        'en' => 'Overdue Invoices',
        'ar' => 'فواتير متأخرة'
    ],
    'dashboard.total_overdue' => [
        'en' => 'Total Overdue',
        'ar' => 'إجمالي المتأخرات'
    ],
    'dashboard.critical_90_days' => [
        'en' => 'Critical (90+ days)',
        'ar' => 'حرجة (٩٠+ يوم)'
    ],
    'dashboard.invoice' => [
        'en' => 'Invoice',
        'ar' => 'فاتورة'
    ],
    'dashboard.customer' => [
        'en' => 'Customer',
        'ar' => 'عميل'
    ],
    'dashboard.days_overdue' => [
        'en' => 'Days Overdue',
        'ar' => 'أيام التأخير'
    ],
    'dashboard.amount' => [
        'en' => 'Amount',
        'ar' => 'المبلغ'
    ],
    'dashboard.action' => [
        'en' => 'Action',
        'ar' => 'إجراء'
    ],
    'dashboard.record_payment' => [
        'en' => 'Record Payment',
        'ar' => 'تسجيل دفعة'
    ],
    'dashboard.days' => [
        'en' => 'days',
        'ar' => 'أيام'
    ],
    'dashboard.quota_performance' => [
        'en' => '🎯 Sales Quota Performance',
        'ar' => '🎯 أداء حصة المبيعات'
    ],
    'dashboard.this_month' => [
        'en' => 'This Month',
        'ar' => 'هذا الشهر'
    ],
    'dashboard.of_quota' => [
        'en' => 'of',
        'ar' => 'من'
    ],
    'dashboard.quota' => [
        'en' => 'quota',
        'ar' => 'الحصة'
    ],
    'dashboard.achieved' => [
        'en' => '✓ Achieved!',
        'ar' => '✓ تم تحقيقها!'
    ],
    'dashboard.on_track' => [
        'en' => '▲ On Track',
        'ar' => '▲ على المسار الصحيح'
    ],
    'dashboard.needs_attention' => [
        'en' => '⚠ Needs Attention',
        'ar' => '⚠ يحتاج اهتمام'
    ],
    'dashboard.year_to_date' => [
        'en' => 'Year to Date',
        'ar' => 'من بداية السنة'
    ],
    'dashboard.exceeding' => [
        'en' => '✓ Exceeding!',
        'ar' => '✓ متفوق!'
    ],
    'dashboard.strong' => [
        'en' => '▲ Strong',
        'ar' => '▲ قوي'
    ],
    'dashboard.behind_pace' => [
        'en' => '⚠ Behind Pace',
        'ar' => '⚠ متأخر عن الوتيرة'
    ],
    'dashboard.gap_to_quota' => [
        'en' => 'Gap to Quota',
        'ar' => 'الفجوة للحصة'
    ],
    'dashboard.surplus' => [
        'en' => 'Surplus',
        'ar' => 'فائض'
    ],
    'dashboard.days_left_month' => [
        'en' => 'days left in month',
        'ar' => 'أيام متبقية في الشهر'
    ],
    'dashboard.daily_target' => [
        'en' => 'Daily target:',
        'ar' => 'الهدف اليومي:'
    ],
    'dashboard.orders_today' => [
        'en' => 'Orders Today',
        'ar' => 'طلبات اليوم'
    ],
    'dashboard.created_since_midnight' => [
        'en' => 'Created since midnight',
        'ar' => 'تم إنشاؤها منذ منتصف الليل'
    ],
    'dashboard.open_orders' => [
        'en' => 'Open Orders',
        'ar' => 'طلبات مفتوحة'
    ],
    'dashboard.not_yet_delivered' => [
        'en' => 'Not yet delivered',
        'ar' => 'لم يتم تسليمها بعد'
    ],
    'dashboard.awaiting_approval' => [
        'en' => 'Awaiting Approval',
        'ar' => 'في انتظار الموافقة'
    ],
    'dashboard.still_on_hold' => [
        'en' => 'Still on hold',
        'ar' => 'لا تزال معلقة'
    ],
    'dashboard.in_transit' => [
        'en' => 'In Transit',
        'ar' => 'قيد النقل'
    ],
    'dashboard.orders_en_route' => [
        'en' => 'Orders currently en route',
        'ar' => 'طلبات في الطريق حالياً'
    ],
    'dashboard.deliveries_today' => [
        'en' => 'Deliveries Today',
        'ar' => 'تسليمات اليوم'
    ],
    'dashboard.scheduled_today' => [
        'en' => 'Scheduled for today',
        'ar' => 'مجدولة لليوم'
    ],
    'dashboard.open_receivables' => [
        'en' => 'Open Receivables',
        'ar' => 'ذمم مدينة مفتوحة'
    ],
    'dashboard.recent_orders' => [
        'en' => 'Recent Orders',
        'ar' => 'طلبات حديثة'
    ],
    'dashboard.latest' => [
        'en' => 'Latest',
        'ar' => 'الأحدث'
    ],
    'dashboard.no_orders' => [
        'en' => 'No orders found.',
        'ar' => 'لم يتم العثور على طلبات.'
    ],
    'dashboard.order' => [
        'en' => 'Order',
        'ar' => 'طلب'
    ],
    'dashboard.total_usd' => [
        'en' => 'Total (USD)',
        'ar' => 'الإجمالي (دولار)'
    ],
    'dashboard.pending_invoices' => [
        'en' => 'Pending Invoices',
        'ar' => 'فواتير معلقة'
    ],
    'dashboard.receivables' => [
        'en' => 'Receivables',
        'ar' => 'ذمم مدينة'
    ],
    'dashboard.no_invoices' => [
        'en' => 'No invoices issued yet.',
        'ar' => 'لم يتم إصدار فواتير بعد.'
    ],
    'dashboard.balance' => [
        'en' => 'Balance',
        'ar' => 'الرصيد'
    ],
    'dashboard.upcoming_deliveries' => [
        'en' => 'Upcoming Deliveries',
        'ar' => 'تسليمات قادمة'
    ],
    'dashboard.logistics' => [
        'en' => 'Logistics',
        'ar' => 'لوجستيات'
    ],
    'dashboard.no_deliveries' => [
        'en' => 'No upcoming deliveries scheduled.',
        'ar' => 'لا توجد تسليمات قادمة مجدولة.'
    ],
    'dashboard.when' => [
        'en' => 'When',
        'ar' => 'متى'
    ],
    'dashboard.recent_payments' => [
        'en' => 'Recent Payments',
        'ar' => 'دفعات حديثة'
    ],
    'dashboard.collections' => [
        'en' => 'Collections',
        'ar' => 'التحصيلات'
    ],
    'dashboard.no_payments' => [
        'en' => 'No payments recorded yet.',
        'ar' => 'لم يتم تسجيل دفعات بعد.'
    ],
    'dashboard.received' => [
        'en' => 'Received',
        'ar' => 'استلام'
    ],
    'dashboard.van_stock_snapshot' => [
        'en' => 'Van Stock Snapshot',
        'ar' => 'لمحة مخزون الشاحنة'
    ],
    'dashboard.inventory' => [
        'en' => 'Inventory',
        'ar' => 'مخزون'
    ],
    'dashboard.skus' => [
        'en' => 'SKUs',
        'ar' => 'رموز المنتجات'
    ],
    'dashboard.units_on_hand' => [
        'en' => 'Units On Hand',
        'ar' => 'وحدات متوفرة'
    ],
    'dashboard.stock_value_usd' => [
        'en' => 'Stock Value (USD)',
        'ar' => 'قيمة المخزون (دولار)'
    ],
    'dashboard.latest_movements' => [
        'en' => 'Latest Movements',
        'ar' => 'آخر الحركات'
    ],
    'dashboard.no_movements' => [
        'en' => 'No van stock movements recorded.',
        'ar' => 'لم يتم تسجيل حركات مخزون.'
    ],
    'dashboard.item' => [
        'en' => 'Item',
        'ar' => 'صنف'
    ],
    'dashboard.change' => [
        'en' => 'Change',
        'ar' => 'التغيير'
    ],
    'dashboard.reason' => [
        'en' => 'Reason',
        'ar' => 'السبب'
    ],

    // Order statuses
    'order_status.on_hold' => [
        'en' => 'On Hold',
        'ar' => 'معلق'
    ],
    'order_status.approved' => [
        'en' => 'Approved',
        'ar' => 'موافق عليه'
    ],
    'order_status.preparing' => [
        'en' => 'Preparing',
        'ar' => 'قيد التحضير'
    ],
    'order_status.ready' => [
        'en' => 'Ready for Pickup',
        'ar' => 'جاهز للاستلام'
    ],
    'order_status.in_transit' => [
        'en' => 'In Transit',
        'ar' => 'قيد النقل'
    ],
    'order_status.delivered' => [
        'en' => 'Delivered',
        'ar' => 'تم التسليم'
    ],
    'order_status.cancelled' => [
        'en' => 'Cancelled',
        'ar' => 'ملغى'
    ],
    'order_status.returned' => [
        'en' => 'Returned',
        'ar' => 'مرتجع'
    ],

    // Invoice statuses
    'invoice_status.draft' => [
        'en' => 'Pending Draft',
        'ar' => 'مسودة معلقة'
    ],
    'invoice_status.pending' => [
        'en' => 'Pending',
        'ar' => 'معلق'
    ],
    'invoice_status.issued' => [
        'en' => 'Issued',
        'ar' => 'صادر'
    ],
    'invoice_status.paid' => [
        'en' => 'Paid',
        'ar' => 'مدفوع'
    ],
    'invoice_status.voided' => [
        'en' => 'Voided',
        'ar' => 'ملغى'
    ],

    // Van Stock Sales Page
    'sale.title' => [
        'en' => 'Create New Sale',
        'ar' => 'إنشاء بيع جديد'
    ],
    'sale.subtitle' => [
        'en' => 'Quick and easy way to record a sale from your van',
        'ar' => 'طريقة سريعة وسهلة لتسجيل عملية بيع من شاحنتك'
    ],
    'sale.step1_title' => [
        'en' => 'Step 1: Who is the customer?',
        'ar' => 'الخطوة 1: من هو العميل؟'
    ],
    'sale.step1_subtitle' => [
        'en' => 'Search for your customer by typing their name or phone number',
        'ar' => 'ابحث عن عميلك بكتابة اسمه أو رقم هاتفه'
    ],
    'sale.step2_title' => [
        'en' => 'Step 2: What are you selling?',
        'ar' => 'الخطوة 2: ماذا تبيع؟'
    ],
    'sale.step3_title' => [
        'en' => 'Step 3: Add notes (Optional)',
        'ar' => 'الخطوة 3: إضافة ملاحظات (اختياري)'
    ],
    'sale.step3_subtitle' => [
        'en' => 'Add any special instructions or notes about this sale',
        'ar' => 'أضف أي تعليمات خاصة أو ملاحظات حول هذا البيع'
    ],
    'sale.filter_governorate' => [
        'en' => 'Filter by Governorate',
        'ar' => 'تصفية حسب المحافظة'
    ],
    'sale.all_governorates' => [
        'en' => 'All Governorates',
        'ar' => 'كل المحافظات'
    ],
    'sale.customer_name_phone' => [
        'en' => 'Customer Name or Phone',
        'ar' => 'اسم العميل أو الهاتف'
    ],
    'sale.search_placeholder' => [
        'en' => '🔍 Type customer name or phone number...',
        'ar' => '🔍 اكتب اسم العميل أو رقم الهاتف...'
    ],
    'sale.search_btn' => [
        'en' => '🔍 Search',
        'ar' => '🔍 بحث'
    ],
    'sale.product_search_placeholder' => [
        'en' => '🔍 Type product name or scan barcode...',
        'ar' => '🔍 اكتب اسم المنتج أو امسح الباركود...'
    ],
    'sale.clear_btn' => [
        'en' => '✕ Clear',
        'ar' => '✕ مسح'
    ],
    'sale.how_to_add_products' => [
        'en' => '📦 How to add products:',
        'ar' => '📦 كيفية إضافة المنتجات:'
    ],
    'sale.add_product_step1' => [
        'en' => 'Type the product name or scan barcode to search',
        'ar' => 'اكتب اسم المنتج أو امسح الباركود للبحث'
    ],
    'sale.add_product_step2' => [
        'en' => 'Click on any product to add it to your sale',
        'ar' => 'انقر على أي منتج لإضافته إلى عملية البيع'
    ],
    'sale.add_product_step3' => [
        'en' => 'Adjust quantity and discount if needed',
        'ar' => 'اضبط الكمية والخصم إذا لزم الأمر'
    ],
    'sale.no_products_found' => [
        'en' => '🔍 No products found.',
        'ar' => '🔍 لم يتم العثور على منتجات.'
    ],
    'sale.try_different_search' => [
        'en' => 'Try searching with a different name or barcode.',
        'ar' => 'جرب البحث باسم أو باركود مختلف.'
    ],
    'sale.products_in_sale' => [
        'en' => '✅ Products in this sale:',
        'ar' => '✅ المنتجات في هذا البيع:'
    ],
    'sale.no_products_yet' => [
        'en' => 'No products added yet. Search and click products above to add them.',
        'ar' => 'لم تتم إضافة منتجات بعد. ابحث وانقر على المنتجات أعلاه لإضافتها.'
    ],
    'sale.notes_label' => [
        'en' => 'Notes',
        'ar' => 'ملاحظات'
    ],
    'sale.notes_placeholder' => [
        'en' => 'Example: Customer requested delivery next week, Special discount approved, etc...',
        'ar' => 'مثال: طلب العميل التوصيل الأسبوع القادم، تمت الموافقة على خصم خاص، إلخ...'
    ],
    'sale.summary_title' => [
        'en' => '📊 Sale Summary',
        'ar' => '📊 ملخص البيع'
    ],
    'sale.number_of_items' => [
        'en' => 'Number of Items:',
        'ar' => 'عدد العناصر:'
    ],
    'sale.subtotal' => [
        'en' => 'Subtotal:',
        'ar' => 'المجموع الفرعي:'
    ],
    'sale.discount' => [
        'en' => 'Discount:',
        'ar' => 'الخصم:'
    ],
    'sale.total_usd' => [
        'en' => 'TOTAL (USD):',
        'ar' => 'المجموع (دولار):'
    ],
    'sale.total_lbp' => [
        'en' => 'TOTAL (LBP):',
        'ar' => 'المجموع (ل.ل.):'
    ],
    'sale.payment_question' => [
        'en' => '💵 Did the customer pay now?',
        'ar' => '💵 هل دفع العميل الآن؟'
    ],
    'sale.payment_optional' => [
        'en' => 'Optional:',
        'ar' => 'اختياري:'
    ],
    'sale.payment_instructions' => [
        'en' => 'If the customer paid you cash or by card, enter the amount below. Otherwise, leave blank and record payment later.',
        'ar' => 'إذا دفع لك العميل نقدًا أو بالبطاقة، أدخل المبلغ أدناه. وإلا، اتركه فارغًا وسجل الدفع لاحقًا.'
    ],
    'sale.amount_paid_usd' => [
        'en' => 'Amount Paid (USD)',
        'ar' => 'المبلغ المدفوع (دولار)'
    ],
    'sale.amount_paid_lbp' => [
        'en' => 'Amount Paid (LBP)',
        'ar' => 'المبلغ المدفوع (ل.ل.)'
    ],
    'sale.helpful_tip' => [
        'en' => '💡 Helpful Tip:',
        'ar' => '💡 نصيحة مفيدة:'
    ],
    'sale.currency_conversion_tip' => [
        'en' => 'Enter amount in either USD or LBP - it will automatically convert using today\'s exchange rate',
        'ar' => 'أدخل المبلغ بالدولار أو الليرة - سيتم تحويله تلقائيًا باستخدام سعر الصرف اليوم'
    ],
    'sale.add_product_hint' => [
        'en' => '⬆️ Add at least one product above to complete your sale',
        'ar' => '⬆️ أضف منتجًا واحدًا على الأقل أعلاه لإتمام عملية البيع'
    ],
    'sale.complete_btn' => [
        'en' => '✅ Complete Sale & Print Invoice',
        'ar' => '✅ إتمام البيع وطباعة الفاتورة'
    ],
    'sale.phone_label' => [
        'en' => 'Phone:',
        'ar' => 'الهاتف:'
    ],
    'sale.city_label' => [
        'en' => 'City:',
        'ar' => 'المدينة:'
    ],
    'sale.sku_label' => [
        'en' => 'SKU:',
        'ar' => 'رمز المنتج:'
    ],
    'sale.category_label' => [
        'en' => 'Category:',
        'ar' => 'الفئة:'
    ],
    'sale.stock_label' => [
        'en' => 'Stock:',
        'ar' => 'المخزون:'
    ],
    'sale.remove_btn' => [
        'en' => 'Remove',
        'ar' => 'إزالة'
    ],
    'sale.how_many' => [
        'en' => '📦 How many?',
        'ar' => '📦 كم عدد؟'
    ],
    'sale.discount_percent' => [
        'en' => '💰 Discount %',
        'ar' => '💰 الخصم %'
    ],
    'sale.subtotal_label' => [
        'en' => 'Subtotal:',
        'ar' => 'المجموع الفرعي:'
    ],
    'sale.unit_label' => [
        'en' => 'Unit:',
        'ar' => 'السعر:'
    ],

    // Flash messages for van stock sales
    'sale.error_exchange_rate_title' => [
        'en' => 'Exchange Rate Unavailable',
        'ar' => 'سعر الصرف غير متوفر'
    ],
    'sale.error_exchange_rate_msg' => [
        'en' => 'Cannot create orders at this time. The system exchange rate is not configured. Please contact your administrator.',
        'ar' => 'لا يمكن إنشاء طلبات في هذا الوقت. لم يتم تكوين سعر صرف النظام. يرجى الاتصال بالمسؤول.'
    ],
    'sale.error_csrf_title' => [
        'en' => 'Security Error',
        'ar' => 'خطأ أمني'
    ],
    'sale.error_csrf_msg' => [
        'en' => 'Invalid or expired CSRF token. Please try again.',
        'ar' => 'رمز CSRF غير صالح أو منتهي الصلاحية. يرجى المحاولة مرة أخرى.'
    ],
    'sale.error_validation_title' => [
        'en' => 'Validation Failed',
        'ar' => 'فشل التحقق'
    ],
    'sale.error_validation_msg' => [
        'en' => 'Unable to create order. Please fix the errors below:',
        'ar' => 'تعذر إنشاء الطلب. يرجى إصلاح الأخطاء أدناه:'
    ],
    'sale.error_select_customer' => [
        'en' => 'Please select a customer.',
        'ar' => 'يرجى اختيار عميل.'
    ],
    'sale.error_invalid_customer' => [
        'en' => 'Invalid customer selected or customer not assigned to you.',
        'ar' => 'عميل غير صالح محدد أو العميل غير مخصص لك.'
    ],
    'sale.error_add_product' => [
        'en' => 'Please add at least one product to the order.',
        'ar' => 'يرجى إضافة منتج واحد على الأقل إلى الطلب.'
    ],
    'sale.error_invalid_discount' => [
        'en' => 'Invalid discount for product ID {id}. Must be between 0 and 100.',
        'ar' => 'خصم غير صالح لمعرف المنتج {id}. يجب أن يكون بين 0 و 100.'
    ],
    'sale.error_no_valid_products' => [
        'en' => 'No valid products in the order.',
        'ar' => 'لا توجد منتجات صالحة في الطلب.'
    ],
    'sale.error_product_not_found' => [
        'en' => 'Product ID {id} not found or inactive.',
        'ar' => 'معرف المنتج {id} غير موجود أو غير نشط.'
    ],
    'sale.error_insufficient_stock' => [
        'en' => 'Insufficient van stock for {name}. Available: {available}, Requested: {requested}.',
        'ar' => 'مخزون الشاحنة غير كافٍ لـ {name}. المتاح: {available}، المطلوب: {requested}.'
    ],
    'sale.error_payment_exceeds' => [
        'en' => 'Payment Error',
        'ar' => 'خطأ في الدفع'
    ],
    'sale.error_payment_exceeds_msg' => [
        'en' => 'Payment amount cannot exceed invoice total.',
        'ar' => 'لا يمكن أن يتجاوز مبلغ الدفع إجمالي الفاتورة.'
    ],
    'sale.error_database_title' => [
        'en' => 'Database Error',
        'ar' => 'خطأ في قاعدة البيانات'
    ],
    'sale.error_database_msg' => [
        'en' => 'Unable to create order. Please try again.',
        'ar' => 'تعذر إنشاء الطلب. يرجى المحاولة مرة أخرى.'
    ],
    'sale.success_title' => [
        'en' => 'Order Created Successfully',
        'ar' => 'تم إنشاء الطلب بنجاح'
    ],
    'sale.success_msg' => [
        'en' => 'Your van stock sale has been recorded and inventory has been updated.',
        'ar' => 'تم تسجيل بيع مخزون الشاحنة وتحديث المخزون.'
    ],
    'sale.success_details' => [
        'en' => 'Order {order} and Invoice {invoice} have been created. Van stock has been updated.',
        'ar' => 'تم إنشاء الطلب {order} والفاتورة {invoice}. تم تحديث مخزون الشاحنة.'
    ],
    'sale.success_payment_recorded' => [
        'en' => 'Payment of {amount} has been recorded.',
        'ar' => 'تم تسجيل دفعة {amount}.'
    ],

    // Empty states
    'sale.empty_config_title' => [
        'en' => 'System Configuration Required',
        'ar' => 'يتطلب تكوين النظام'
    ],
    'sale.empty_config_msg' => [
        'en' => 'Orders cannot be created until the exchange rate is properly configured in the system.',
        'ar' => 'لا يمكن إنشاء الطلبات حتى يتم تكوين سعر الصرف بشكل صحيح في النظام.'
    ],
    'sale.empty_config_btn' => [
        'en' => 'Return to Dashboard',
        'ar' => 'العودة إلى لوحة التحكم'
    ],
    'sale.empty_customers_title' => [
        'en' => 'No Customers Assigned',
        'ar' => 'لا يوجد عملاء مخصصون'
    ],
    'sale.empty_customers_msg' => [
        'en' => 'You need to have customers assigned to you before creating van stock sales.',
        'ar' => 'يجب أن يكون لديك عملاء مخصصون لك قبل إنشاء مبيعات مخزون الشاحنة.'
    ],
    'sale.empty_customers_btn' => [
        'en' => 'Go to Customers page',
        'ar' => 'الانتقال إلى صفحة العملاء'
    ],
    'sale.empty_stock_title' => [
        'en' => 'No Van Stock Available',
        'ar' => 'لا يوجد مخزون شاحنة متاح'
    ],
    'sale.empty_stock_msg' => [
        'en' => 'You need to have products in your van stock before creating sales.',
        'ar' => 'يجب أن يكون لديك منتجات في مخزون شاحنتك قبل إنشاء المبيعات.'
    ],
    'sale.empty_stock_btn' => [
        'en' => 'Go to Van Stock page',
        'ar' => 'الانتقال إلى صفحة مخزون الشاحنة'
    ],

    // JavaScript alerts
    'sale.alert_already_added' => [
        'en' => '⚠️ This product is already in your sale!\\n\\nYou can change the quantity below if needed.',
        'ar' => '⚠️ هذا المنتج موجود بالفعل في عملية البيع!\\n\\nيمكنك تغيير الكمية أدناه إذا لزم الأمر.'
    ],
    'sale.alert_insufficient_stock' => [
        'en' => '⚠️ Not enough stock!\\n\\nYou only have {stock} units available in your van.\\n\\nPlease enter a smaller quantity.',
        'ar' => '⚠️ المخزون غير كافٍ!\\n\\nلديك {stock} وحدة فقط متاحة في شاحنتك.\\n\\nيرجى إدخال كمية أقل.'
    ],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Insert Translations</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            max-width: 800px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-top: 0;
        }
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 20px;
        }
        .btn:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Insert Sales Portal Translations</h1>

        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $inserted = insert_translations($translations);
                echo '<div class="success">';
                echo '✅ <strong>Success!</strong><br>';
                echo "Successfully inserted/updated {$inserted} translations into the database.";
                echo '</div>';

                echo '<div class="info">';
                echo '<strong>What\'s Next:</strong><br>';
                echo '1. Visit the Sales Portal dashboard<br>';
                echo '2. Look for the 🌐 language switcher button in the sidebar<br>';
                echo '3. Click it to switch between English and Arabic<br>';
                echo '4. The page will reload with RTL layout and Arabic text';
                echo '</div>';

                echo '<a href="../sales/dashboard.php" class="btn">Go to Sales Portal</a>';
            } catch (Exception $e) {
                echo '<div class="error">';
                echo '❌ <strong>Error!</strong><br>';
                echo htmlspecialchars($e->getMessage());
                echo '</div>';
            }
        } else {
            echo '<div class="info">';
            echo '<strong>Ready to insert translations</strong><br>';
            echo 'This will insert ' . count($translations) . ' translation keys covering:<br><br>';
            echo '• Navigation menu items (13)<br>';
            echo '• Common buttons (11)<br>';
            echo '• Form labels (16)<br>';
            echo '• Status labels (8)<br>';
            echo '• Messages (10)<br>';
            echo '• Lebanese Governorates (9)<br>';
            echo '• Common phrases (3)<br><br>';
            echo 'Click the button below to proceed.';
            echo '</div>';

            echo '<form method="POST">';
            echo '<button type="submit" class="btn">Insert Translations</button>';
            echo '</form>';
        }
        ?>
    </div>
</body>
</html>

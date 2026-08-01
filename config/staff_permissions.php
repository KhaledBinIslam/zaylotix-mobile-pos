<?php

/**
 * The fixed catalog of app sections a shop owner can grant to their cashier.
 * Unlike config/business_types.php-derived features (which the platform
 * admin controls per shop), this list is app-structural — it maps directly
 * to routes/nav sections — so it's a config file, not an admin-editable
 * database table. The owner just ticks checkboxes against this list.
 */

return [
    'pos' => ['label_bn' => 'বিক্রি (POS)', 'label_en' => 'Sell (POS)', 'category' => 'বিক্রি'],
    'sales_history' => ['label_bn' => 'বিক্রির ইতিহাস', 'label_en' => 'Sales history', 'category' => 'বিক্রি'],
    'promotions' => ['label_bn' => 'অফার/কুপন ব্যবস্থাপনা', 'label_en' => 'Promotions/coupons management', 'category' => 'বিক্রি'],

    'stock' => ['label_bn' => 'স্টক দেখা ও পণ্য যোগ', 'label_en' => 'View stock & add products', 'category' => 'স্টক'],
    'purchases' => ['label_bn' => 'ক্রয়/স্টক কেনা', 'label_en' => 'Purchases', 'category' => 'স্টক'],
    'damages' => ['label_bn' => 'ক্ষতি/নষ্ট পণ্য', 'label_en' => 'Damage/wastage', 'category' => 'স্টক'],
    'returns' => ['label_bn' => 'বিক্রয় ফেরত', 'label_en' => 'Sales returns', 'category' => 'স্টক'],
    'stock_count' => ['label_bn' => 'স্টক গণনা', 'label_en' => 'Stock count', 'category' => 'স্টক'],
    'barcode_labels' => ['label_bn' => 'বারকোড লেবেল প্রিন্ট', 'label_en' => 'Barcode label printing', 'category' => 'স্টক'],

    'customers' => ['label_bn' => 'কাস্টমার তালিকা', 'label_en' => 'Customer list', 'category' => 'কাস্টমার'],
    'due' => ['label_bn' => 'বাকি তোলা', 'label_en' => 'Collect dues', 'category' => 'কাস্টমার'],

    'expenses' => ['label_bn' => 'খরচের হিসাব', 'label_en' => 'Expenses', 'category' => 'হিসাব'],
    'accounts' => ['label_bn' => 'হিসাব-নিকাশ (সম্পদ/দায়)', 'label_en' => 'Accounts (assets/liabilities)', 'category' => 'হিসাব'],
    'reports' => ['label_bn' => 'লাভ-ক্ষতি রিপোর্ট', 'label_en' => 'Profit & loss reports', 'category' => 'হিসাব'],
    'export' => ['label_bn' => 'ডাটা এক্সপোর্ট', 'label_en' => 'Data export', 'category' => 'হিসাব'],

    'settings' => ['label_bn' => 'দোকানের সেটিংস', 'label_en' => 'Shop settings (logo/VAT)', 'category' => 'সেটিংস'],
];

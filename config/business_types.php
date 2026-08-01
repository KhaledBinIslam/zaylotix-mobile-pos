<?php

/**
 * Per-business-type defaults used to seed a new shop's product categories,
 * units, to toggle optional product fields (expiry/batch/imei/size/color),
 * and to pre-check a sensible starting `features` set on the admin's Create
 * Shop form (Admin/Shops/Create.vue watches business_type_id and applies
 * this list — the admin can still freely tick/untick before submitting, this
 * is just a starting point, not an enforced rule).
 *
 * The `features` lists are deliberately tiered by how much a shop that size
 * typically needs, not "give everyone everything": a one-person mudir dokan
 * (grocery) starts with just the essentials so the app isn't cluttered with
 * purchase ledgers and stock counts it'll never touch, while a supershop or
 * pharmacy — higher transaction volume, regulated/perishable stock, more
 * staff — starts with the fuller operational + accountability set. Same
 * underlying product either way; admin can always grant more later via
 * Admin/Shops/{shop}/edit as the shop grows.
 */

return [

    'supershop' => [
        'label_bn' => 'সুপারশপ',
        'label_en' => 'Super Shop',
        'fields' => ['expiry', 'batch'],
        // full set — higher volume, multiple staff, needs the full
        // operational + accountability toolkit from day one
        'features' => [
            'memo_print', 'memo_whatsapp', 'unit_conversion', 'weight_based_selling', 'wholesale_pricing', 'barcode_printing',
            'purchases', 'suppliers', 'damages', 'returns', 'stock_count', 'low_stock_alerts',
            'batch_tracking', 'accounts', 'expenses', 'reports', 'export', 'vat',
            'cashier_management', 'activity_log',
        ],
        'categories' => [
            ['name' => 'মুদি', 'name_en' => 'Grocery', 'emoji' => '🛒'],
            ['name' => 'তেল-ঘি', 'name_en' => 'Oil & Ghee', 'emoji' => '🫒'],
            ['name' => 'চাল-আটা-ডাল', 'name_en' => 'Rice & Flour', 'emoji' => '🌾'],
            ['name' => 'মসলা', 'name_en' => 'Spices', 'emoji' => '🌶️'],
            ['name' => 'পানীয়', 'name_en' => 'Drinks', 'emoji' => '🥤'],
            ['name' => 'স্ন্যাকস', 'name_en' => 'Snacks', 'emoji' => '🍪'],
            ['name' => 'দুধ-ডিম', 'name_en' => 'Dairy', 'emoji' => '🥛'],
            ['name' => 'পার্সোনাল কেয়ার', 'name_en' => 'Personal care', 'emoji' => '🧼'],
            ['name' => 'অন্যান্য', 'name_en' => 'Other', 'emoji' => '📦'],
        ],
        'units' => [
            ['name' => 'পিস', 'name_en' => 'Piece', 'code' => 'pcs'],
            ['name' => 'কেজি', 'name_en' => 'Kilogram', 'code' => 'kg'],
            ['name' => 'গ্রাম', 'name_en' => 'Gram', 'code' => 'g'],
            ['name' => 'লিটার', 'name_en' => 'Litre', 'code' => 'l'],
            ['name' => 'মিলি', 'name_en' => 'Millilitre', 'code' => 'ml'],
            ['name' => 'ডজন', 'name_en' => 'Dozen', 'code' => 'dz'],
            ['name' => 'প্যাকেট', 'name_en' => 'Packet', 'code' => 'pkt'],
            ['name' => 'বক্স', 'name_en' => 'Box', 'code' => 'box'],
        ],
    ],

    'grocery' => [
        'label_bn' => 'মুদির দোকান',
        'label_en' => 'Grocery Shop',
        'fields' => [],
        // deliberately minimal — a small mudir dokan doesn't need a
        // purchase ledger or stock-count reconciliation to run day to day;
        // loose-selling from a box (unit_conversion) is the one thing even
        // the smallest shop here actually uses constantly
        'features' => ['memo_print', 'unit_conversion', 'weight_based_selling', 'accounts', 'expenses', 'reports'],
        'categories' => [
            ['name' => 'মুদি', 'name_en' => 'Grocery', 'emoji' => '🛒'],
            ['name' => 'তেল-ঘি', 'name_en' => 'Oil & Ghee', 'emoji' => '🫒'],
            ['name' => 'চাল-আটা-ডাল', 'name_en' => 'Rice & Flour', 'emoji' => '🌾'],
            ['name' => 'মসলা', 'name_en' => 'Spices', 'emoji' => '🌶️'],
            ['name' => 'পানীয়', 'name_en' => 'Drinks', 'emoji' => '🥤'],
            ['name' => 'স্ন্যাকস', 'name_en' => 'Snacks', 'emoji' => '🍪'],
            ['name' => 'দুধ-ডিম', 'name_en' => 'Dairy', 'emoji' => '🥛'],
            ['name' => 'অন্যান্য', 'name_en' => 'Other', 'emoji' => '📦'],
        ],
        'units' => [
            ['name' => 'পিস', 'name_en' => 'Piece', 'code' => 'pcs'],
            ['name' => 'কেজি', 'name_en' => 'Kilogram', 'code' => 'kg'],
            ['name' => 'গ্রাম', 'name_en' => 'Gram', 'code' => 'g'],
            ['name' => 'লিটার', 'name_en' => 'Litre', 'code' => 'l'],
            ['name' => 'মিলি', 'name_en' => 'Millilitre', 'code' => 'ml'],
            ['name' => 'ডজন', 'name_en' => 'Dozen', 'code' => 'dz'],
            ['name' => 'প্যাকেট', 'name_en' => 'Packet', 'code' => 'pkt'],
            ['name' => 'বক্স', 'name_en' => 'Box', 'code' => 'box'],
        ],
    ],

    'clothing' => [
        'label_bn' => 'কাপড়ের দোকান',
        'label_en' => 'Clothing Shop',
        'fields' => ['size', 'color'],
        // returns matter a lot here (size/fit exchanges); perishable-stock
        // features (damages, low-stock alerts) are less relevant
        'features' => [
            'memo_print', 'barcode_printing', 'purchases', 'returns', 'stock_count', 'product_variants',
            'accounts', 'expenses', 'reports', 'export', 'cashier_management',
        ],
        'categories' => [
            ['name' => 'শার্ট', 'name_en' => 'Shirts', 'emoji' => '👔'],
            ['name' => 'প্যান্ট', 'name_en' => 'Pants', 'emoji' => '👖'],
            ['name' => 'শাড়ি', 'name_en' => 'Saree', 'emoji' => '🥻'],
            ['name' => 'থ্রি-পিস', 'name_en' => 'Three-piece', 'emoji' => '👗'],
            ['name' => 'বাচ্চাদের', 'name_en' => 'Kids wear', 'emoji' => '🧒'],
            ['name' => 'জুতা', 'name_en' => 'Shoes', 'emoji' => '👟'],
            ['name' => 'অন্যান্য', 'name_en' => 'Other', 'emoji' => '📦'],
        ],
        'units' => [
            ['name' => 'পিস', 'name_en' => 'Piece', 'code' => 'pcs'],
            ['name' => 'জোড়া', 'name_en' => 'Pair', 'code' => 'pair'],
            ['name' => 'গজ', 'name_en' => 'Yard', 'code' => 'yd'],
            ['name' => 'মিটার', 'name_en' => 'Metre', 'code' => 'm'],
            ['name' => 'সেট', 'name_en' => 'Set', 'code' => 'set'],
        ],
    ],

    'pharmacy' => [
        'label_bn' => 'ওষুধের দোকান',
        'label_en' => 'Pharmacy',
        'fields' => ['expiry', 'batch'],
        // regulated, perishable stock — full tracking + VAT + accountability
        'features' => [
            'memo_print', 'barcode_printing', 'purchases', 'suppliers', 'damages', 'returns',
            'stock_count', 'low_stock_alerts', 'batch_tracking', 'prescription_records', 'wholesale_pricing', 'accounts', 'expenses', 'reports', 'export', 'vat',
            'cashier_management', 'activity_log',
        ],
        'categories' => [
            ['name' => 'ট্যাবলেট', 'name_en' => 'Tablets', 'emoji' => '💊'],
            ['name' => 'সিরাপ', 'name_en' => 'Syrup', 'emoji' => '🍯'],
            ['name' => 'ইনজেকশন', 'name_en' => 'Injection', 'emoji' => '💉'],
            ['name' => 'মেডিকেল ইকুইপমেন্ট', 'name_en' => 'Medical equipment', 'emoji' => '🩺'],
            ['name' => 'অন্যান্য', 'name_en' => 'Other', 'emoji' => '📦'],
        ],
        'units' => [
            ['name' => 'পিস', 'name_en' => 'Piece', 'code' => 'pcs'],
            ['name' => 'স্ট্রিপ', 'name_en' => 'Strip', 'code' => 'strip'],
            ['name' => 'বোতল', 'name_en' => 'Bottle', 'code' => 'btl'],
            ['name' => 'বক্স', 'name_en' => 'Box', 'code' => 'box'],
        ],
    ],

    'mobile' => [
        'label_bn' => 'মোবাইল/ইলেকট্রনিক্স দোকান',
        'label_en' => 'Mobile/Electronics Shop',
        'fields' => ['imei'],
        // high-value, theft-prone stock — accountability (activity_log) and
        // full purchase/damage tracking matter more here than in a grocery
        'features' => [
            'memo_print', 'barcode_printing', 'purchases', 'damages', 'returns', 'stock_count',
            'low_stock_alerts', 'serial_tracking', 'accounts', 'expenses', 'reports', 'export', 'vat',
            'cashier_management', 'activity_log',
        ],
        'categories' => [
            ['name' => 'হ্যান্ডসেট', 'name_en' => 'Handsets', 'emoji' => '📱'],
            ['name' => 'চার্জার', 'name_en' => 'Chargers', 'emoji' => '🔌'],
            ['name' => 'হেডফোন', 'name_en' => 'Headphones', 'emoji' => '🎧'],
            ['name' => 'কভার', 'name_en' => 'Covers', 'emoji' => '🛡️'],
            ['name' => 'এক্সেসরিজ', 'name_en' => 'Accessories', 'emoji' => '🔧'],
            ['name' => 'অন্যান্য', 'name_en' => 'Other', 'emoji' => '📦'],
        ],
        'units' => [
            ['name' => 'পিস', 'name_en' => 'Piece', 'code' => 'pcs'],
            ['name' => 'সেট', 'name_en' => 'Set', 'code' => 'set'],
        ],
    ],

    'cosmetics' => [
        'label_bn' => 'কসমেটিকস',
        'label_en' => 'Cosmetics',
        'fields' => ['expiry'],
        // expiry-sensitive but smaller-scale than a pharmacy — skip vat/
        // activity_log/suppliers by default, admin can add if the shop grows
        'features' => [
            'memo_print', 'barcode_printing', 'purchases', 'damages', 'returns',
            'stock_count', 'low_stock_alerts', 'batch_tracking', 'accounts', 'expenses', 'reports',
        ],
        'categories' => [
            ['name' => 'স্কিন কেয়ার', 'name_en' => 'Skin care', 'emoji' => '🧴'],
            ['name' => 'মেকআপ', 'name_en' => 'Makeup', 'emoji' => '💄'],
            ['name' => 'পারফিউম', 'name_en' => 'Perfume', 'emoji' => '🧪'],
            ['name' => 'হেয়ার', 'name_en' => 'Hair care', 'emoji' => '💇'],
            ['name' => 'অন্যান্য', 'name_en' => 'Other', 'emoji' => '📦'],
        ],
        'units' => [
            ['name' => 'পিস', 'name_en' => 'Piece', 'code' => 'pcs'],
            ['name' => 'বোতল', 'name_en' => 'Bottle', 'code' => 'btl'],
            ['name' => 'বক্স', 'name_en' => 'Box', 'code' => 'box'],
        ],
    ],

    'general' => [
        'label_bn' => 'অন্যান্য',
        'label_en' => 'General',
        'fields' => [],
        // catch-all for a shop that doesn't fit a specific type — stays
        // conservative like grocery, admin adjusts once they know the shop
        'features' => ['memo_print', 'accounts', 'expenses', 'reports'],
        'categories' => [
            ['name' => 'সাধারণ', 'name_en' => 'General', 'emoji' => '📦'],
            ['name' => 'অন্যান্য', 'name_en' => 'Other', 'emoji' => '🗂️'],
        ],
        // "general" is the catch-all for shops that don't fit a specific
        // type, so this list stays broad on purpose rather than narrow
        'units' => [
            ['name' => 'পিস', 'name_en' => 'Piece', 'code' => 'pcs'],
            ['name' => 'কেজি', 'name_en' => 'Kilogram', 'code' => 'kg'],
            ['name' => 'লিটার', 'name_en' => 'Litre', 'code' => 'l'],
            ['name' => 'প্যাকেট', 'name_en' => 'Packet', 'code' => 'pkt'],
            ['name' => 'বক্স', 'name_en' => 'Box', 'code' => 'box'],
            ['name' => 'সেট', 'name_en' => 'Set', 'code' => 'set'],
        ],
    ],

    'restaurant' => [
        'label_bn' => 'রেস্টুরেন্ট',
        'label_en' => 'Restaurant',
        'fields' => [],
        // table service is the defining need here — the rest mirrors a
        // small food business: purchases/damages for ingredients (spoilage
        // happens), no unit-conversion/batch/variant/serial verticals since
        // those aren't how a restaurant's menu items work
        'features' => [
            'memo_print', 'restaurant_tables', 'purchases', 'damages', 'low_stock_alerts',
            'accounts', 'expenses', 'reports', 'export', 'vat', 'cashier_management', 'activity_log',
        ],
        'categories' => [
            ['name' => 'ভাত-তরকারি', 'name_en' => 'Rice & Curry', 'emoji' => '🍛'],
            ['name' => 'কাবাব/গ্রিল', 'name_en' => 'Kebab/Grill', 'emoji' => '🍢'],
            ['name' => 'ফাস্ট ফুড', 'name_en' => 'Fast Food', 'emoji' => '🍔'],
            ['name' => 'পানীয়', 'name_en' => 'Drinks', 'emoji' => '🥤'],
            ['name' => 'মিষ্টি/ডেজার্ট', 'name_en' => 'Sweets/Dessert', 'emoji' => '🍮'],
            ['name' => 'অন্যান্য', 'name_en' => 'Other', 'emoji' => '📦'],
        ],
        'units' => [
            ['name' => 'প্লেট', 'name_en' => 'Plate', 'code' => 'plate'],
            ['name' => 'বাটি', 'name_en' => 'Bowl', 'code' => 'bowl'],
            ['name' => 'গ্লাস', 'name_en' => 'Glass', 'code' => 'glass'],
            ['name' => 'পিস', 'name_en' => 'Piece', 'code' => 'pcs'],
        ],
    ],

];

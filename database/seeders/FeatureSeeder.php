<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $features = [
            // billing — how a sale gets handed to the customer
            ['key' => 'memo_whatsapp', 'label_bn' => 'WhatsApp-এ মেমো পাঠানো', 'label_en' => 'Send memo via WhatsApp', 'category' => 'billing', 'description' => 'Manual WhatsApp memo link after checkout.'],
            ['key' => 'memo_print', 'label_bn' => 'POS প্রিন্টারে মেমো প্রিন্ট', 'label_en' => 'Print memo on POS printer', 'category' => 'billing', 'description' => 'Thermal-receipt-formatted printable memo with shop name/logo/price.'],

            // inventory — stock operations beyond plain selling
            ['key' => 'barcode_printing', 'label_bn' => 'বারকোড লেবেল প্রিন্ট', 'label_en' => 'Barcode label printing', 'category' => 'inventory', 'description' => 'Print barcode labels with regular + discount price for barcode printers.'],
            ['key' => 'unit_conversion', 'label_bn' => 'বক্স/স্ট্রিপ থেকে ভেঙে বিক্রি', 'label_en' => 'Sell broken-down pack units', 'category' => 'inventory', 'description' => 'Define pack sizes (box/strip/etc.) that decrement base stock automatically — e.g. sell 2 tablets out of a strip, or single candies out of a box.'],
            ['key' => 'purchases', 'label_bn' => 'ক্রয় ব্যবস্থাপনা', 'label_en' => 'Purchase tracking', 'category' => 'inventory', 'description' => 'Record stock bought from suppliers and its effect on cash/bank balance.'],
            ['key' => 'damages', 'label_bn' => 'ক্ষতি/নষ্ট পণ্য ট্র্যাকিং', 'label_en' => 'Damage write-off tracking', 'category' => 'inventory', 'description' => 'Write off expired/broken stock and record the loss.'],
            ['key' => 'returns', 'label_bn' => 'বিক্রয় ফেরত', 'label_en' => 'Sales returns', 'category' => 'inventory', 'description' => 'Take back sold products from a customer, restock and refund.'],
            ['key' => 'stock_count', 'label_bn' => 'স্টক গণনা মিলানো', 'label_en' => 'Stock count reconciliation', 'category' => 'inventory', 'description' => 'Physically count stock and reconcile it against what the system shows.'],
            ['key' => 'low_stock_alerts', 'label_bn' => 'কম স্টক এলার্ট', 'label_en' => 'Low-stock reorder alerts', 'category' => 'inventory', 'description' => 'Notify the owner when a product\'s stock drops to/below its configured reorder point.'],
            ['key' => 'suppliers', 'label_bn' => 'সাপ্লায়ার ব্যবস্থাপনা', 'label_en' => 'Supplier management', 'category' => 'inventory', 'description' => 'Track named suppliers, their payable balance, and pay it down over time.'],
            ['key' => 'batch_tracking', 'label_bn' => 'ব্যাচ ও মেয়াদ ট্র্যাকিং', 'label_en' => 'Batch & expiry tracking', 'category' => 'inventory', 'description' => 'Track batch number/expiry date per stock-in, sell oldest-expiry stock first (FEFO), and get notified before something expires.'],
            ['key' => 'product_variants', 'label_bn' => 'সাইজ/রং ভ্যারিয়েন্ট', 'label_en' => 'Size/color variants', 'category' => 'inventory', 'description' => 'One product with multiple size/color options, each with its own stock and (optionally) its own price.'],
            ['key' => 'serial_tracking', 'label_bn' => 'IMEI/সিরিয়াল ট্র্যাকিং', 'label_en' => 'IMEI/serial tracking', 'category' => 'inventory', 'description' => 'Track each unit\'s IMEI/serial and warranty period, and look one up later for after-sales service.'],
            ['key' => 'weight_based_selling', 'label_bn' => 'ওজন/আয়তন হিসেবে বিক্রি (কেজি, গ্রাম, লিটার)', 'label_en' => 'Weight/volume-based selling (kg, gram, litre)', 'category' => 'inventory', 'description' => 'Mark a product as loose-sold — price becomes "per kg"/"per litre" and the POS gets a quick gram/ml weight-entry pad instead of a whole-unit stepper.'],
            ['key' => 'wholesale_pricing', 'label_bn' => 'পাইকারি মূল্য (Wholesale)', 'label_en' => 'Wholesale pricing', 'category' => 'inventory', 'description' => 'Set a separate wholesale price per product and mark a whole checkout as retail or wholesale — POS uses the wholesale rate on every line for that sale.'],
            ['key' => 'prescription_records', 'label_bn' => 'প্রেসক্রিপশন রেকর্ড', 'label_en' => 'Prescription records', 'category' => 'inventory', 'description' => 'Flag drug-control products so the cashier is reminded to check a prescription, and record a note about it at checkout.'],
            // opt-in only — a restaurant that wants raw-ingredient/recipe-level
            // cost control turns this on specifically; products.stock keeps
            // working exactly as before either way (see IngredientConsumption)
            ['key' => 'ingredient_tracking', 'label_bn' => 'উপাদান ও রেসিপি ট্র্যাকিং', 'label_en' => 'Ingredient & recipe tracking', 'category' => 'inventory', 'description' => 'Track raw ingredient stock, define a recipe per dish, and automatically consume ingredients when it sells or is prepared.'],
            ['key' => 'restaurant_tables', 'label_bn' => 'টেবিল ও KOT (রেস্টুরেন্ট)', 'label_en' => 'Tables & KOT (restaurant)', 'category' => 'billing', 'description' => 'Table-wise open tab, add items over time, print a kitchen order ticket, and bill the table when the party is done.'],
            ['key' => 'promotions', 'label_bn' => 'অফার/কুপন (BOGO, কম্বো, কুপন কোড)', 'label_en' => 'Promotions/coupons (BOGO, combo, coupon codes)', 'category' => 'billing', 'description' => 'Buy-X-get-Y (or combo) auto-discounts and coupon codes applied at checkout.'],
            ['key' => 'loyalty_points', 'label_bn' => 'লয়্যালটি পয়েন্ট', 'label_en' => 'Loyalty points', 'category' => 'billing', 'description' => 'Customers earn points on purchases and redeem them for a discount later — the owner sets both rates.'],
            ['key' => 'quotations', 'label_bn' => 'কোটেশন/মূল্য তালিকা', 'label_en' => 'Quotations', 'category' => 'billing', 'description' => 'Give a customer a written price quote before they buy, then convert it straight into a sale when they\'re ready.'],

            // accounts — money the shop is tracking
            ['key' => 'accounts', 'label_bn' => 'হিসাব-নিকাশ', 'label_en' => 'Accounts & balance sheet', 'category' => 'accounts', 'description' => 'Assets, liabilities, capital and net worth in one view.'],
            ['key' => 'expenses', 'label_bn' => 'খরচ ব্যবস্থাপনা', 'label_en' => 'Expense tracking', 'category' => 'accounts', 'description' => 'Record shop expenses (rent, salary, utilities) and their effect on cash/bank balance.'],
            ['key' => 'partners', 'label_bn' => 'পার্টনার হিসাব', 'label_en' => 'Partner accounts', 'category' => 'accounts', 'description' => 'Multi-owner investment and profit-share tracking within one shop.'],
            // opt-in only — not granted by default to any business type, an
            // admin/owner turns it on specifically for a shop that has staff
            // to run payroll for
            ['key' => 'hr_payroll', 'label_bn' => 'কর্মচারী ও বেতন (HR/Payroll)', 'label_en' => 'HR & Payroll', 'category' => 'accounts', 'description' => 'Employee records, daily attendance, salary advances, and monthly payroll runs.'],

            // tax — NBR-facing calculations
            ['key' => 'vat', 'label_bn' => 'ভ্যাট/ট্যাক্স ব্যবস্থাপনা', 'label_en' => 'VAT & tax management', 'category' => 'tax', 'description' => 'Set the shop\'s VAT mode (none / turnover / full) and apply it to sales.'],

            // reports — looking back at what happened
            ['key' => 'reports', 'label_bn' => 'বিক্রয় রিপোর্ট ও লাভ-ক্ষতি', 'label_en' => 'Sales reports & P&L', 'category' => 'reports', 'description' => 'Daily/weekly/monthly sales, profit, and loss statement.'],
            ['key' => 'export', 'label_bn' => 'ডাটা এক্সপোর্ট', 'label_en' => 'Data export (Excel/CSV)', 'category' => 'reports', 'description' => 'Download sales, stock, due, expense, and P&L data as spreadsheet files.'],

            // staff — who else can use the shop's account
            ['key' => 'cashier_management', 'label_bn' => 'ক্যাশিয়ার যোগ করার সুবিধা', 'label_en' => 'Add staff accounts', 'category' => 'staff', 'description' => 'Let the shop owner add any number of staff accounts, each with its own owner-chosen access checklist.'],
            ['key' => 'activity_log', 'label_bn' => 'অ্যাক্টিভিটি লগ', 'label_en' => 'Staff activity log', 'category' => 'staff', 'description' => 'An audit trail of who did what — void sales, stock-ins, purchases, payments, staff changes.'],
        ];

        foreach ($features as $f) {
            Feature::updateOrCreate(['key' => $f['key']], $f + ['is_active' => true]);
        }
    }
}

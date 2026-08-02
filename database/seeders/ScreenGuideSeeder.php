<?php

namespace Database\Seeders;

use App\Models\ScreenGuide;
use Illuminate\Database\Seeder;

/** Initial content -- the same text HowToHint.vue's 3 existing usages used to hardcode via i18n, so switching to admin-managed guides isn't a visual regression. The admin can edit/add more from here on (Admin > Screen Guides). */
class ScreenGuideSeeder extends Seeder
{
    public function run(): void
    {
        $guides = [
            [
                'screen_key' => 'pos', 'label' => 'POS / Sell screen',
                'text_bn' => 'নিচের সবুজ "বিক্রি" বাটনে চাপ দিন। পণ্যে ট্যাপ করলে কার্টে যোগ হবে। শেষে "টাকা নিন" চাপুন — ব্যাস, বিক্রি হয়ে গেল।',
                'text_en' => 'Tap the green "Sell" button below. Tap a product to add it to the cart. Then tap "Take payment" — done.',
            ],
            [
                'screen_key' => 'stock', 'label' => 'Stock screen',
                'text_bn' => '"স্টক" পেজে গিয়ে "+ নতুন পণ্য" বাটনে চাপুন। নাম আর দাম লিখে সংরক্ষণ করুন। আগের পণ্যের স্টক বাড়াতে হলে পণ্যে ট্যাপ করে এডিট করুন।',
                'text_en' => 'Go to the "Stock" page and tap "+ New product". Enter the name and price, then save. To add stock to an existing item, tap it to edit.',
            ],
            [
                'screen_key' => 'due', 'label' => 'Due (customers) screen',
                'text_bn' => 'বিক্রির সময় "বাকি/ধারে" অপশন বেছে নিন আর কাস্টমারের নাম-ফোন লিখুন। পরে "বাকি" পেজে গিয়ে কে কত বাকি রেখেছে দেখতে ও টাকা জমা নিতে পারবেন।',
                'text_en' => 'At checkout, choose "Due/Credit" and enter the customer\'s name and phone. Later, open the "Due" page to see who owes what and collect payment.',
            ],
        ];

        foreach ($guides as $guide) {
            ScreenGuide::updateOrCreate(['screen_key' => $guide['screen_key']], $guide + ['is_active' => true]);
        }
    }
}

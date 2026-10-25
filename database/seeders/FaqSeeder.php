<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

/** Initial content -- carries over the app's previous hardcoded FAQ text so switching to admin-managed FAQs isn't a visual regression. The admin can edit/add/remove freely from here on (Admin > FAQs). */
class FaqSeeder extends Seeder
{
    public function run(): void
    {
        $faqs = [
            ['question_bn' => 'কীভাবে একটা জিনিস বিক্রি করব?', 'question_en' => 'How do I sell an item?', 'answer_bn' => 'নিচের সবুজ "বিক্রি" বাটনে চাপ দিন। পণ্যে ট্যাপ করলে কার্টে যোগ হবে। শেষে "টাকা নিন" চাপুন — ব্যাস, বিক্রি হয়ে গেল।', 'answer_en' => 'Tap the green "Sell" button below. Tap a product to add it to the cart. Then tap "Take payment" — done.'],
            ['question_bn' => 'কীভাবে নতুন পণ্য/স্টক যোগ করব?', 'question_en' => 'How do I add a new product/stock?', 'answer_bn' => '"স্টক" পেজে গিয়ে "+ নতুন পণ্য" বাটনে চাপুন। নাম আর দাম লিখে সংরক্ষণ করুন। আগের পণ্যের স্টক বাড়াতে হলে পণ্যে ট্যাপ করে এডিট করুন।', 'answer_en' => 'Go to the "Stock" page and tap "+ New product". Enter the name and price, then save. To add stock to an existing item, tap it to edit.'],
            ['question_bn' => 'বাকি (ধারে বিক্রি) কীভাবে হিসাব রাখব?', 'question_en' => 'How do I track due (credit) sales?', 'answer_bn' => 'বিক্রির সময় "বাকি/ধারে" অপশন বেছে নিন আর কাস্টমারের নাম-ফোন লিখুন। পরে "বাকি" পেজে গিয়ে কে কত বাকি রেখেছে দেখতে ও টাকা জমা নিতে পারবেন।', 'answer_en' => 'At checkout, choose "Due/Credit" and enter the customer\'s name and phone. Later, open the "Due" page to see who owes what and collect payment.'],
            ['question_bn' => 'ভুল করে বিক্রি করে ফেললে কী করব?', 'question_en' => 'What if I made a mistake on a sale?', 'answer_bn' => '"বিক্রির ইতিহাস" পেজে গিয়ে সেই বিক্রিটা খুঁজুন, তারপর "বাতিল করুন" বাটনে চাপুন — স্টক ও হিসাব নিজে থেকেই ঠিক হয়ে যাবে।', 'answer_en' => 'Go to "Sales History", find that sale, and tap "Void". Stock and accounts are corrected automatically.'],
            ['question_bn' => 'দিনের শেষে লাভ-ক্ষতি কীভাবে দেখব?', 'question_en' => 'How do I see daily profit/loss?', 'answer_bn' => '"রিপোর্ট" পেজে "আজ" বাটনে চাপ দিলেই আজকের মোট বিক্রি, লাভ ও বাকির হিসাব একসাথে দেখতে পাবেন।', 'answer_en' => 'On the "Reports" page, tap "Today" to see total sales, profit, and dues at a glance.'],
            ['question_bn' => 'ভাষা বাংলা থেকে ইংরেজি করব কীভাবে?', 'question_en' => 'How do I switch the language?', 'answer_bn' => 'উপরে ডান পাশে "EN"/"বাং" বাটনে চাপ দিলেই ভাষা বদলে যাবে।', 'answer_en' => 'Tap the "EN"/"বাং" button at the top-right to switch languages.'],
            // "Online Business" module, part 7 — see docs/FIX_PLAN.md's
            // Phase 37 design note. Reuses this existing admin-managed FAQ
            // system rather than a new static help page.
            [
                'question_bn' => 'অনলাইন অর্ডারের প্রফিট কীভাবে হিসাব হয়?',
                'question_en' => 'How is online order profit calculated?',
                'answer_bn' => "অনলাইন অর্ডারের টাকা শুধু 'ডেলিভারড' হওয়ার পরেই প্রফিট হিসেবে ধরা হয় — অপেক্ষমান/ট্রানজিটে থাকা অর্ডার এখনো হিসাবে আসে না।\n\nপ্রতিটা ডেলিভারড অর্ডারের জন্য:\nবিক্রির দাম − পণ্যের দাম − কুরিয়ার খরচ + ডেলিভারি চার্জ (কাস্টমার যা দিয়েছে) − বিজ্ঞাপন খরচ − প্ল্যাটফর্ম/গেটওয়ে ফি − নষ্ট/হারানো পণ্যের ক্ষতি = প্রফিট।\n\nভাড়া/বেতনের মতো শেয়ার্ড খরচ একটা নিয়ম অনুযায়ী (সমানভাবে/রেভিনিউ অনুপাতে/নিজে বণ্টন করে) চ্যানেলগুলোতে ভাগ হয়, যদি আপনি সেই নিয়ম সেট করে থাকেন — \"বিজ্ঞাপন খরচ\" পেজ থেকে সেট করা যায়।\n\nপুরো হিসাব (সব চ্যানেল মিলিয়ে, এবং প্রতিটা চ্যানেল আলাদাভাবে) Online Business → \"অনলাইন প্রফিট রিপোর্ট\" পেজে দেখা যাবে।",
                'answer_en' => "Online order money only counts as profit once an order is marked 'Delivered' — pending/in-transit orders aren't counted yet.\n\nFor each delivered order:\nSale price − product cost − courier cost + delivery charge (what the customer paid) − ad spend − platform/gateway fees − damage/loss on returned items = profit.\n\nShared costs like rent/salary are split across channels by a rule (equal / by revenue share / manual) only if you've set one up — from the \"Ad spend\" page.\n\nSee the full breakdown (combined and per channel) under Online Business → \"Online Profit Report\".",
            ],
        ];

        foreach ($faqs as $i => $faq) {
            Faq::updateOrCreate(
                ['question_en' => $faq['question_en']],
                $faq + ['sort_order' => $i, 'is_active' => true]
            );
        }
    }
}

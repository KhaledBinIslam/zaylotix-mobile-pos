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
        ];

        foreach ($faqs as $i => $faq) {
            Faq::updateOrCreate(
                ['question_en' => $faq['question_en']],
                $faq + ['sort_order' => $i, 'is_active' => true]
            );
        }
    }
}

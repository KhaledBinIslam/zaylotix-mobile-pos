# ডিপ্লয় চেকলিস্ট (Phase 40)

প্রতিটি প্রোডাকশন ডিপ্লয়ে এই ক্রমে, কোনো ধাপ বাদ নয়। অর্ধেক-ডিপ্লয়ই
"মালিকের ভিউতে আপডেট আসে না"-র দ্বিতীয় বড় কারণ ছিল
(docs/CUSTOMIZATION_AUDIT.md কারণ ৪ — `migrate` প্রম্পটে আটকে `&&`-চেইনের
বাকি সব বাদ পড়েছিল)।

## স্ট্যান্ডার্ড ডিপ্লয় (এক লাইনে)

```
ssh -p 65002 u441890539@82.29.157.97 "cd domains/zaylotix.com/pos_app && git pull && php artisan migrate --force && php artisan config:clear && php artisan cache:clear && php artisan shop:smoke-test --all && php artisan shop:effective-settings --all"
```

## ধাপে ধাপে কী হয় / বড় রিলিজে অতিরিক্ত কী

1. **ডাটাবেস ব্যাকআপ** — বড়/ঝুঁকিপূর্ণ migration থাকলে আগে:
   Hostinger hPanel → Databases → phpMyAdmin → Export (বা cron-এর নিয়মিত ব্যাকআপ আছে কি না দেখে নাও)।
2. `git pull` — build করা অ্যাসেট repo-তে commit করা থাকে, সার্ভারে npm চলে না।
3. `composer install --no-dev` — **শুধু** composer.lock বদলালে (খুবই কম ঘটে)।
4. `php artisan migrate --force` — `--force` **বাধ্যতামূলক**: এটা ছাড়া প্রোডাকশনে
   confirmation প্রম্পট আসে, non-interactive SSH-এ cancel হয়ে পরের সব ধাপ বাদ পড়ে।
5. `php artisan config:clear && php artisan cache:clear` — route:clear/view:clear
   সাধারণত লাগে না (route/view cache করা হয় না), সন্দেহ হলে চালাতে ক্ষতি নেই।
6. queue worker থাকলে `php artisan queue:restart` — (এই হোস্টে এখনো worker নেই; চালু করলে এই ধাপ বাধ্যতামূলক)।
7. **`php artisan shop:smoke-test --all`** — সব business type-এ throwaway শপ বানিয়ে
   আসল checkout চালিয়ে rollback করে। FAIL হলে সেখানেই থামো, আগের commit-এ ফেরো।
8. **`php artisan shop:effective-settings --all`** — প্রতিটি আসল শপের কাস্টমাইজেশন
   সেটিংস resolve হয় কি না (খারাপ JSON ইত্যাদি ধরা পড়ে)।
9. **একটা পরীক্ষা শপে যাচাই** — নিজের test শপে লগইন করে বদলানো ফিচারটা দেখো।
10. **মালিকের আসল ভিউ যাচাই** — ক্লায়েন্টের শপ impersonate করে (admin → shop →
    Impersonate) পরিবর্তনটা দেখা যাচ্ছে কি না দেখো; টেস্ট-প্রমাণিত যে impersonation
    আর মালিকের লগইন একই জিনিস দেখে। ক্লায়েন্টের খোলা ফোনে পুরনো সংস্করণ থাকলে
    অ্যাপ foreground-এ আনলেই "নতুন সংস্করণ এসেছে — রিফ্রেশ করুন" ব্যানার আসবে;
    More পেজের নিচের সংস্করণ নম্বর মিলিয়ে নিশ্চিত হওয়া যায়।

## নতুন কাস্টম ফিচার যোগের নিয়ম (Phase 40-এর স্থায়ী নীতি)

- **কোডে শপ-আইডি/নাম ধরে `if` নয়** — সবসময় শপ-সেটিং (shops টেবিলের কলাম +
  Shop model-এ defaults-merge accessor) বা ফিচার-ফ্ল্যাগ।
- **ডিফল্ট বন্ধ/আগের আচরণ** — কাস্টম মান না থাকলে বিদ্যমান শপের আউটপুট এক
  বাইটও বদলাবে না (snapshot টেস্ট: ShopCustomizationTest::
  test_a_never_customized_shop_resolves_to_exact_system_defaults)।
- নতুন সেটিং = `App\Support\ShopCustomization::effective()`-এ এক লাইন রো যোগ
  করো — তাহলেই admin "কাস্টমাইজেশন" প্যানেল, `shop:effective-settings` কমান্ড
  আর reset কিল-সুইচে আপনাআপনি আসবে।
- **টেস্ট আবশ্যিক** — টেন্যান্ট-লিক (শপ ক-তে সেট করলে শপ খ অপরিবর্তিত) +
  ডিফল্ট-অপরিবর্তিত, দুটোই।
- সেটিংসের সার্ভার-সাইড ক্যাশ আজ **নেই** (প্রতি request DB-পড়া) — কখনো যোগ করলে
  কী অবশ্যই `shop:{id}:` namespace-এ, লেখার সাথেই ওই শপের কী invalidate।

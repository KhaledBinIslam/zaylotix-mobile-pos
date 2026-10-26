# Baby Park (জুতার দোকান, ২ ব্রাঞ্চ) — লঞ্চ প্ল্যান

Khaled-এর প্রথম পেইং ক্লায়েন্ট। আজকের মধ্যে ব্যবহারের জন্য প্রস্তুত করতে হবে।
এই ডকটি ধাপ ১-এর অডিট ও সিদ্ধান্ত অনুযায়ী লেখা।

## ধাপ ১ — অডিট ফলাফল ও সিদ্ধান্ত

**সবচেয়ে বড় আবিষ্কার: শেয়ার্ড-স্টক পুল মেকানিজম ইতিমধ্যে সম্পূর্ণ বানানো,
টেস্ট করা, এবং প্রোডাকশনে আছে** (Online Business module Part 8,
`shared_stock_pool` ফিচার, commit-পর্যায়ে `SharedStockPoolTest.php`-এ ৮টা
টেস্ট পাস করা)। এটা ঠিক Khaled-এর পছন্দের ডিফল্ট ডিজাইনের সাথে মিলে যায়:

- `Product::stockPoolMemberIds()` / `applyStockDelta()`
  (`app/Models/Product.php:171-268`) — `master_product_id` দিয়ে main শপ ↔
  ব্রাঞ্চ কপি লিংক করা থাকলে এবং main শপে `shared_stock_pool` ফিচার অন
  থাকলে, সব মেম্বার রো একসাথে `lockForUpdate()` করে, একই নতুন stock ভ্যালু
  অ্যাটমিকভাবে সব রো-তে লিখে দেয় — ওভারসেল অসম্ভব, deadlock-safe (ascending
  id অর্ডারে লক), ফিচার অফ থাকা কোনো শপের জন্য শূন্য এক্সট্রা কুয়েরি।
- `ProductVariant::stockPoolMemberIds()` / `applyStockDelta()`
  (`app/Models/ProductVariant.php`) — ঠিক একই মেকানিজম ভ্যারিয়েন্ট
  (সাইজ/রং)-এর জন্যও, barcode দিয়ে (না থাকলে size+color দিয়ে) মেলায়।
- `CatalogSync::syncToBranch()` / `syncProductToBranches()`
  (`app/Support/CatalogSync.php`) — main শপের Product/Category/Unit/Variant
  ব্রাঞ্চে কপি করে, `master_product_id` সেট করে রাখে। `shared_catalog`
  ফিচার অন থাকলে প্রোডাক্ট সেভ হওয়ার সাথে সাথেই রিয়েল-টাইমে সিঙ্ক হয়
  (`ProductController::store()/update()` hook)।
- `Admin\ShopController::createBranch()` (`app/Http/Controllers/Admin/ShopController.php:176-224`)
  — ব্রাঞ্চ তৈরির established pattern: নতুন Shop row (parent_shop_id সহ,
  **নিজের কোনো owner User তৈরি হয় না** — মালিক একই লগইনে switch করেন),
  main শপের feature grant কপি, তারপর `CatalogSync::syncToBranch()`।
- `BranchController::switch()` (`app/Http/Controllers/App/BranchController.php`)
  — owner-only, session-ভিত্তিক branch switch, `Tenancy::id()` তখন
  active_branch_id রিজলভ করে।
- `Tenancy::businessShopIds()`/`sameBusiness()` — ইতিমধ্যে business-root
  ভিত্তিক গ্রুপিং আছে, `Customer` ইতিমধ্যে business-wide শেয়ার্ড
  (`BusinessScope`)।
- Staff/cashier অ্যাকাউন্ট সব সময় একটা নির্দিষ্ট `shop_id`-এ ফিক্সড থাকে
  (switch করতে পারে না — `BranchController::switch()`-এ
  `abort_unless(role===owner)`), তাই **ব্রাঞ্চ-২-এর জন্য আলাদা লগইন তৈরি
  করলে সেটা এমনিতেই শুধু সেই ব্রাঞ্চের ডেটা দেখবে** — কোনো নতুন
  permission কোড লাগবে না, `TenantScope` + fixed `shop_id` যথেষ্ট।
- অফলাইন সিঙ্কে শপ-মিসম্যাচ প্রোটেকশন ইতিমধ্যে আছে
  (`useOfflineSync.js:78`, `PosController::checkout()`'s শপ-পিন চেক,
  Phase 11/12 — ভুল শপে সিঙ্ক হলে ক্র্যাশ না করে 409/`shop_mismatch` ফ্ল্যাগ
  করে, resolve sheet-এ দেখায়)। স্টক কম থাকলে checkout সবসময় ৪২২ দেয়
  (`offline_replay`-তেও স্টক-চেক স্কিপ হয় না) — মানে দুই ব্রাঞ্চ একসাথে
  অফলাইনে শেষ জোড়া বিক্রির চেষ্টা করলে প্রথম সিঙ্ক সফল, দ্বিতীয়টা
  "পর্যাপ্ত স্টক নেই" ৪২২ পায় যা অফলাইন-কিউ-এর "permanent failure" রিভিউ
  শিটে (drop/cancel/retry) দেখা যায় — ঠিক যেটা Step 4 চেয়েছে, ক্র্যাশ না।
- ভ্যারিয়েন্ট ম্যাট্রিক্স গ্রিড UI ইতিমধ্যে আছে
  (`Stock/Index.vue`'s `gridColorsInput`/`gridSizesInput`/`submitGrid()` →
  `productVariants.bulkStore`) — এক কালার-লিস্ট + এক সাইজ-লিস্ট দিলে পুরো
  matrix এক ক্লিকে স্টকে ওঠে।
- POS-এ ভ্যারিয়েন্ট চিপ, বারকোড→ভ্যারিয়েন্ট রিজলভ, স্টক-শেষ গ্রে-আউট —
  সব `Pos/Index.vue`-এ আগে থেকেই আছে (`variantOf`, `variantLabel`,
  `variantStockLevel`, barcode fallback)।

**সিদ্ধান্ত: ধাপ ১.৩-এর ফলব্যাক লাগবে না — মূল ডিজাইন (opt-in
shared-stock-pool) ইতিমধ্যে কোর কোডে বানানো, টেস্ট করা, এবং প্রোডাকশনে
আছে। নতুন কোনো রিস্কি কোর স্টক-কোড পরিবর্তনের দরকার নেই — শুধু Baby
Park-এর জন্য এটা সঠিকভাবে সেটআপ ও সাইজ/রং-নির্দিষ্ট গ্যাপগুলো পূরণ।**

### যা আসলে নতুন বানাতে হবে (gap analysis)

সব কিছু অডিট করে দেখা গেছে প্রায় সব প্রযুক্তিগত ভিত্তি আগে থেকেই আছে।
বাকি যা genuinely নতুন:

1. **`shop:setup-baby-park` artisan কমান্ড** (idempotent, dry-run) — main
   শপ + ব্রাঞ্চ তৈরি, ফিচার গ্রান্ট, ডিফল্ট ডেটা সিড, ক্রেডেনশিয়াল প্রিন্ট।
2. **`branch:create-user` artisan কমান্ড** — ব্রাঞ্চ-স্কোপড স্টাফ তৈরি
   (ভবিষ্যতের জন্য, আজ phone নম্বর ছাড়াই রেডি রাখা)। নোট: এই অ্যাপে
   `manager` রোল বলে কিছু নেই — শুধু `owner`/`staff`। `staff` রোল ব্যবহার
   করব, `config/staff_permissions.php`-এর সব পারমিশন দিয়ে (মালিক পরে
   Staff পেজ থেকে কমাতে পারবেন)।
3. **`config/business_types.php`-এ নতুন `footwear` এন্ট্রি** — `clothing`
   প্রিসেটের কাছাকাছি (fields: size+color আগে থেকেই আছে) কিন্তু
   জুতা-নির্দিষ্ট ক্যাটাগরি/ইউনিট দিয়ে। `BusinessType` রো নতুন তৈরি হবে।
   বিদ্যমান কোনো business type-এ কোনো পরিবর্তন নয় (pure addition)।
4. **ডিফল্ট সাইজ/রং প্রিসেট, DB-চালিত ও এডিটেবল** — `shops` টেবিলে দুটো
   নতুন nullable কলাম (`default_variant_sizes`, `default_variant_colors`,
   কমা-সেপারেটেড টেক্সট), `SettingController`-এ একটা নতুন
   `updateVariantDefaults()` (বিদ্যমান `updateCreditLimit()`-এর প্যাটার্ন
   কপি), এবং `Stock/Index.vue`-এর গ্রিড ওপেন হওয়ার সময় (ইনপুট ফাঁকা থাকলে)
   এই ডিফল্ট থেকে প্রিফিল। বিদ্যমান কোনো শপের আচরণ বদলায় না (nullable,
   ফাঁকা থাকলে আগের মতোই খালি গ্রিড)।
5. **ক্রস-ব্রাঞ্চ ভ্যারিয়েন্ট রিপোর্ট** — বিদ্যমান
   `Reports::variantProfitReport()` এক-শপ-স্কোপড, `branchProductProfitReport()`
   ক্রস-ব্রাঞ্চ কিন্তু প্রোডাক্ট-লেভেল (ভ্যারিয়েন্ট ভাঙা নেই)। নতুন
   `Reports::branchVariantProfitReport(array $shopIds, $from, $to)` —
   ঠিক `branchProductProfitReport()`-এর SQL-aggregation স্টাইলেই, কিন্তু
   `product_variant_id`/`variant_label` দিয়ে গ্রুপ করে — কোন ব্রাঞ্চে কোন
   সাইজ/রং বেশি বিক্রি হচ্ছে। `ReportController`-এ ঠিক
   `branchProductProfitReportIfMainShop()`-এর প্যাটার্নে গেট করা (main শপ +
   সত্যিকারের ব্রাঞ্চ থাকলেই, `product_variants` ফিচার থাকলেই)।
   `variantInventoryBreakdown()`-এর row-এ `reorder_point` যুক্ত করা হবে
   (এক লাইন সংযোজন) যাতে ফ্রন্টএন্ড "সাইজ শেষ হয়ে আসছে" ফ্ল্যাগ করতে পারে
   — শেয়ার্ড পুলে স্টক সংখ্যা যেহেতু এক, এটা ব্রাঞ্চ-নিরপেক্ষভাবেই সঠিক।
6. **`Reports/Index.vue`-এ ছোট সংযোজন** — নতুন branchVariantProfitReport
   দেখানোর একটা সেকশন (বিদ্যমান branchProductProfitReport সেকশনের পাশে),
   এবং variant inventory টেবিলে "কম স্টক" ফ্ল্যাগ (reorder_point দিয়ে)।
7. **টেস্ট** — নতুন যা বানানো হলো তার জন্য + এই নির্দিষ্ট কম্বিনেশনের
   (২ ব্রাঞ্চ + শেয়ার্ড পুল + ভ্যারিয়েন্ট + অফলাইন) একটা end-to-end
   স্মোক টেস্ট, যদিও অন্তর্নিহিত মেকানিজম আগেই আলাদাভাবে টেস্ট করা।

### যা দরকার নেই (সময় বাঁচানোর জন্য স্পষ্ট করে বলা)
- কোনো নতুন `manager` রোল — বিদ্যমান `staff` রোল + `staff_permissions`
  যথেষ্ট, এবং এটা স্পেকের চাওয়া হার্ডকোড রোলের চেয়ে বেশি ফ্লেক্সিবল।
- `transfer_confirmation` ফিচার — শেয়ার্ড পুলে থাকা প্রোডাক্টের মধ্যে
  ব্রাঞ্চ-টু-ব্রাঞ্চ ট্রান্সফার গাণিতিকভাবে no-op (docblock অনুযায়ী),
  তাই এটা Baby Park-এর জন্য অর্থহীন — গ্রান্ট করছি না।
- `partners`, `vat` ফিচার — স্পেকে উল্লেখ নেই/স্পষ্ট "বন্ধ" বলা আছে।
- কোনো নতুন স্টক-লকিং/পুলিং কোড — বিদ্যমান মেকানিজম যথেষ্ট ও টেস্টেড।

## ধাপ ৫: ফিচার লিস্ট (Ultimate-থেকে + স্ট্রাকচারাল)

Main শপ (`parent_shop_id=null`):
`memo_whatsapp, memo_print, reports, expenses, accounts, quotations,
cashier_management, export, barcode_printing, purchases, returns, damages,
stock_count, product_variants, whatsapp_bulk, activity_log, promotions,
loyalty_points, hr_payroll, suppliers, low_stock_alerts, wholesale_pricing,
installment_sales, shop_backup, online_business, shared_catalog,
shared_stock_pool`

ব্রাঞ্চ শপ: উপরের লিস্ট থেকে `shared_catalog`/`shared_stock_pool` বাদ
(এই দুটো কনভেনশন অনুযায়ী main-শপ-only — `stockPoolMemberIds()` মাস্টারের
flag-ই পড়ে)।

বাদ (অপ্রাসঙ্গিক): `restaurant_tables`, `ingredient_tracking`,
`prescription_records`, `weight_based_selling`, `serial_tracking`
(IMEI), `batch_tracking`, `vat`, `partners`, `unit_conversion` (জুতা
জোড়ায় বিক্রি হয়, pack-breakdown লাগে না)।

## সংযোজন (একই দিন): এক্সেল/CSV দিয়ে সাইজ/রং ভ্যারিয়েন্ট বাল্ক-আপলোড

Khaled সরাসরি সফটওয়্যারে টাইপ না করে এক্সেলে ডেটা তুলে আপলোড করতে চাওয়ায়
যুক্ত হলো। বিদ্যমান `ProductImportController` (route: `/app/products/import`,
টেমপ্লেট: `/app/products/import/template`) ইতিমধ্যে ছিল, কিন্তু তার
`size`/`color` কলাম সরাসরি `Product.size`/`Product.color`-এ লিখত (প্রতি
সারি = আলাদা পণ্য, `ProductVariant` সিস্টেমের সাথে সম্পর্কহীন)। Khaled
নিশ্চিত করলেন তিনি **গ্রুপড ভ্যারিয়েন্ট** চান (Stock পেজের গ্রিডের মতো,
একটা পণ্যের নিচে সব সাইজ/রং), যাতে নতুন বানানো ক্রস-ব্রাঞ্চ সাইজ/রং
রিপোর্টেও (`branchVariantProfitReport`) এটা সঠিকভাবে ধরা পড়ে।

**কোনো নতুন কলাম লাগেনি** — একই ১২টা কলাম। পরিবর্তন শুধু
`product_variants` ফিচার অন থাকা শপের জন্য: একই `name`+`category` নিয়ে
একাধিক সারি (সাইজ/রং ভরা) থাকলে সব এক পণ্যের নিচে গ্রুপ হয়ে
`ProductVariant` রো হিসেবে তৈরি/আপডেট হয় (বারকোড দিয়ে মিলায়, না থাকলে
সাইজ+রং দিয়ে) — ফিচার বন্ধ থাকা কোনো শপের আচরণ অপরিবর্তিত। পুনরায়
আপলোড করলে (বারকোড মিলে গেলে) স্টক ডুপ্লিকেট না হয়ে আপডেট হয়
(`applyStockDelta`, শেয়ার্ড পুলেও সঠিকভাবে কাজ করে)। মূল প্রোডাক্টের
`stock` কলাম সবসময় তার ভ্যারিয়েন্টগুলোর যোগফল থাকে (বিদ্যমান ইনভ্যারিয়েন্ট
রক্ষা করে)। আপলোডের শেষে, main শপে `shared_catalog` থাকলে প্রতিটা
ব্রাঞ্চে একবার ক্যাটালগ সিঙ্কও চালানো হয় (ম্যানুয়াল "সিঙ্ক" বাটনের মতোই) —
নতুবা বাল্ক-ইমপোর্ট করা পণ্য শাখা-২-এ দেখা যেত না যতক্ষণ কেউ ম্যানুয়ালি
সিঙ্ক না করত।

একটা বারকোড অন্য পণ্যের ভ্যারিয়েন্টে আগে থেকে থাকলে ওই সারি নিরাপদে বাদ
পড়ে (কোনো খালি/অসম্পূর্ণ পণ্য তৈরি হয় না)। একটা নাম+ক্যাটাগরি মিলে যাওয়া
পুরোনো সাধারণ পণ্যে (যার আগে থেকে স্টক আছে, কোনো ভ্যারিয়েন্ট নেই) প্রথমবার
ভ্যারিয়েন্ট বসানোর ক্ষেত্রে ম্যানুয়াল গ্রিডের মতো "বাকিটা কি নষ্ট ধরবেন?"
প্রশ্ন একটা ব্যাচ-আপলোডে করা যায় না — তাই এই ক্ষেত্রে ইচ্ছাকৃতভাবে একদম
নতুন, আলাদা পণ্য তৈরি হয় (স্টক কখনো নীরবে হারায় না)।

Tests: `tests/Feature/ProductImportTest.php`-এ ৭টা নতুন টেস্ট (গ্রুপিং,
ফিচার-বন্ধ অবস্থায় পুরনো আচরণ অক্ষুণ্ণ, পুনরায়-আপলোডে স্টক আপডেট, নতুন
সাইজ যুক্ত হওয়া, বারকোড-কনফ্লিক্ট নিরাপদে বাদ, ব্রাঞ্চ-সিঙ্ক)।

## ধাপ ৭: রোলব্যাক/ডিপ্লয় প্রসঙ্গ

`shop:setup-baby-park --rollback` শুধু phone 01864987754-এর শপ/ইউজার/
ক্যাটাগরি/ইউনিট ডিলিট করবে, **কোনো Sale/SaleItem থাকলে রিফিউজ করবে**
(সেফটি গার্ড) — বিস্তারিত `docs/BABY_PARK_LAUNCH.md`-এ।

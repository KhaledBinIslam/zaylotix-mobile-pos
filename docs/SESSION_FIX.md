# Session / 419 ("Page Expired") — স্থায়ী সমাধান

Khaled-এর নতুন মাস্টার-প্রম্পটের "ফেজ ০.১"। নোট: FIX_PLAN.md-এ ইতিমধ্যেই একটা
সম্পূর্ণ আলাদা "Phase 0 / 0.1-0.6" আছে (ShopProvisioner idempotency, business-
type drift ইত্যাদি — একটা আগের মাস্টার প্রম্পট থেকে) — সংখ্যা একই হলেও এই দুটো
সম্পূর্ণ ভিন্ন কাজ। বিভ্রান্তি এড়াতে এই কাজটা FIX_PLAN.md-এ "Phase 13" হিসেবে
লগ করা হয়েছে, কিন্তু এই ফাইলের ভেতরে Khaled-এর নিজের "০.১" নামেই রাখা হলো।

## ধাপ ১ — মূল কারণ (ফাইল:লাইন সহ)

### ১. `SESSION_LIFETIME` — কোড বলে ৭২০ মিনিট, বাস্তবে ছিল ১২০ (সবচেয়ে বড় সন্দেহভাজন কারণ)
`config/session.php:35-41` একটা আগের ফিক্সে (`ebd1eef`, 2026-08-17,
"Fix session-expiry-mid-work...") ডিফল্ট `120` থেকে `720` (এক শিফট+) করা
হয়েছিল, সাথে বিস্তারিত কমেন্ট। কিন্তু `env('SESSION_LIFETIME', 720)` — এই
ডিফল্টটা তখনই কার্যকর হয় যখন `.env`-এ এই কী সেট করা **নেই**। বাস্তবে
`.env.example:37` এবং এই মেশিনের `.env:31` দুটোতেই তখনও সরাসরি
`SESSION_LIFETIME=120` লেখা ছিল — Laravel-এর ইনস্টলার-ডিফল্ট মান যা
`.env`-এ হার্ডকোড হয়ে গিয়েছিল। `.env` গিট-ইগনোর্ড (কখনো `git pull`-এ
বদলায় না), তাই ওই আগের ফিক্স শুধু কোডের fallback বদলেছে — প্রোডাকশনের আসল
`.env`-এ হাত পড়েনি। **অত্যন্ত সম্ভাবনা আছে যে প্রোডাকশনেও এখনো
`SESSION_LIFETIME=120` আছে, মানে আসল সেশন লাইফটাইম উদ্দেশ্য করা ১২ ঘন্টার
বদলে এখনো ২ ঘন্টা** — যেকোনো ২ ঘন্টার বেশি নিষ্ক্রিয়তা (দুপুরের বিরতি, কম
কাস্টমার সময়, রাতভর POS ট্যাব খোলা) সেশন row সুইপড/মুছে যায়, আর তখন পেজে
বসে থাকা পুরনো CSRF টোকেন অচল হয়ে যায় → 419।

**যাচাই**: `git log -p -- config/session.php` দেখায় শুধু `lifetime`-এর
মান/কমেন্ট বদলেছিল, `.env`/`.env.example` কখনো সাথে আপডেট হয়নি (`git log
--oneline -- .env.example` এ কোনো SESSION-সংক্রান্ত কমিট নেই)।

### ২. ডিপ্লয় কমান্ডের `cache:clear` — সেশন মুছে না (নিরাপদ, কিন্তু driver ভুল হলে না)
`config/session.php:21` এর ডিফল্ট `SESSION_DRIVER` সবসময়ই `database`
(initial commit থেকেই) — `database/migrations/2024_01_01_000004_create_users_table.php:34-41`
এ `sessions` টেবিল তৈরি হয়। `cache:clear` শুধু `cache`/`cache_locks` টেবিল
(আলাদা মাইগ্রেশন, `0001_01_01_000001_create_cache_table.php`) ফাঁকা করে —
`sessions` টেবিলের সাথে এর কোনো সম্পর্ক নেই যতক্ষণ `SESSION_DRIVER=database`।
**শর্ত**: প্রোডাকশনের `.env`-এ যদি কখনো `SESSION_DRIVER=file`/`cache`/অন্য
কিছু সেট করা থাকে (আমি সরাসরি প্রোডাকশনের `.env` পড়তে পারি না — SSH key
নেই এই মেশিনে), তাহলে `cache:clear` কিছুক্ষেত্রে সেশন মুছতে পারত। এটা
নিশ্চিত করতে Khaled-কে প্রোডাকশনের `.env`-এর `SESSION_*` লাইনগুলো একবার
দেখতে বলা হয়েছে (নিচে ডিপ্লয় নোটে)।

### ৩. 419/401 হ্যান্ডলিং — আংশিক আগেই ঠিক, কিন্তু কোনো রিট্রাই/রিফ্রেশ মেকানিজম ছিল না
`bootstrap/app.php:49-100` (আগের ফিক্স `ebd1eef`-এর অংশ) ইতিমধ্যেই: raw-fetch
কলারদের (POS checkout ইত্যাদি) জন্য 419-কে একটা পরিষ্কার JSON
`{message: '...'}` + status 419 করে রিটার্ন করে (silent redirect-follow করে
না), আর প্লেইন Inertia রিকোয়েস্টের জন্য `back()->with('error', ...)` — flash
toast দেখায় (`AppLayout.vue:250` এটা শোনে)। **কিন্তু** — কোথাও কোনো টোকেন
রিফ্রেশ বা রিট্রাই ছিল না: `resources/js/support/fetchWithSessionRetry.js`
(আগের ফিক্সেই তৈরি) শুধু ৪০১-এ একবার রিট্রাই করত, ৪১৯-কে স্পর্শই করত না।

### ৪. ক্লায়েন্ট CSRF টোকেনের উৎস — একবার সেট, কখনো রিফ্রেশ হয় না
`resources/views/app.blade.php:7` — `<meta name="csrf-token" content="{{ csrf_token() }}">`,
পেজ প্রথম লোড হওয়ার মুহূর্তে একবারই রেন্ডার হয়। প্রতিটা raw-fetch কল সাইট
(`Pos/Index.vue:683`, `Clothing/Pos.vue:397,444`, `Restaurant/Order.vue`,
`Returns/Index.vue`, `useOfflineSync.js:106`) এই স্ট্যাটিক মেটা ট্যাগ থেকেই
`X-CSRF-TOKEN` হেডার বানায় — পুরো ট্যাব খোলা থাকা অবস্থায় এটা কখনো আপডেট হয়
না। এর বিপরীতে, Inertia-র নিজের axios-চালিত রিকোয়েস্ট Laravel-এর
`XSRF-TOKEN` কুকি পড়ে, যা Laravel প্রতিটা রেসপন্সেই নতুন করে সেট করে — তাই
Inertia নেভিগেশন স্বয়ংক্রিয়ভাবে অনেকটা self-heal করে, কিন্তু raw fetch করে
না। **এটাই precisely কারণ raw-fetch স্ক্রিনগুলো (POS checkout, অফলাইন
সিঙ্ক replay) এই বাগের সবচেয়ে বেশি শিকার।**

### ৫. বড় ফাঁক পাওয়া গেছে: `useOfflineSync.js`-এ `fetchWithSessionRetry` ব্যবহারই হতো না
`useOfflineSync.js`-এর `trySync()` সরাসরি বেয়ার `fetch()` কল করত (লাইন
~89, ফিক্সের আগে) — ৪০১-এর জন্য বিদ্যমান এক-বার-রিট্রাই সুরক্ষাটাও এখানে
ছিলই না। আর সবচেয়ে গুরুত্বপূর্ণ: ৪০১/৪১৯ রেসপন্স আগের কোডে কোনো নির্দিষ্ট
শাখায় না পড়ে সাধারণ `res.status >= 400 && res.status < 500` বাকেটে পড়ত,
যা `'permanent'` (স্থায়ী ব্যর্থতা, "resolve" UI দরকার) হিসেবে মার্ক করত —
**এটাই সম্ভবত সেই আগের অমীমাংসিত "1 sale(s) failed to sync" বাগ, যেখানে
সার্ভার লগে কিছুই পাওয়া যায়নি** (একটা 401/419 মিডলওয়্যার-লেভেলে ঘটে,
ValidationException-এর মতো "report" হয় না Laravel-এর ডিফল্ট আচরণে এই
কনফিগারেশনে)।

### ৬. `Clothing/Pos.vue` — `Pos/Index.vue`-এর সাথে প্যারিটি ছিল না
`Pos/Index.vue`, `Restaurant/Order.vue`, `Returns/Index.vue` — এই তিনটা
`fetchWithSessionRetry` ব্যবহার করত (৪০১ সুরক্ষা), কিন্তু `Clothing/Pos.vue`
এর checkout (লাইন ৩৯৭) ও gateway checkout (লাইন ৪৪৪) বেয়ার `fetch()`-ই
ব্যবহার করত — একই "parity gap" প্যাটার্ন যা আগেও Clothing/Pos.vue-তে
পাওয়া গেছে (Phase 12-এ idempotency/shop-pin)।

### ৭. লগআউট রিডাইরেক্ট — ফেরার URL মনে রাখত না
`EnsureShopUser.php:41` প্লেইন `redirect()->route('login')` করত — Laravel-এর
`redirect()->guest()` হেল্পার (যা session-এ `url.intended` সেভ করে) ব্যবহার
করত না, যদিও `LoginController.php:36`-এ `redirect()->intended(...)` **আগে
থেকেই** লগইনের পর সেই intended URL পড়ার জন্য রেডি বসে ছিল — অর্ধেক-তৈরি একটা
ফিচার, ব্যবহারই হতো না আসলে।

### ৮. `public/sw.js` — HTML/টোকেন cache করে কিনা
`public/sw.js:130-143` নেটওয়ার্ক-ফার্স্ট, `cache: 'no-store'` দিয়ে —
কিন্তু `res.ok` হলে পুরো `/app/*` রেসপন্স (পূর্ণ HTML পেজ লোড-সহ, যেটার
ভেতরে সেই সময়ের CSRF মেটা ট্যাগ বেক করা থাকে) অফলাইন-ফলব্যাকের জন্য ক্যাশ
করে — কখনো 4xx/5xx ক্যাশ করে না (ভালো, আগের থেকেই)। এটা মূল কারণ না, কিন্তু
সহায়ক ফ্যাক্টর: অফলাইনে থাকা অবস্থায় যদি একটা পুরনো cached HTML থেকে পেজ
খোলে, সেই HTML-এর মেটা ট্যাগ আরও পুরনো হতে পারে।

## ধাপ ২ — সমাধান (যা করা হয়েছে)

1. **`SESSION_LIFETIME=720`** — `.env.example` ও এই মেশিনের `.env` ঠিক
   করা হয়েছে। **প্রোডাকশনের `.env`-এ এটা ম্যানুয়ালি বদলাতে হবে** (নিচে
   ডিপ্লয় নোট দেখুন) — এটা নিরাপদ, কাউকে লগআউট করবে না (session driver
   অপরিবর্তিত, শুধু কতক্ষণ idle থাকলে session expire হবে তার সীমা বাড়ছে)।
2. **নতুন `GET /app/csrf-token`** (`CsrfTokenController::show()`) — লগইন
   করা থাকলেই বর্তমান সেশনের সত্যিকার টোকেন ফেরত দেয়।
3. **`fetchWithSessionRetry.js` বাড়ানো হয়েছে** — ৪০১ (আগে থেকে ছিল) +
   নতুন ৪১৯ হ্যান্ডলিং: ৪১৯ পেলে `refreshCsrfToken()` কল করে (নতুন
   এন্ডপয়েন্ট থেকে), পেজের মেটা ট্যাগ আপডেট করে (যাতে পরের যেকোনো
   raw-fetch কলও উপকৃত হয়, শুধু এই একটা না), আর সেই নতুন টোকেন দিয়ে ঠিক
   একবার রিট্রাই করে।
4. **`Clothing/Pos.vue`** — checkout ও gateway-checkout fetch এখন
   `fetchWithSessionRetry` দিয়ে (`Pos/Index.vue`-এর সাথে প্যারিটি)।
5. **`useOfflineSync.js`** — `trySync()` এখন `fetchWithSessionRetry`
   ব্যবহার করে (আগে বেয়ার `fetch()` ছিল), এবং একটা নতুন pure ফাংশন
   `offlineSyncFailureKind()` (`offlineQueue.js`) ৪০১/৪১৯/৫xx-কে
   `'transient'` হিসেবে ক্লাসিফাই করে — `'permanent'`/"resolve" UI-তে আর
   পড়বে না। বিল কখনো হারায় না, pending-ই থাকে, পরে নিজে বা ম্যানুয়াল
   রিট্রাইয়ে সিঙ্ক হবে।
6. **নতুন `sessionKeepAlive.js`** — লগইন করা শপ সেশনে (`app.js`) প্রতি ১০
   মিনিটে (অনলাইন থাকলে) + ট্যাব ফোকাসে ফিরলে + নেটওয়ার্ক ফিরলে —
   স্বয়ংক্রিয়ভাবে টোকেন রিফ্রেশ করে। এটাই আসল, proactive সমাধান — একটা POS
   ট্যাব পুরো শিফট খোলা থাকলেও এটা কখনো যথেষ্ট stale হতে দেয় না, তাই
   fetchWithSessionRetry-র "after the fact" রিকভারি কমই লাগবে বাস্তবে।
7. **`EnsureShopUser.php`** — `redirect()->route('login')` →
   `redirect()->guest(route('login'))`। সত্যিকার লগআউট হলে এখন
   লগইনের পরে সেই আগের পেজেই ফিরে যাবে (`LoginController`-এর বিদ্যমান
   `redirect()->intended()` ব্যবহার করে — নতুন কিছু বানাতে হয়নি)।
8. **`bootstrap/app.php`** — ৪১৯-এ `back()`-এর সাথে এখন
   `->withInput($request->except(['password','_token']))` — একটা stale
   লগইন ফর্ম সাবমিট ৪১৯ পেলেও ফোন/ইমেইল ফিল্ড হারাবে না (পাসওয়ার্ড কখনো
   session-এ round-trip করবে না, ইচ্ছাকৃতভাবে)।

### ইচ্ছাকৃতভাবে যা করা হয়নি (স্কোপ সীমিত রাখতে)
- **Inertia-level রিকোয়েস্টে সত্যিকার "silent retry"**: `bootstrap/app.php`-এর
  ৪১৯ হ্যান্ডলার Inertia রিকোয়েস্টের জন্য ইতিমধ্যেই `back()`-একটা
  redirect-এ রূপান্তর করে ফেলে সার্ভার-সাইডেই (ইউজারকে raw error পেজ না
  দেখানোর জন্য, আগের ফিক্সেই ছিল) — তাই ক্লায়েন্ট কখনো আসল ৪১৯ স্ট্যাটাসটা
  দেখেই না, রিট্রাই করার কিছু নেই ক্লায়েন্ট-সাইডে। পুরো app-জুড়ে প্রতিটা
  Inertia ফর্ম সাবমিটের এই error-responder বদলানো অনেক বড় ঝুঁকি (পুরো
  অ্যাপের error handling একসাথে বদলে যেত) — এর বদলে উপরের keep-alive
  (proactive) + withInput (reactive, graceful) মিলিয়ে এটা যথেষ্ট কভার করে।
- **Redis session/cache/queue**: Khaled নিজেই এটাকে "স্কেলের জন্য
  অতিরিক্ত" বলেছেন, আর Phase ২ (স্কেল হার্ডেনিং) এটার জন্যই আলাদা করে
  রাখা — ওখানেই করা হবে, এখানে স্কোপ-ক্রিপ এড়াতে বাদ।
- **"ফর্মের ডাটা না হারিয়ে" আক্ষরিক অর্থে যেকোনো ফর্ম** (লগইন ছাড়া অন্য
  কোনো Inertia ফর্ম, যেমন সেটিংস) **সত্যিকার লগআউটের পর পুনরুদ্ধার** —
  এটা একটা অনেক বড়, আলাদা ফিচার (re-login-এর পর arbitrary ফর্ম state
  resume) যেটা এই ফিক্সের স্কোপে নেই। লগইন ফর্ম নিজে (৭ নং) ও
  intended-URL (৭ নং) কভার করা হয়েছে, যেটা মূল complaint-টা address করে।

## ধাপ ৩ — টেস্ট

- **PHP**: `tests/Feature/SessionFixTest.php` (৪টা) — টোকেন এন্ডপয়েন্ট
  কাজ করে + auth লাগে, intended-URL রিডাইরেক্ট কাজ করে
  (লগইনের-পর-ফিরে-যাওয়া সহ), পোল রিকোয়েস্ট এখনো প্লেইন ৪০১ পায় (redirect
  না)। (একটা সত্যিকার ৪১৯/TokenMismatchException HTTP টেস্টে reproduce
  করা যায় না — Laravel নিজেই `$app->runningUnitTests()` true থাকলে CSRF
  verification পুরোপুরি স্কিপ করে, সব টেস্টেই — তাই সেই অংশটা নিচের JS
  ইউনিট টেস্টে কভার হয়েছে।)
- **JS (vitest)**: `fetchWithSessionRetry.test.js` (৮টা নতুন — ৪১৯
  রিট্রাই/টোকেন-রিফ্রেশ/token-fetch-failure কেস), `offlineQueue.test.js`
  (৪টা নতুন — `offlineSyncFailureKind`), `sessionKeepAlive.test.js` (৬টা
  নতুন — ১০-মিনিট ইন্টারভাল, অফলাইনে চুপ, visibilitychange, online ইভেন্ট,
  ডাবল-স্টার্ট সুরক্ষা)।
- পুরো `php artisan test` (৮৪৮+৪) ও `npx vitest run` (১০৫+১৯) সবুজ।

## ⚠️ ডিপ্লয়ের আগে — SESSION_DRIVER নিশ্চিত করো

এই ফিক্সে **কোনো session driver বদল নেই** (database-ই ছিল, database-ই
থাকছে) — শুধু `.env`-এর `SESSION_LIFETIME` মান ঠিক করা হচ্ছে, যেটা
**কাউকে লগআউট করবে না**।

কিন্তু deploy করার আগে, SSH করে একবার দেখো প্রোডাকশনের আসল `.env`-এ
`SESSION_DRIVER` কী আছে:

```
ssh -p 65002 u441890539@82.29.157.97 "cd domains/zaylotix.com/pos_app && grep '^SESSION_' .env"
```

- যদি `SESSION_DRIVER=database` দেখায় (যেটাই আমার ধারণা) — নিচের ডিপ্লয়
  কমান্ড সরাসরি চালাও, কোনো সমস্যা নেই।
- যদি অন্য কিছু দেখায় (`file`/`cookie`/`array` ইত্যাদি) — **আমাকে জানাও,
  deploy থামিয়ে**। সেটা `database`-এ বদলাতে হবে (এটাই সঠিক ফিক্স), কিন্তু
  সেটা করলে **সবাই একবার লগআউট হয়ে যাবে** — তোমার অনুমতি লাগবে কখন এটা
  করা হবে।

## ডিপ্লয় নির্দেশনা

```
ssh -p 65002 u441890539@82.29.157.97 "cd domains/zaylotix.com/pos_app && git pull && php artisan config:clear && php artisan cache:clear"
```

তারপর, উপরের grep দিয়ে `SESSION_DRIVER=database` নিশ্চিত হলে, `.env`-এ
`SESSION_LIFETIME` লাইনটা ম্যানুয়ালি `720` করো (nano/vi দিয়ে, বা):

```
ssh -p 65002 u441890539@82.29.157.97 "cd domains/zaylotix.com/pos_app && sed -i 's/^SESSION_LIFETIME=.*/SESSION_LIFETIME=720/' .env && php artisan config:clear"
```

কোনো migration নেই এই ফেজে।

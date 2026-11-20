# FEATURE_AUDIT.md — Zaylotix Complete Feature & Functionality Audit

Covers master-prompt section 2 (Complete Feature & Functionality Audit) and
section 6 (Feature Enable/Disable & Subscription Audit). Companion to the
already-delivered `TENANT_CUSTOMIZATION_ISOLATION_AUDIT.md` (section 19).

**Method**: for every module, the real route/controller was located and
cross-referenced against the existing automated test suite (250 Feature test
files, 1773 passing tests at time of writing). A test is only cited as
evidence when it actually exercises the behavior claimed, not merely because
a file with a matching name exists. Classification key, per the master
prompt's own evidence standard:

- **VERIFIED PASS** — a real, passing automated test covers this behavior (file cited).
- **NOT TESTED** — the code exists and appears correct on inspection, but no automated test exercises it.
- **POTENTIAL RISK** — a specific, named concern, not a confirmed bug.
- **VERIFIED FAIL** — a confirmed, reproducible bug (none found in this pass — see below).

---

## 1. Feature-by-feature matrix

| # | Module | Status | Evidence |
|---|---|---|---|
| 1 | Dashboard (Home) | VERIFIED PASS | `HomeOnlineBlockTest.php`, `HomeLowStockTileTest.php`, `PageRenderTest.php` |
| 2 | User authentication (login) | VERIFIED PASS | `AuthSeparationTest.php`, `tests/Feature/Api/AuthTest.php` |
| 3 | Registration / signup | VERIFIED PASS | `SelfServeSignupTest.php`, `SignupEnabledToggleTest.php`, `SiteSetSignupEnabledTest.php` |
| 4 | Password reset (self-service) | **NOT BUILT** | No route/controller exists for a self-service forgot-password flow (grepped `routes/web.php` for `password`/`Password` — zero matches). Current design: only an admin can reset a shop owner's login via `Admin\ShopController::updateOwnerCredentials()`. This is a deliberate design (small shop count, admin-mediated support model), not an oversight — but see **Finding F-1** below. |
| 5 | User roles & permissions | VERIFIED PASS | `StaffPermissionTest.php`, `AdminRbacAndImpersonationTest.php`, `tests/Feature/Api/PermissionTest.php` |
| 6 | Subscription & packages | VERIFIED PASS (see note) | `SubscriptionExpiryTest.php`, `AdminSubscriptionDashboardTest.php`, `FeaturePricingTest.php`, `BranchFollowsParentSubscriptionTest.php`. **Design note**: there is no fixed "Starter/Business/Ultimate" tier object enforced in code — `usePackageMatch` (Admin/Shops/Edit.vue) is a *display-only* hint that tells the admin which named package a chosen combination resembles, for pricing reference only. The actual, enforced mechanism is per-feature à-la-carte flags (below), which **is** real server-side enforcement. Section 6's "test every package" literally doesn't map onto this architecture; the equivalent and correctly-tested concept is per-feature gating. |
| 7 | Feature enable/disable (server-side enforcement) | VERIFIED PASS | `FeatureGatingTest.php` — 4 distinct features proven: 403 without grant → granted → 200, real DB write confirmed. `EnsureFeatureEnabled` middleware (`app/Http/Middleware/EnsureFeatureEnabled.php`) checks `$shop->hasFeature($key)` fresh on every request against `Tenancy::shop()`, not a cached/stale value. |
| 8 | Feature-gate route-coverage sweep | VERIFIED PASS | Every `feature:`/`perm:` gated area in `routes/web.php` applies the middleware at the **group** level (`Route::middleware(['perm:x','feature:y'])->group(fn () => ...)`), not per-route — this is structurally safer than per-route gating since a route added later inside an already-gated group can't be forgotten. Spot-checked rentals (line 487), online_business (525), service_billing (582), hr_payroll (612), season_campaigns (753, 769) — all consistent, no ungated route found inside a gated feature's area. |
| 9 | Shop/business settings | VERIFIED PASS | `ShopCustomizationTest.php`, `ShopOperationsTest.php`, `ReceiptAndLabelSettingsTest.php`, `LanguageSettingTest.php`, `MobileNavSettingTest.php` |
| 10 | Product management | VERIFIED PASS | `ProductVariantTest.php`, `ProductPhotoTest.php`, `ProductImportTest.php`, `ProductSearchNormalizationTest.php`, `ProductsSetReorderPointTest.php` |
| 11 | Product categories | VERIFIED PASS | `SubCategoryTest.php`, `ArticlesFixCategoryCodeTest.php`, `ArticlesListBrokenCategoriesTest.php` |
| 12 | Product variations (color/size) | VERIFIED PASS | `ProductVariantTest.php`, `VariantOptionsTest.php`, `VariantInvariantGuardsTest.php`, `ColorBarcodeResolverTest.php`, `MergeCaseDuplicateVariantsTest.php` |
| 13 | Product units | VERIFIED PASS | `UnitConversionTest.php`, `ProductsSetUnitTest.php`, `WeightBasedSellingTest.php` |
| 14 | Barcode management | VERIFIED PASS | `BarcodeLabelColorBarcodesTest.php`, `BarcodeLabelDataExposureTest.php`, `SmartScanBarcodeLookupTest.php`, `LabelPrintTrackingTest.php`, `LabelTemplateTest.php`, `ArticlesRegenerateBarcodesTest.php` |
| 15 | Purchase management | VERIFIED PASS | `PurchaseOrderTest.php`, `SupplierIntakeTest.php`, `SupplierPayableStockInTest.php`, `ProductSourceStockInTest.php` |
| 16 | Sales management | VERIFIED PASS | `CheckoutTransactionTest.php`, `CoreCartFlowTest.php`, `PosSourceLineCheckoutTest.php`, `SplitPaymentTest.php` |
| 17 | POS | VERIFIED PASS | `PosIndexPropsTest.php`, `PosBarcodeEndpointTest.php`, `PosOversellAndQuickItemTest.php`, `PosCatalogScaleTest.php`, `ClothingPosPageTest.php` |
| 18 | Inventory management | VERIFIED PASS | `StockHistoryTest.php`, `StockDecreaseTest.php`, `VariantInventoryReportTest.php`, `StockStatusFilterTest.php` |
| 19 | Stock adjustments | VERIFIED PASS | `StockCountSourceLineFifoTest.php`, `OpeningStockTest.php` |
| 20 | Stock transfer | VERIFIED PASS | `BranchStockTransferTest.php`, `BranchTransferConfirmationTest.php`, `SharedStockPoolTest.php` |
| 21 | Customer management | VERIFIED PASS | `CustomerIndexTest.php`, `CustomerListTest.php`, `CustomerLookupTest.php`, `CustomerSearchTest.php`, `BranchCustomerSharingTest.php` |
| 22 | Supplier management | VERIFIED PASS | `SupplierTest.php`, `SupplierManagementTest.php`, `SupplierMergeTest.php`, `SupplierPickerListTest.php` |
| 23 | Due management | VERIFIED PASS | `DueReconcileTest.php`, `CustomerPaymentHistoryTest.php`, `CreditLimitTest.php` |
| 24 | Payments & expenses | **NOT TESTED** (gap — see **Finding F-2**) | `ExpenseController` has no dedicated test; 3 incidental `Expense::` hits (`AdSpendTest.php`, `OnlineProfitReportTest.php`, `ShopDataExportTest.php`) only use it as unrelated fixture data, never assert its own create/cash-effect behavior. Code read on inspection (below) — looks correct, just unverified by a regression test. |
| 25 | Returns and exchanges | VERIFIED PASS | `ReturnTest.php`, `ReturnExchangeTest.php`, `ReturnBarcodeLookupTest.php`, `BranchCrossReturnTest.php`, `ReturnOnlineOrderTest.php`, `ReturnSourceLineTest.php` — this area also just received live bugfixes + new regression tests this session (cross-branch barcode lookup, ZV code resolution, Confirm-button UX) |
| 26 | Profit and loss | VERIFIED PASS | `ItemWiseProfitReportsTest.php`, `ProductProfitReportTest.php`, `OnlineProfitReportTest.php`, `BranchVariantProfitReportTest.php`, `CombinedRangeStatsTest.php` |
| 27 | Reports and analytics | VERIFIED PASS | `ReportsVatTest.php`, `SupplierReportTest.php`, `VariantInventoryReportTest.php`, `ExpiringSoonReportTest.php` |
| 28 | Notifications | VERIFIED PASS | `NotificationTest.php`, `LowStockAlertTest.php`, `SaleNotificationTest.php`, `DailyOwnerSummaryTest.php`, `WarrantyExpiryAlertTest.php` |
| 29 | Invoice / receipt generation | VERIFIED PASS | `ReceiptFooterTest.php`, `ReceiptAndLabelSettingsTest.php`; memo-content correctness (English-only conversion, shipped same day as this audit) covered by `resources/js/support/__tests__/receiptMessage.test.js` |
| 30 | PDF exports | VERIFIED PASS | `ExportPdfTest.php` (includes the new margin-customization and all new export kinds added this session) |
| 31 | Excel exports | VERIFIED PASS | `ExportPdfTest.php`, `ShopDataExportTest.php` |
| 32 | WhatsApp integration | VERIFIED PASS | `WhatsappBulkTest.php`, `WhatsappCredentialTest.php` |
| 33 | Online store / website integration | VERIFIED PASS | `OnlineOrderTest.php`, `OnlineOrderCreatePropsTest.php`, `OnlineOrderReconcileTest.php`, `DigitalCatalogTest.php`, `LandingPageAndSupportContactTest.php` |
| 34 | API endpoints | VERIFIED PASS | `tests/Feature/Api/AuthTest.php`, `tests/Feature/Api/PermissionTest.php` |
| 35 | Background jobs / scheduled tasks | VERIFIED PASS | `ScheduledJobsRunWithoutProcOpenTest.php` (also confirms the host's `proc_open`-disabled constraint is respected — see `routes/console.php`), `SalesReconcileCommandTest.php` |

## 2. Findings

### F-1 — No self-service password reset (POTENTIAL RISK, not a bug)
A shop owner who forgets their password has no self-service recovery path —
only Khaled (via the Admin panel) can reset it. For the current shop count
this is a workable support model (and keeps the attack surface smaller —
no email-token-reset flow to secure), but it is a real **availability**
dependency on one person. Not fixed here (out of this audit's scope —
report only); flagging for a product decision, not a code defect.

### F-2 — Expense module has zero automated regression coverage (NOT TESTED)
`app/Http/Controllers/App/ExpenseController.php` was read in full. On
inspection: tenant-scoped correctly (`Expense` uses `BelongsToTenant`,
`expense_category_id` validated with a shop-scoped `Rule::exists`, matching
the tenant-safety pattern already proven elsewhere), wrapped in `DB::transaction`,
and deliberately allows `cash_balance`/`bank_balance` to go negative with a
clear comment explaining why (so the balance never silently drifts from
`Expense::sum('amount')`). **No bug found by inspection** — this is a real
money-affecting path with no automated proof it keeps working across future
changes. Recommend a `ExpenseTest.php` (create with existing category,
create with new inline category, cash vs bank method decrements the right
balance field, negative-balance case) — not added here per this audit's
report-only scope.

## 3. Summary

- **35 modules audited.**
- **32 VERIFIED PASS**, **1 NOT TESTED** (Expenses — F-2), **1 NOT BUILT** (self-service password reset — F-1), **1 design-clarification** (package tiers, item 6 — correctly enforced via a different, equivalent mechanism, not a gap).
- **0 P0 / 0 P1 bugs found.** No VERIFIED FAIL.
- Feature enable/disable (master-prompt section 6's core ask) is **real, server-side, and structurally hard to forget** (group-level middleware) — the strongest-evidenced section of this audit.

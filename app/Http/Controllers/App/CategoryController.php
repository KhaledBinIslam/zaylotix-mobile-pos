<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Category → sub-category management, SEAMAN SHOE STORE Phase 2
 * (feature: sub_categories — the route group is gated, so no other shop
 * ever sees this page or can create a child category).
 *
 * The owner's principle, verbatim from the spec: a CATEGORY is what a
 * customer asks for ("show me shoes"); every kind of that thing is a
 * SUB-CATEGORY under it. Max two levels, enforced here where the refusal
 * can explain itself.
 */
class CategoryController extends Controller
{
    public function index()
    {
        $categories = ProductCategory::withCount('products')->orderBy('name')->get();

        return Inertia::render('App/Categories/Index', [
            // Phase 3 — reusable size sets; a category may name one as its
            // default so the variant grid prefills with the right sizes
            'sizeSets' => \App\Models\SizeSet::orderBy('name')->get(['id', 'name', 'sizes']),
            'tree' => $categories->whereNull('parent_id')->values()->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'emoji' => $c->emoji,
                'products_count' => $c->products_count,
                'size_set_id' => $c->size_set_id,
                'children' => $categories->where('parent_id', $c->id)->values()->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'emoji' => $s->emoji,
                    'products_count' => $s->products_count,
                    'size_set_id' => $s->size_set_id,
                ])->all(),
            ])->all(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'emoji' => ['nullable', 'string', 'max:8'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        if ($refusal = $this->refuseBadParent($data['parent_id'] ?? null)) {
            return $refusal;
        }

        $category = ProductCategory::create([
            'name' => $data['name'],
            'name_en' => $data['name'],
            // ?? first: an absent nullable field is missing from validated
            // data entirely, and $data['emoji'] alone is a 500
            'emoji' => ($data['emoji'] ?? null) ?: '📦',
            'parent_id' => $data['parent_id'] ?? null,
        ]);

        Activity::log('category.create', "ক্যাটাগরি '{$category->name}' তৈরি হয়েছে।", $category);

        return back()->with('success', 'ক্যাটাগরি তৈরি হয়েছে।');
    }

    public function update(Request $request, ProductCategory $category)
    {
        $this->authorizeShop($category);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'emoji' => ['nullable', 'string', 'max:8'],
            'parent_id' => ['nullable', 'integer'],
            'size_set_id' => ['nullable', 'integer'],
        ]);

        // tenant-scoped find: another shop's set id simply doesn't exist here
        if (! empty($data['size_set_id']) && ! \App\Models\SizeSet::find($data['size_set_id'])) {
            return back()->withErrors(['size_set_id' => 'সাইজ সেটটা পাওয়া যায়নি।']);
        }

        $newParentId = $data['parent_id'] ?? null;

        if ($newParentId === $category->id) {
            return back()->withErrors(['parent_id' => 'একটা ক্যাটাগরি নিজের ভেতরে ঢুকতে পারে না।']);
        }
        if ($refusal = $this->refuseBadParent($newParentId)) {
            return $refusal;
        }
        // moving a PARENT under something would bury its children three
        // levels deep — two levels is the whole design
        if ($newParentId !== null && $category->children()->exists()) {
            return back()->withErrors(['parent_id' => 'এই ক্যাটাগরির নিজেরই সাব-ক্যাটাগরি আছে — আগে সেগুলো সরান, তারপর এটাকে কোনো ক্যাটাগরির ভেতরে নিন।']);
        }

        $category->update([
            'name' => $data['name'],
            'name_en' => $data['name'],
            'emoji' => ($data['emoji'] ?? null) ?: $category->emoji,
            'parent_id' => $newParentId,
            'size_set_id' => $data['size_set_id'] ?? null,
        ]);

        Activity::log('category.update', "ক্যাটাগরি '{$category->name}' বদলানো হয়েছে।", $category);

        return back()->with('success', 'সংরক্ষণ করা হয়েছে।');
    }

    /**
     * Deleting never orphans: a category holding products needs an
     * explicit destination (another category, or "no category"), and a
     * parent with children refuses until the children are dealt with —
     * both stated to the owner, never silently resolved.
     */
    public function destroy(Request $request, ProductCategory $category)
    {
        $this->authorizeShop($category);

        $data = $request->validate([
            // null/absent = detach products to "no category" — allowed, but
            // only as the owner's explicit choice (the UI asks first)
            'move_products_to' => ['nullable', 'integer'],
        ]);

        if ($category->children()->exists()) {
            return back()->withErrors(['category' => "'{$category->name}'-এর ভেতরে সাব-ক্যাটাগরি আছে — আগে সেগুলো মুছুন বা সরান।"]);
        }

        $destination = null;
        if (! empty($data['move_products_to'])) {
            $destination = ProductCategory::find($data['move_products_to']); // tenant-scoped
            if (! $destination || $destination->id === $category->id) {
                return back()->withErrors(['move_products_to' => 'যে ক্যাটাগরিতে সরাতে বলা হয়েছে সেটা পাওয়া যায়নি।']);
            }
        }

        DB::transaction(function () use ($category, $destination) {
            Product::where('category_id', $category->id)
                ->update(['category_id' => $destination?->id]);
            $category->delete();
        });

        Activity::log('category.delete', "ক্যাটাগরি '{$category->name}' মুছে ফেলা হয়েছে".($destination ? " — পণ্যগুলো '{$destination->name}'-এ সরানো হয়েছে" : '').'।', $category);

        return back()->with('success', 'ক্যাটাগরি মুছে ফেলা হয়েছে।');
    }

    // ---- size sets (Phase 3) — named, reusable size lists; a category
    // points at one so the variant grid prefills with the right sizes ----

    public function sizeSetStore(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'sizes' => ['required', 'string', 'max:1000'], // comma list, e.g. "20.5, 21, 21.5"
        ]);

        \App\Models\SizeSet::create([
            'name' => $data['name'],
            'sizes' => $this->parseSizes($data['sizes']),
        ]);

        return back()->with('success', 'সাইজ সেট তৈরি হয়েছে।');
    }

    public function sizeSetUpdate(Request $request, \App\Models\SizeSet $sizeSet)
    {
        abort_unless($sizeSet->shop_id === \App\Support\Tenancy::id(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'sizes' => ['required', 'string', 'max:1000'],
        ]);

        $sizeSet->update(['name' => $data['name'], 'sizes' => $this->parseSizes($data['sizes'])]);

        return back()->with('success', 'সংরক্ষণ করা হয়েছে।');
    }

    public function sizeSetDestroy(\App\Models\SizeSet $sizeSet)
    {
        abort_unless($sizeSet->shop_id === \App\Support\Tenancy::id(), 404);
        // categories pointing at it fall back to "no default" via the FK's
        // nullOnDelete — nothing orphans, prefill just stops suggesting
        $sizeSet->delete();

        return back()->with('success', 'সাইজ সেট মুছে ফেলা হয়েছে।');
    }

    /** "20.5, 21 , 21.5" → ["20.5","21","21.5"] — order kept exactly as typed; the owner's order IS the display order. */
    private function parseSizes(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($s) => $s !== ''));
    }

    /** A parent must exist in THIS shop and itself be top-level (max two levels). Returns a redirect on refusal, null when fine. */
    private function refuseBadParent(?int $parentId)
    {
        if ($parentId === null) {
            return null;
        }

        $parent = ProductCategory::find($parentId); // tenant-scoped — another shop's id simply isn't found
        if (! $parent) {
            return back()->withErrors(['parent_id' => 'মূল ক্যাটাগরিটা পাওয়া যায়নি।']);
        }
        if ($parent->parent_id !== null) {
            return back()->withErrors(['parent_id' => "'{$parent->name}' নিজেই একটা সাব-ক্যাটাগরি — সাব-ক্যাটাগরির নিচে আর স্তর হয় না।"]);
        }

        return null;
    }

    private function authorizeShop(ProductCategory $category): void
    {
        // route-model binding is tenant-scoped already; belt-and-braces
        abort_unless($category->shop_id === \App\Support\Tenancy::id(), 404);
    }
}

<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Sheet from '@/Components/Sheet.vue';
import { useI18n } from '@/composables/useI18n';

const props = defineProps({ ingredients: Array, products: Array, recipes: Object });
const { t } = useI18n();

const tab = ref('ingredients');

// --- ingredients CRUD ---
const sheet = ref(false);
const editing = ref(null);
const form = useForm({ name: '', unit: 'kg', stock: '', cost: '', reorder_point: '' });
function openNew() {
    editing.value = null;
    form.reset();
    form.unit = 'kg';
    sheet.value = true;
}
function openEdit(i) {
    editing.value = i;
    form.name = i.name;
    form.unit = i.unit;
    form.reorder_point = i.reorder_point || '';
    sheet.value = true;
}
function save() {
    if (editing.value) {
        form.put(route('app.ingredients.update', editing.value.id), { onSuccess: () => { sheet.value = false; } });
    } else {
        form.post(route('app.ingredients.store'), { onSuccess: () => { sheet.value = false; form.reset(); } });
    }
}
function destroy(i) {
    if (!confirm(t('ingredient.deleteConfirm'))) return;
    router.delete(route('app.ingredients.destroy', i.id));
}

// --- per-product recipe editor ---
const recipeProduct = ref(null);
const recipeSheet = ref(false);
const recipeLines = ref([]);
function openRecipe(p) {
    recipeProduct.value = p;
    const existing = props.recipes[p.id] || [];
    recipeLines.value = existing.map((r) => ({ ingredient_id: r.ingredient_id, qty_per_unit: r.qty_per_unit }));
    if (!recipeLines.value.length) recipeLines.value.push({ ingredient_id: '', qty_per_unit: '' });
    recipeSheet.value = true;
}
function addRecipeLine() {
    recipeLines.value.push({ ingredient_id: '', qty_per_unit: '' });
}
function removeRecipeLine(idx) {
    recipeLines.value.splice(idx, 1);
}
function saveRecipe() {
    const lines = recipeLines.value.filter((l) => l.ingredient_id && l.qty_per_unit);
    router.put(route('app.products.recipe.save', recipeProduct.value.id), { lines }, {
        onSuccess: () => { recipeSheet.value = false; },
    });
}
const ingredientUnit = (id) => props.ingredients.find((i) => i.id === id)?.unit || '';
</script>

<template>
    <Head :title="t('ingredient.title')" />
    <AppLayout active="ingredients">
        <div class="pgttl">{{ t('ingredient.title') }}</div>
        <div class="pgsub">{{ t('ingredient.subtitle') }}</div>

        <div class="seg" style="margin-bottom:14px">
            <button :class="{ on: tab === 'ingredients' }" @click="tab = 'ingredients'">{{ t('ingredient.tabIngredients') }}</button>
            <button :class="{ on: tab === 'recipes' }" @click="tab = 'recipes'">{{ t('ingredient.tabRecipes') }}</button>
        </div>

        <template v-if="tab === 'ingredients'">
            <button class="btn" style="margin-bottom:16px" @click="openNew">{{ t('ingredient.addIngredient') }}</button>
            <div v-for="i in ingredients" :key="i.id" class="card" style="margin-bottom:10px">
                <div style="display:flex;justify-content:space-between;align-items:start">
                    <div>
                        <b style="font-size:15px">{{ i.name }}</b>
                        <div style="font-size:12.5px;color:var(--mut);margin-top:3px">{{ i.stock }} {{ i.unit }} {{ t('ingredient.inStock') }}</div>
                        <div v-if="i.reorder_point" style="font-size:11.5px;color:var(--dim)">{{ t('ingredient.reorderAt') }} {{ i.reorder_point }} {{ i.unit }}</div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:6px">
                        <button class="btn sm ghost" style="width:auto;padding:6px 12px;font-size:12px" @click="openEdit(i)">{{ t('common.edit') }}</button>
                        <button class="btn sm ghost" style="width:auto;padding:6px 12px;font-size:12px;color:var(--rose);border-color:var(--rose)" @click="destroy(i)">{{ t('common.delete') }}</button>
                    </div>
                </div>
            </div>
            <div v-if="!ingredients.length" class="empty"><div class="big">🧂</div>{{ t('ingredient.noIngredients') }}</div>
        </template>

        <template v-else>
            <div style="font-size:12.5px;color:var(--mut);margin-bottom:12px">{{ t('ingredient.recipeHint') }}</div>
            <div v-for="p in products" :key="p.id" class="row" @click="openRecipe(p)">
                <div class="ava">{{ p.emoji || '🍽️' }}</div>
                <div class="mid">
                    <b>{{ p.name }}</b>
                    <span>{{ (recipes[p.id] || []).length }} {{ t('ingredient.ingredientsCount') }}</span>
                </div>
                <div class="end">›</div>
            </div>
            <div v-if="!products.length" class="empty"><div class="big">🍽️</div>{{ t('ingredient.noProducts') }}</div>
        </template>

        <Sheet v-model="sheet" :title="editing ? t('common.edit') : t('ingredient.addIngredient')">
            <div class="field"><label>{{ t('ingredient.name') }}</label><input v-model="form.name"></div>
            <div class="field">
                <label>{{ t('ingredient.unit') }}</label>
                <div class="seg">
                    <button :class="{ on: form.unit === 'kg' }" @click="form.unit = 'kg'">kg</button>
                    <button :class="{ on: form.unit === 'litre' }" @click="form.unit = 'litre'">litre</button>
                    <button :class="{ on: form.unit === 'pcs' }" @click="form.unit = 'pcs'">pcs</button>
                    <button :class="{ on: form.unit === 'gram' }" @click="form.unit = 'gram'">gram</button>
                </div>
            </div>
            <div v-if="!editing" class="field">
                <label>{{ t('ingredient.openingStock') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="form.stock" type="number" min="0">
            </div>
            <div v-if="!editing" class="field">
                <label>{{ t('ingredient.cost') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="form.cost" type="number" min="0">
            </div>
            <div class="field">
                <label>{{ t('ingredient.reorderPoint') }} <span style="color:var(--dim);font-weight:400">{{ t('stock.optional') }}</span></label>
                <input v-model="form.reorder_point" type="number" min="0">
            </div>
            <div v-if="form.errors.name" style="color:var(--rose);font-size:12px;margin-bottom:10px">{{ form.errors.name }}</div>
            <button class="btn" :disabled="form.processing" @click="save">{{ form.processing ? '...' : t('stock.save') }}</button>
            <button class="btn ghost" style="margin-top:10px" @click="sheet = false">{{ t('common.cancel') }}</button>
        </Sheet>

        <Sheet v-model="recipeSheet" :title="recipeProduct ? recipeProduct.name : ''">
            <div v-for="(line, idx) in recipeLines" :key="idx" style="display:flex;gap:6px;margin-bottom:10px;align-items:center">
                <select v-model="line.ingredient_id" style="flex:1;margin:0">
                    <option value="">{{ t('damage.selectPlaceholder') }}</option>
                    <option v-for="ing in ingredients" :key="ing.id" :value="ing.id">{{ ing.name }}</option>
                </select>
                <input v-model="line.qty_per_unit" type="number" min="0" step="0.001" :placeholder="ingredientUnit(line.ingredient_id) || t('ingredient.qty')" style="width:100px;margin:0">
                <button class="btn sm ghost" style="width:auto;padding:8px 10px" @click="removeRecipeLine(idx)">✕</button>
            </div>
            <button class="btn sm ghost" style="margin-bottom:14px" @click="addRecipeLine">+ {{ t('ingredient.addLine') }}</button>
            <button class="btn" @click="saveRecipe">{{ t('stock.save') }}</button>
        </Sheet>
    </AppLayout>
</template>

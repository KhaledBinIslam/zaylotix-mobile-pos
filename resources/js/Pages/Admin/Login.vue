<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import ZaylotixLogo from '@/Components/ZaylotixLogo.vue';

const form = useForm({ email: 'admin@zaylotix.com', password: 'password', remember: true });

function submit() {
    form.post(route('admin.login.store'), { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Admin Login" />
    <div class="min-h-screen flex items-center justify-center bg-gray-50 px-4">
        <div class="w-full max-w-sm bg-white border rounded-2xl shadow-xl p-8">
            <div class="text-center mb-6">
                <ZaylotixLogo :size="56" />
                <div class="text-sm text-gray-500 mt-2">POS — Super Admin Panel</div>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="text-sm font-medium text-gray-600">Email</label>
                    <input v-model="form.email" type="email" class="mt-1 w-full rounded-lg border-gray-300">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-600">Password</label>
                    <input v-model="form.password" type="password" class="mt-1 w-full rounded-lg border-gray-300">
                </div>
                <div v-if="form.errors.email" class="text-rose-600 text-sm">{{ form.errors.email }}</div>
                <button type="submit" class="w-full py-3 rounded-lg text-white font-bold" style="background:#7C3AED" :disabled="form.processing">
                    {{ form.processing ? '...' : 'Log in' }}
                </button>
            </form>

            <div class="text-center text-xs text-gray-400 mt-6">
                A <a href="https://zaylotix.com/" target="_blank" class="font-semibold" style="color:#7C3AED">Zaylotix</a> product ·
                Owner: <a href="https://khaledbinislam.com/" target="_blank" class="font-semibold text-gray-600">Khaled Bin Islam</a>
            </div>
        </div>
    </div>
</template>

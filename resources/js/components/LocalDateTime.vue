<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { formatDateTimeLocal, formatDateTimeUtc } from '@/lib/utils';

const props = defineProps<{
    value: string | null | undefined;
}>();

// Rendered identically by SSR and the client's first paint (both use the
// same fixed UTC formatting), so hydration never mismatches — the server
// cannot know the browser's timezone, so it can't render local time safely.
// Once mounted (client-only, after hydration has already completed), this
// swaps to the browser's own local timezone, which is the actual product
// intent for what an operator should see.
const display = ref(props.value ? formatDateTimeUtc(props.value) : '—');

onMounted(() => {
    if (props.value) {
        display.value = formatDateTimeLocal(props.value);
    }
});
</script>

<template>{{ display }}</template>

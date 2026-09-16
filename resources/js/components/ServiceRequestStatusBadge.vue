<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { ServiceRequestStatus } from '@/types';

const props = defineProps<{
    status: ServiceRequestStatus;
}>();

const STATUS_CONFIG: Record<
    ServiceRequestStatus,
    {
        label: string;
        variant: 'default' | 'secondary' | 'destructive' | 'outline';
        class?: string;
    }
> = {
    queued: { label: 'Queued', variant: 'outline' },
    processing: { label: 'Processing', variant: 'secondary' },
    retrying: {
        label: 'Retrying',
        variant: 'secondary',
        class: 'text-amber-700 dark:text-amber-400',
    },
    uncertain: {
        label: 'Uncertain',
        variant: 'secondary',
        class: 'text-amber-700 dark:text-amber-400',
    },
    completed: {
        label: 'Completed',
        variant: 'default',
        class: 'bg-green-600 text-white hover:bg-green-600/90 dark:bg-green-700',
    },
    failed: { label: 'Failed', variant: 'destructive' },
};

const config = computed(() => STATUS_CONFIG[props.status]);
</script>

<template>
    <Badge :variant="config.variant" :class="config.class">{{
        config.label
    }}</Badge>
</template>

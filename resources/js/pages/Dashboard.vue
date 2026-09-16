<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ClipboardList } from '@lucide/vue';
import ServiceRequestController from '@/actions/App/Http/Controllers/ServiceRequestController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import type { DashboardSummary } from '@/types';

defineProps<{
    summary: DashboardSummary;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <Heading
                title="Dashboard"
                description="Service requests for your organisation."
            />

            <Button as-child>
                <Link :href="ServiceRequestController.index.url()">
                    <ClipboardList class="size-4" />
                    View Service Requests
                </Link>
            </Button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card>
                <CardHeader>
                    <CardTitle class="text-muted-foreground text-sm font-medium"
                        >Total requests</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <p class="text-2xl font-semibold">{{ summary.total }}</p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-muted-foreground text-sm font-medium"
                        >Completed</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <p class="text-2xl font-semibold">
                        {{ summary.completed }}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-muted-foreground text-sm font-medium"
                        >In progress</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <p class="text-2xl font-semibold">
                        {{ summary.inProgress }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        Queued, processing, or retrying
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-muted-foreground text-sm font-medium"
                        >Needs attention</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <p class="text-2xl font-semibold">
                        {{ summary.needsAttention }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        Failed or uncertain
                    </p>
                </CardContent>
            </Card>
        </div>
    </div>
</template>

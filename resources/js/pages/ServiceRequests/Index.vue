<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ArrowUpDown, RefreshCw } from '@lucide/vue';
import { onUnmounted, ref } from 'vue';
import ServiceRequestController from '@/actions/App/Http/Controllers/ServiceRequestController';
import Heading from '@/components/Heading.vue';
import LocalDateTime from '@/components/LocalDateTime.vue';
import ServiceRequestStatusBadge from '@/components/ServiceRequestStatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    Paginated,
    ServiceRequestListItem,
    ServiceRequestSortField,
    ServiceRequestStatus,
    SortDirection,
} from '@/types';

const props = defineProps<{
    serviceRequests: Paginated<ServiceRequestListItem>;
    filters: {
        status: ServiceRequestStatus | null;
        sort: ServiceRequestSortField;
        direction: SortDirection;
        customer: number | null;
    };
    statusOptions: ServiceRequestStatus[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Service Requests',
                href: ServiceRequestController.index(),
            },
        ],
    },
});

const STATUS_LABELS: Record<ServiceRequestStatus, string> = {
    queued: 'Queued',
    processing: 'Processing',
    retrying: 'Retrying',
    uncertain: 'Uncertain',
    completed: 'Completed',
    failed: 'Failed',
};

const ALL_STATUSES = 'all';

const isLoading = ref(false);
const errorMessage = ref<string | null>(null);

const offStart = router.on('start', () => {
    isLoading.value = true;
    errorMessage.value = null;
});
const offFinish = router.on('finish', () => {
    isLoading.value = false;
});
// Inertia's 'error' event fires only for form-validation errors (a non-empty
// page.props.errors) — this page never submits a form, so it would never
// fire here. Real request failures (a 5xx/non-Inertia response, or the
// request never reaching the server at all) fire 'httpException' and
// 'networkError' respectively; those are the events that actually
// correspond to "the refresh failed".
const offHttpException = router.on('httpException', () => {
    errorMessage.value =
        'Unable to refresh service requests. Please try again.';
});
const offNetworkError = router.on('networkError', () => {
    errorMessage.value =
        'Unable to refresh service requests. Please try again.';
});

onUnmounted(() => {
    offStart();
    offFinish();
    offHttpException();
    offNetworkError();
});

function onStatusChange(value: unknown) {
    const status =
        value === ALL_STATUSES ? undefined : (value as ServiceRequestStatus);

    router.get(
        ServiceRequestController.index.url(),
        {
            status,
            sort: props.filters.sort,
            direction: props.filters.direction,
            customer: props.filters.customer ?? undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function applySort(field: ServiceRequestSortField) {
    const direction: SortDirection =
        props.filters.sort === field && props.filters.direction === 'asc'
            ? 'desc'
            : 'asc';

    router.get(
        ServiceRequestController.index.url(),
        {
            status: props.filters.status ?? undefined,
            sort: field,
            direction,
            customer: props.filters.customer ?? undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function clearCustomerFilter() {
    router.get(
        ServiceRequestController.index.url(),
        {
            status: props.filters.status ?? undefined,
            sort: props.filters.sort,
            direction: props.filters.direction,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function sortIcon(field: ServiceRequestSortField) {
    if (props.filters.sort !== field) {
        return ArrowUpDown;
    }

    return props.filters.direction === 'asc' ? ArrowUp : ArrowDown;
}

function refresh() {
    router.reload({ only: ['serviceRequests'] });
}

function formatAmount(item: ServiceRequestListItem): string {
    return `${item.amount} ${item.currency}`;
}
</script>

<template>
    <Head title="Service Requests" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <Heading
                title="Service Requests"
                description="All service-activation requests for your organisation."
            />

            <div class="flex items-center gap-3">
                <Select
                    :model-value="filters.status ?? ALL_STATUSES"
                    @update:model-value="onStatusChange"
                >
                    <SelectTrigger class="w-40">
                        <SelectValue placeholder="All statuses" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL_STATUSES"
                            >All statuses</SelectItem
                        >
                        <SelectItem
                            v-for="option in statusOptions"
                            :key="option"
                            :value="option"
                        >
                            {{ STATUS_LABELS[option] }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Button
                    variant="outline"
                    :disabled="isLoading"
                    @click="refresh"
                >
                    <RefreshCw
                        :class="['size-4', isLoading && 'animate-spin']"
                    />
                    Refresh
                </Button>
            </div>
        </div>

        <p v-if="filters.customer" class="text-muted-foreground text-sm">
            Filtered to customer ({{ filters.customer }}).
            <button
                type="button"
                class="text-primary underline-offset-4 hover:underline"
                @click="clearCustomerFilter"
            >
                Clear
            </button>
        </p>

        <p v-if="isLoading" class="text-muted-foreground text-sm" role="status">
            Loading service requests…
        </p>

        <p v-if="errorMessage" class="text-destructive text-sm" role="alert">
            {{ errorMessage }}
        </p>

        <div
            class="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border"
        >
            <div
                v-if="serviceRequests.data.length === 0"
                class="text-muted-foreground p-8 text-center text-sm"
            >
                No service requests found.
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr
                            class="border-sidebar-border/70 dark:border-sidebar-border border-b text-left"
                        >
                            <th class="p-3 font-medium whitespace-nowrap">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:underline"
                                    @click="applySort('reference')"
                                >
                                    Reference
                                    <component
                                        :is="sortIcon('reference')"
                                        class="size-3.5"
                                    />
                                </button>
                            </th>
                            <th class="p-3 font-medium">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:underline"
                                    @click="applySort('customer')"
                                >
                                    Customer
                                    <component
                                        :is="sortIcon('customer')"
                                        class="size-3.5"
                                    />
                                </button>
                            </th>
                            <th class="p-3 font-medium whitespace-nowrap">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:underline"
                                    @click="applySort('service_id')"
                                >
                                    Service ID
                                    <component
                                        :is="sortIcon('service_id')"
                                        class="size-3.5"
                                    />
                                </button>
                            </th>
                            <th class="p-3 font-medium whitespace-nowrap">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:underline"
                                    @click="applySort('amount')"
                                >
                                    Amount
                                    <component
                                        :is="sortIcon('amount')"
                                        class="size-3.5"
                                    />
                                </button>
                            </th>
                            <th class="p-3 font-medium">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:underline"
                                    @click="applySort('status')"
                                >
                                    Status
                                    <component
                                        :is="sortIcon('status')"
                                        class="size-3.5"
                                    />
                                </button>
                            </th>
                            <th class="p-3 font-medium whitespace-nowrap">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:underline"
                                    @click="applySort('created')"
                                >
                                    Created
                                    <component
                                        :is="sortIcon('created')"
                                        class="size-3.5"
                                    />
                                </button>
                            </th>
                            <th class="p-3 font-medium">
                                <span class="sr-only">View</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in serviceRequests.data"
                            :key="item.id"
                            class="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-0"
                        >
                            <td class="p-3 font-mono text-xs whitespace-nowrap">
                                {{ item.reference }}
                            </td>
                            <td class="p-3">
                                {{ item.customer.name }}
                                <span class="text-muted-foreground"
                                    >({{ item.customer.customer_id }})</span
                                >
                            </td>
                            <td class="p-3 whitespace-nowrap">
                                {{ item.service_id }}
                            </td>
                            <td class="p-3 whitespace-nowrap">
                                {{ formatAmount(item) }}
                            </td>
                            <td class="p-3">
                                <ServiceRequestStatusBadge
                                    :status="item.status"
                                />
                            </td>
                            <td
                                class="text-muted-foreground p-3 whitespace-nowrap"
                            >
                                <LocalDateTime :value="item.created_at" />
                            </td>
                            <td class="p-3 text-right">
                                <Link
                                    :href="
                                        ServiceRequestController.show.url(
                                            item.id,
                                        )
                                    "
                                    class="text-primary text-sm underline-offset-4 hover:underline"
                                    preserve-scroll
                                >
                                    View
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <nav
            v-if="
                serviceRequests.data.length > 0 &&
                serviceRequests.links.length > 3
            "
            class="flex flex-wrap items-center gap-1"
            aria-label="Pagination"
        >
            <template
                v-for="(link, index) in serviceRequests.links"
                :key="index"
            >
                <span
                    v-if="!link.url"
                    class="text-muted-foreground rounded-md px-3 py-1.5 text-sm"
                    v-html="link.label"
                />
                <Link
                    v-else
                    :href="link.url"
                    preserve-state
                    preserve-scroll
                    :class="[
                        'rounded-md px-3 py-1.5 text-sm',
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-accent',
                    ]"
                    v-html="link.label"
                />
            </template>
        </nav>
    </div>
</template>

<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Clock,
    HelpCircle,
    Loader,
    RotateCcw,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ServiceRequestController from '@/actions/App/Http/Controllers/ServiceRequestController';
import Heading from '@/components/Heading.vue';
import LocalDateTime from '@/components/LocalDateTime.vue';
import ServiceRequestStatusBadge from '@/components/ServiceRequestStatusBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type {
    ServiceRequestDetail,
    ServiceRequestHistoryEvent,
    ServiceRequestStatus,
} from '@/types';

const props = defineProps<{
    serviceRequest: ServiceRequestDetail;
    history: ServiceRequestHistoryEvent[];
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

// Statuses where an operator benefits from a clear "what happened" summary,
// drawn from the most recent history event rather than a separate field.
const NEEDS_ATTENTION_STATUSES: ServiceRequestStatus[] = [
    'failed',
    'retrying',
    'uncertain',
];

// A small icon per event's resulting status, purely for scan-ability — the
// text (message, statuses, attempt/delay) already carries the full meaning.
const EVENT_ICONS: Record<ServiceRequestStatus, typeof Clock> = {
    queued: Clock,
    processing: Loader,
    retrying: RotateCcw,
    uncertain: HelpCircle,
    completed: CheckCircle2,
    failed: XCircle,
};

const latestEvent = computed<ServiceRequestHistoryEvent | null>(
    () => props.history.at(-1) ?? null,
);
const needsAttention = computed(() =>
    NEEDS_ATTENTION_STATUSES.includes(props.serviceRequest.status),
);

const isConfirmOpen = ref(false);
const isRetrying = ref(false);

function submitRetry() {
    isRetrying.value = true;

    router.post(
        ServiceRequestController.retry.url(props.serviceRequest.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                isRetrying.value = false;
                isConfirmOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Service Request ${serviceRequest.reference}`" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <Link
                :href="ServiceRequestController.index.url()"
                class="text-muted-foreground mb-4 inline-flex items-center gap-1 text-sm hover:underline"
            >
                <ArrowLeft class="size-4" />
                Back to Service Requests
            </Link>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <Heading
                    :title="`Service Request ${serviceRequest.reference}`"
                    description="Details and processing history for this service request."
                />
                <div class="flex items-center gap-3">
                    <ServiceRequestStatusBadge
                        :status="serviceRequest.status"
                    />
                    <Button
                        v-if="serviceRequest.can_retry"
                        variant="outline"
                        @click="isConfirmOpen = true"
                    >
                        Retry
                    </Button>
                </div>
            </div>
        </div>

        <Alert
            v-if="needsAttention && latestEvent"
            :variant="
                serviceRequest.status === 'failed' ? 'destructive' : 'default'
            "
        >
            <AlertTriangle />
            <AlertTitle>{{ STATUS_LABELS[serviceRequest.status] }}</AlertTitle>
            <AlertDescription>
                {{ latestEvent.message }}
                <span v-if="serviceRequest.provider_attempts">
                    (attempt {{ serviceRequest.provider_attempts }} of 3)</span
                >
            </AlertDescription>
        </Alert>

        <Card>
            <CardHeader>
                <CardTitle>Details</CardTitle>
            </CardHeader>
            <CardContent>
                <dl
                    class="grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3"
                >
                    <div>
                        <dt class="text-muted-foreground">Reference</dt>
                        <dd class="font-mono">
                            {{ serviceRequest.reference }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Customer</dt>
                        <dd>
                            <Link
                                :href="
                                    ServiceRequestController.index.url({
                                        query: {
                                            customer:
                                                serviceRequest.customer
                                                    .customer_id,
                                        },
                                    })
                                "
                                class="text-primary underline-offset-4 hover:underline"
                            >
                                {{ serviceRequest.customer.name }} ({{
                                    serviceRequest.customer.customer_id
                                }})
                            </Link>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Service ID</dt>
                        <dd>{{ serviceRequest.service_id }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Action</dt>
                        <dd class="capitalize">{{ serviceRequest.action }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Requested by</dt>
                        <dd class="capitalize">
                            {{ serviceRequest.requested_by }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Amount</dt>
                        <dd>
                            {{ serviceRequest.amount }}
                            {{ serviceRequest.currency }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Status</dt>
                        <dd>{{ STATUS_LABELS[serviceRequest.status] }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Provider attempts</dt>
                        <dd>{{ serviceRequest.provider_attempts }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Created</dt>
                        <dd>
                            <LocalDateTime :value="serviceRequest.created_at" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Completed</dt>
                        <dd>
                            <LocalDateTime
                                :value="serviceRequest.completed_at"
                            />
                        </dd>
                    </div>
                </dl>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>History</CardTitle>
            </CardHeader>
            <CardContent>
                <p
                    v-if="history.length === 0"
                    class="text-muted-foreground text-sm"
                >
                    No history events yet.
                </p>

                <ol v-else class="space-y-4">
                    <li
                        v-for="(event, index) in history"
                        :key="index"
                        class="border-sidebar-border/70 dark:border-sidebar-border border-l-2 pl-4"
                    >
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2"
                        >
                            <p class="flex items-center gap-1.5 font-medium">
                                <component
                                    :is="EVENT_ICONS[event.to_status]"
                                    class="text-muted-foreground size-4 shrink-0"
                                />
                                {{ event.message }}
                            </p>
                            <span class="text-muted-foreground text-xs">
                                <LocalDateTime :value="event.created_at" />
                            </span>
                        </div>
                        <p class="text-muted-foreground text-xs">
                            {{ event.actor_type }}
                            <template
                                v-if="
                                    event.from_status &&
                                    event.from_status !== event.to_status
                                "
                            >
                                · {{ STATUS_LABELS[event.from_status] }} →
                                {{ STATUS_LABELS[event.to_status] }}
                            </template>
                            <template v-else>
                                · {{ STATUS_LABELS[event.to_status] }}
                            </template>
                            <template v-if="event.provider_attempt">
                                · attempt {{ event.provider_attempt }}</template
                            >
                            <template v-if="event.retry_delay_seconds">
                                · retry in
                                {{ event.retry_delay_seconds }}s</template
                            >
                        </p>
                    </li>
                </ol>
            </CardContent>
        </Card>
    </div>

    <Dialog v-model:open="isConfirmOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Retry this service request?</DialogTitle>
                <DialogDescription>
                    <template v-if="serviceRequest.status === 'failed'">
                        This will queue activation to be attempted again from
                        the start.
                    </template>
                    <template v-else>
                        This will re-check with the provider to confirm whether
                        activation already succeeded. It will not attempt to
                        activate the service again.
                    </template>
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="isRetrying"
                    @click="isConfirmOpen = false"
                    >Cancel</Button
                >
                <Button :disabled="isRetrying" @click="submitRetry">
                    {{ isRetrying ? 'Retrying…' : 'Confirm retry' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

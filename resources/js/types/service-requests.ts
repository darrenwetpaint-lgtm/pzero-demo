export type ServiceRequestStatus =
    | 'queued'
    | 'processing'
    | 'retrying'
    | 'uncertain'
    | 'completed'
    | 'failed';

export type ServiceRequestCustomer = {
    customer_id: number;
    name: string;
};

export type ServiceRequestListItem = {
    id: number;
    reference: string;
    customer: ServiceRequestCustomer;
    service_id: number;
    amount: string;
    currency: string;
    status: ServiceRequestStatus;
    created_at: string;
};

export type ServiceRequestDetail = {
    id: number;
    reference: string;
    status: ServiceRequestStatus;
    customer: ServiceRequestCustomer;
    service_id: number;
    action: string;
    requested_by: string;
    amount: string;
    currency: string;
    provider_attempts: number;
    created_at: string;
    completed_at: string | null;
    can_retry: boolean;
};

export type ServiceRequestHistoryEvent = {
    from_status: ServiceRequestStatus | null;
    to_status: ServiceRequestStatus;
    actor_type: string;
    message: string;
    provider_attempt: number | null;
    provider_outcome: string | null;
    lookup_outcome: string | null;
    retry_delay_seconds: number | null;
    created_at: string;
};

export type ServiceRequestSortField =
    | 'reference'
    | 'customer'
    | 'service_id'
    | 'amount'
    | 'status'
    | 'created';

export type SortDirection = 'asc' | 'desc';

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

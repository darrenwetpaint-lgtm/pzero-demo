<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Organisation;
use App\Models\PortalCredential;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * All identities below are fictional TEST-only demo data for the
     * PZero assessment — see the final setup report for the raw
     * operator passwords and portal tokens (only their hashes are stored).
     */
    public function run(): void
    {
        $this->seedOrganisation(
            clientId: 18,
            organisationName: 'Northstar Telecom',
            operatorName: 'Alice Operator',
            operatorEmail: 'alice@northstar.test',
            customers: [
                ['customer_number' => 10482, 'name' => 'Amahle Dlamini', 'email' => 'amahle@northstar-customer.test'],
                ['customer_number' => 10483, 'name' => 'General Trading CC', 'email' => 'accounts@northstar-customer.test'],
            ],
            portalCredentialName: 'Northstar Portal Token',
            rawPortalToken: 'northstar-portal-test-token-18',
        );

        $this->seedOrganisation(
            clientId: 27,
            organisationName: 'Bluewave Services',
            operatorName: 'Bianca Operator',
            operatorEmail: 'bianca@bluewave.test',
            customers: [
                ['customer_number' => 20482, 'name' => 'Sipho Nkosi', 'email' => 'sipho@bluewave-customer.test'],
                ['customer_number' => 20483, 'name' => 'Coastal Retail Ltd', 'email' => 'accounts@bluewave-customer.test'],
            ],
            portalCredentialName: 'Bluewave Portal Token',
            rawPortalToken: 'bluewave-portal-test-token-27',
        );

        $this->call(ServiceRequestDemoSeeder::class);
    }

    /**
     * @param  array<int, array{customer_number: int, name: string, email: string}>  $customers
     */
    private function seedOrganisation(
        int $clientId,
        string $organisationName,
        string $operatorName,
        string $operatorEmail,
        array $customers,
        string $portalCredentialName,
        string $rawPortalToken,
    ): void {
        $organisation = Organisation::create([
            'client_id' => $clientId,
            'name' => $organisationName,
        ]);

        foreach ($customers as $customer) {
            Customer::create([
                'organisation_id' => $organisation->id,
                'customer_number' => $customer['customer_number'],
                'name' => $customer['name'],
                'email' => $customer['email'],
            ]);
        }

        User::factory()->create([
            'organisation_id' => $organisation->id,
            'name' => $operatorName,
            'email' => $operatorEmail,
        ]);

        PortalCredential::create([
            'organisation_id' => $organisation->id,
            'name' => $portalCredentialName,
            'token_hash' => hash('sha256', $rawPortalToken),
        ]);
    }
}

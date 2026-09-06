<?php

namespace Tests\Unit;

use App\Services\WemaAlatService;
use Tests\TestCase;

class WemaAlatServiceTest extends TestCase
{
    private WemaAlatService $wemaService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wemaService = new WemaAlatService();
    }

    public function test_it_generates_wema_virtual_account_in_sandbox_mode(): void
    {
        $params = [
            'amount' => 50000.00,
            'student_name' => 'Chioma Okonkwo',
            'student_id' => 101,
            'school_id' => 1,
            'school_code' => 'SCH001',
            'reference' => 'TEST_WEMA_REF_123',
            'email' => 'parent@example.com',
            'phone' => '08012345678',
        ];

        $result = $this->wemaService->generateVirtualAccount($params);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('account_number', $result);
        $this->assertEquals('Wema Bank', $result['bank_name']);
        $this->assertStringStartsWith('02', $result['account_number']);
        $this->assertEquals('TEST_WEMA_REF_123', $result['reference']);
        $this->assertEquals(50000.00, $result['amount']);
    }

    public function test_it_formats_alat_pay_initialization_payload(): void
    {
        $params = [
            'amount' => 75000.00,
            'email' => 'sponsor@example.com',
            'reference' => 'SP_ALAT_TEST_99',
            'callback_url' => 'https://schoolprofit.ng/callback',
        ];

        $result = $this->wemaService->initializePayment($params);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals('SP_ALAT_TEST_99', $result['reference']);
    }

    public function test_it_dispatches_single_payout_transfer_in_sandbox(): void
    {
        $params = [
            'amount' => 74500.00,
            'destination_bank_code' => '058', // GTBank
            'destination_account_number' => '0123456789',
            'destination_account_name' => 'St. Jude International School',
            'narration' => 'SchoolProfit Tuition Sweep: Chioma Okonkwo',
            'reference' => 'SWEEP_TEST_001',
        ];

        $result = $this->wemaService->singleTransfer($params);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals('successful', $result['status']);
        $this->assertArrayHasKey('session_id', $result);
    }
}

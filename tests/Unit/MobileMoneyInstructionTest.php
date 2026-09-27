<?php

namespace Tests\Unit;

use App\Domain\Payment\Providers\FlutterwavePaymentProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Which of the gateway's words are shown to a customer waiting on a phone
 * prompt. An instruction (a dial code, or a verb asking for an action) is
 * shown as the gateway wrote it; a status phrase is not an instruction and
 * is dropped, so the waiting state writes its own sentence instead. The
 * Francophone rail answers "Transaction in progress", which used to be
 * shown as the whole instruction.
 */
class MobileMoneyInstructionTest extends TestCase
{
    private function instruction(array $data): ?string
    {
        // Skip the constructor (config/secret): only the pure selection
        // logic is exercised.
        $provider = (new ReflectionClass(FlutterwavePaymentProvider::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($provider, 'gatewayInstruction');

        return $method->invoke($provider, $data);
    }

    public function test_it_prefers_the_authorization_note_dial_instruction(): void
    {
        $data = [
            'message' => 'Charge initiated',
            'meta' => ['authorization' => ['note' => 'Please dial *126*14# to approve this payment on your phone.']],
            'data' => ['processor_response' => 'Transaction in progress'],
        ];

        $this->assertSame('Please dial *126*14# to approve this payment on your phone.', $this->instruction($data));
    }

    public function test_it_falls_back_to_a_processor_response_that_asks_for_an_action(): void
    {
        $data = [
            'message' => 'pending',
            'data' => ['processor_response' => 'Approve the Orange Money prompt sent to your handset.'],
        ];

        $this->assertSame('Approve the Orange Money prompt sent to your handset.', $this->instruction($data));
    }

    public function test_a_bare_dial_code_counts_as_an_instruction(): void
    {
        $this->assertSame('*126#', $this->instruction(['data' => ['processor_response' => '*126#']]));
    }

    public function test_status_phrases_are_not_instructions(): void
    {
        $this->assertNull($this->instruction(['message' => 'initiated']));
        $this->assertNull($this->instruction(['data' => ['processor_response' => 'Transaction in progress']]));
        $this->assertNull($this->instruction(['data' => ['processor_response' => 'pending']]));
        $this->assertNull($this->instruction([]));
    }
}

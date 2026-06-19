<?php

namespace Sisly\Coach\Tests\Unit;

use Mockery;
use Sisly\Coach\Enums\SafetyVerdict;
use Sisly\Coach\Services\AnthropicService;
use Sisly\Coach\Services\SafetyService;
use Sisly\Coach\Support\SafetyResult;
use PHPUnit\Framework\TestCase;

class SafetyServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeService(string $rawResponse): SafetyService
    {
        $anthropic = Mockery::mock(AnthropicService::class);
        $anthropic->shouldReceive('safetyCompletion')
            ->once()
            ->andReturn($rawResponse);

        return new SafetyService($anthropic);
    }

    public function test_ok_verdict_parsed_correctly(): void
    {
        $raw     = '{"verdict":"ok","category":"none","rationale":"ordinary venting"}';
        $service = $this->makeService($raw);
        $result  = $service->classify('I had a tough day');

        $this->assertSame(SafetyVerdict::Ok, $result->verdict);
        $this->assertSame('none', $result->category);
    }

    public function test_checking_verdict_parsed(): void
    {
        $raw     = '{"verdict":"checking","category":"acute_distress","rationale":"ambiguous"}';
        $service = $this->makeService($raw);
        $result  = $service->classify("I can't do this anymore");

        $this->assertSame(SafetyVerdict::Checking, $result->verdict);
    }

    public function test_flagged_verdict_parsed(): void
    {
        $raw     = '{"verdict":"flagged","category":"self_harm","rationale":"explicit self harm"}';
        $service = $this->makeService($raw);
        $result  = $service->classify("I want to hurt myself");

        $this->assertSame(SafetyVerdict::Flagged, $result->verdict);
        $this->assertSame('self_harm', $result->category);
    }

    public function test_fails_closed_to_checking_on_malformed_json(): void
    {
        $service = $this->makeService('not valid json at all');
        $result  = $service->classify('some message');

        // Must fail CLOSED — never silently pass as 'ok'
        $this->assertSame(SafetyVerdict::Checking, $result->verdict);
        $this->assertSame('parse_failed', $result->rationale);
    }

    public function test_fails_closed_on_unknown_verdict_value(): void
    {
        $service = $this->makeService('{"verdict":"unknown","category":"none","rationale":"test"}');
        $result  = $service->classify('some message');

        $this->assertSame(SafetyVerdict::Checking, $result->verdict);
    }

    public function test_strips_json_markdown_fences(): void
    {
        $raw     = "```json\n{\"verdict\":\"ok\",\"category\":\"none\",\"rationale\":\"fine\"}\n```";
        $service = $this->makeService($raw);
        $result  = $service->classify('fine message');

        $this->assertSame(SafetyVerdict::Ok, $result->verdict);
    }

    /** Test cases from the execution guide table */
    public function test_idiom_of_exhaustion_does_not_flag(): void
    {
        // "I'm so dead today" should be ok in work context
        $raw     = '{"verdict":"ok","category":"none","rationale":"exhaustion idiom in work context"}';
        $service = $this->makeService($raw);
        $result  = $service->classify("I'm so dead today");

        $this->assertSame(SafetyVerdict::Ok, $result->verdict);
    }

    public function test_explicit_crisis_flags(): void
    {
        $raw     = '{"verdict":"flagged","category":"self_harm","rationale":"direct"}';
        $service = $this->makeService($raw);
        $result  = $service->classify("I don't want to be here anymore");

        $this->assertSame(SafetyVerdict::Flagged, $result->verdict);
    }

    public function test_ambiguous_message_returns_checking(): void
    {
        $raw     = '{"verdict":"checking","category":"acute_distress","rationale":"ambiguous"}';
        $service = $this->makeService($raw);
        $result  = $service->classify("I can't do this anymore");

        $this->assertSame(SafetyVerdict::Checking, $result->verdict);
    }
}

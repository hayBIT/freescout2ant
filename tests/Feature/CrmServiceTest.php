<?php

namespace Modules\AmeiseModule\Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Modules\AmeiseModule\Services\CrmService;

class CrmServiceTest extends TestCase
{
    /** @test */
    public function it_refreshes_token_and_creates_contact(): void
    {
        // 1. Fake Refresh‑Aufruf
        Http::fakeSequence()
            ->push(['access_token' => 'NEU', 'refresh_token' => 'X', 'expires_in' => 3600], 200)
            // 2. Fake eigentliche API‑Route
            ->push(['id' => 1, 'name' => 'Max'], 201);

        $crm = app(CrmService::class);

        $data = $crm->createContact(['name' => 'Max']);

        $this->assertEquals(['id' => 1, 'name' => 'Max'], $data);
        Http::assertSentCount(2); // Refresh + API‑Call
    }
}

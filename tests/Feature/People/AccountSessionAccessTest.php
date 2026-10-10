<?php

use App\Models\PersonProductAccess;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('denies delivered block on web and Livewire including administrators', function (bool $administrator) {
    $this->withoutVite();
    $person = $administrator ? adminUser() : employeeUser();
    $response = $this->actingAs($person)->get(route('dashboard'))->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
    $this->withHeader('X-Livewire', 'true')->postJson(route('default-livewire.update'), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]])->assertOk();
    PersonProductAccess::create(['company_id' => $person->company_id, 'user_id' => $person->id, 'confirmed_enabled' => false, 'version' => 1, 'operation_id' => (string) Str::uuid()]);
    $this->get(route('dashboard'))->assertForbidden();
    Livewire::flushState();
    $this->withHeader('X-Livewire', 'true')->postJson(route('default-livewire.update'), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]])->assertForbidden();
})->with([false, true]);

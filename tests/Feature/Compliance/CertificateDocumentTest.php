<?php

use App\Actions\Certificates\IssueCertificate;
use App\Actions\Certificates\RevokeCertificate;
use App\Enums\ComplianceEventType;
use App\Enums\Permission;
use App\Jobs\GenerateCertificateDocument;
use App\Models\Certificate;
use App\Models\ComplianceEvent;
use App\Models\Department;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Services\Certificates\CertificateRenderer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('never lets a translation file shadow an interface label', function (): void {
    // A lang group named after a visible string makes __() return the whole file as an
    // array, which took down every screen that renders the navigation.
    foreach (['Certificates', 'People', 'Courses', 'Departments', 'Assignments'] as $label) {
        expect(__($label))->toBeString();
    }
});

it('renders a certificate document with a verification QR code', function (): void {
    Storage::fake('local');
    $certificate = Certificate::factory()->create();

    $path = app(CertificateRenderer::class)->render($certificate);

    Storage::disk('local')->assertExists($path);

    expect($certificate->fresh()->file_path)->toBe($path)
        ->and(Storage::disk('local')->get($path))->toStartWith('%PDF');
});

it('queues the document when a certificate is issued', function (): void {
    Queue::fake();

    Certificate::factory()->create();
    app(IssueCertificate::class)->handle(
        UserTrainingAssignment::factory()->create(['user_id' => User::factory()])
    );

    Queue::assertPushed(GenerateCertificateDocument::class);
});

it('lets the holder download their own certificate', function (): void {
    Storage::fake('local');
    $certificate = Certificate::factory()->create();

    $this->actingAs($certificate->user)
        ->get(route('certificates.download', ['certificate' => $certificate]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('refuses the download to an unrelated employee', function (): void {
    Storage::fake('local');
    $certificate = Certificate::factory()->create();

    $this->actingAs(employeeUser())
        ->get(route('certificates.download', ['certificate' => $certificate]))
        ->assertForbidden();
});

it('keeps a revoked certificate verifiable instead of deleting it', function (): void {
    Storage::fake('local');
    $certificate = Certificate::factory()->create();

    app(RevokeCertificate::class)->handle($certificate, 'Issued against the wrong course version');

    $certificate->refresh();

    expect($certificate->isValid())->toBeFalse()
        ->and($certificate->revocation_reason)->toBe('Issued against the wrong course version')
        ->and(ComplianceEvent::query()->where('event_type', ComplianceEventType::CertificateRevoked->value)->count())->toBe(1);

    // The public page still answers, and answers "revoked".
    $this->get(route('certificates.verify', $certificate))
        ->assertOk()
        ->assertSee(__('ui.verify_revoked'));
});

it('only lets the revoke permission revoke', function (): void {
    Storage::fake('local');
    $certificate = Certificate::factory()->create();
    $department = Department::factory()->create();
    $certificate->user->departments()->attach($department);
    $viewer = userWithPermissions([Permission::CertificatesView]);
    $viewer->managedDepartments()->attach($department);
    $operator = userWithPermissions([Permission::CertificatesRevoke]);
    $operator->managedDepartments()->attach($department);

    Livewire\Livewire::actingAs($viewer)
        ->test('compliance.certificates')
        ->call('startRevoking', $certificate->id)
        ->assertForbidden();

    Livewire\Livewire::actingAs($operator)
        ->test('compliance.certificates')
        ->call('startRevoking', $certificate->id)
        ->set('revocationReason', 'Superseded by a corrected issue')
        ->call('revoke')
        ->assertHasNoErrors();

    expect($certificate->fresh()->isRevoked())->toBeTrue();
});

it('lets a manager view recursive reports certificates but not unrelated certificates', function (): void {
    Storage::fake('local');
    $department = Department::factory()->create();
    $nestedDepartment = Department::factory()->create();
    $manager = userWithPermissions([Permission::CertificatesView]);
    $direct = User::factory()->create();
    $nested = User::factory()->create();
    $outside = User::factory()->create();

    $manager->managedDepartments()->attach($department);
    $direct->departments()->attach($department);
    $direct->managedDepartments()->attach($nestedDepartment);
    $nested->departments()->attach($nestedDepartment);

    $directCertificate = Certificate::factory()->create(['user_id' => $direct]);
    $nestedCertificate = Certificate::factory()->create(['user_id' => $nested]);
    $outsideCertificate = Certificate::factory()->create(['user_id' => $outside]);

    Livewire\Livewire::actingAs($manager)
        ->test('compliance.certificates')
        ->assertSee($directCertificate->certificate_number)
        ->assertSee($nestedCertificate->certificate_number)
        ->assertDontSee($outsideCertificate->certificate_number);

    $this->actingAs($manager)
        ->get(route('certificates.download', ['certificate' => $nestedCertificate]))
        ->assertOk();

    $this->actingAs(userWithPermissions([Permission::CertificatesView]))
        ->get(route('certificates.download', ['certificate' => $nestedCertificate]))
        ->assertForbidden();
});

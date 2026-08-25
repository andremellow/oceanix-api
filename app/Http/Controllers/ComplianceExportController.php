<?php

namespace App\Http\Controllers;

use App\Models\UserTrainingAssignment;
use App\Services\Audit\AuditLogger;
use App\Services\Compliance\ComplianceOverview;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports the operational table under the filters currently applied.
 *
 * Streamed row by row so a workforce-sized report never has to fit in memory, and the
 * export itself is audited: who pulled a list of people and their compliance status is a
 * question the organization should be able to answer.
 */
class ComplianceExportController extends Controller
{
    public function __invoke(Request $request, ComplianceOverview $overview, AuditLogger $audit): StreamedResponse
    {
        $filters = $request->only(['search', 'department_id', 'job_function_id', 'course_id', 'status', 'due_bucket', 'origin']);

        $audit->log('compliance_report.exported', metadata: ['filters' => array_filter($filters)]);

        $filename = sprintf('oceanix-compliance-%s.csv', now()->format('Y-m-d-His'));

        return response()->streamDownload(function () use ($overview, $filters, $request): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                __('Employee'), __('ui.email'), __('ui.employee_id'), __('Department'), __('Job function'),
                __('Course'), __('Content version'), __('Origin'), __('Due date'), __('Status'),
                __('Days overdue'), __('Completed'), __('Certificate'),
            ]);

            $overview->assignments($filters, $request->user())
                ->with(['user.departments', 'user.jobFunctions', 'course', 'courseVersion', 'certificate'])
                ->chunk(500, function ($assignments) use ($handle): void {
                    foreach ($assignments as $assignment) {
                        fputcsv($handle, $this->row($assignment));
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return list<string>
     */
    private function row(UserTrainingAssignment $assignment): array
    {
        return [
            $assignment->user->name,
            $assignment->user->email,
            (string) $assignment->user->employee_id,
            $assignment->user->departments->pluck('name')->join(', '),
            $assignment->user->jobFunctions->pluck('name')->join(', '),
            $assignment->course->title,
            (string) $assignment->courseVersion->version_number,
            $assignment->origin_type->label(),
            $assignment->due_at?->toDateString() ?? '',
            $assignment->status->label(),
            (string) $assignment->daysOverdue(),
            $assignment->completed_at?->toDateString() ?? '',
            $assignment->certificate?->certificate_number ?? '',
        ];
    }
}

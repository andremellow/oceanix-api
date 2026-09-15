<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Lesson;
use App\Models\Module;
use App\Models\UserTrainingAssignment;
use App\Services\Documents\LessonDocumentAccess;
use Illuminate\Http\Request;

final class LessonDocumentController extends Controller
{
    public function training(Request $request, Company $company, UserTrainingAssignment $assignment, Lesson $lesson, string $document, LessonDocumentAccess $access)
    {
        return $access->training($request->user(), $assignment, $lesson, $document);
    }

    public function company(Request $request, Company $company, Course $course, Lesson $lesson, string $document, LessonDocumentAccess $access)
    {
        return $access->company($request->user(), $course, $lesson, $document);
    }

    public function platformCourse(Course $course, CourseVersion $version, string $kind, string $item, string $document, LessonDocumentAccess $access)
    {
        return $access->platformCourse($course, $version, $kind, $item, $document);
    }

    public function platformModule(Module $module, string $document, LessonDocumentAccess $access)
    {
        return $access->platformModule($module, $document);
    }

    public function preview(#[\SensitiveParameter] string $token, string $kind, string $item, string $document, LessonDocumentAccess $access)
    {
        return $access->preview($token, $kind, $item, $document);
    }
}

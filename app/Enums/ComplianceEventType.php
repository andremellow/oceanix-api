<?php

namespace App\Enums;

/**
 * Append-only compliance trail — see docs/product-spec.md §11.
 *
 * Event types are a closed vocabulary so evidence stays queryable. Adding a type is a
 * schema-level decision: never write an ad-hoc string into `compliance_events.event_type`.
 */
enum ComplianceEventType: string
{
    case AssignmentCreated = 'assignment.created';
    case AssignmentOpened = 'assignment.opened';
    case AssignmentCancelled = 'assignment.cancelled';
    case AssignmentWaived = 'assignment.waived';
    case CourseStarted = 'course.started';
    case CourseFailed = 'course.failed';
    case CourseCompleted = 'course.completed';
    case LessonStarted = 'lesson.started';
    case LessonFailed = 'lesson.failed';
    case LessonCompleted = 'lesson.completed';
    case PlaybackAuthorized = 'playback.authorized';
    case VideoPlayed = 'video.played';
    case VideoPaused = 'video.paused';
    case VideoSeeked = 'video.seeked';
    case VideoProgressed = 'video.progressed';
    case VideoEnded = 'video.ended';
    case VideoRewatched = 'video.rewatched';
    case QuestionPresented = 'question.presented';
    case QuestionAnswered = 'question.answered';
    case QuestionFailed = 'question.failed';
    case QuestionPassed = 'question.passed';
    case CertificateIssued = 'certificate.issued';
    case CertificateRevoked = 'certificate.revoked';
    case NotificationQueued = 'notification.queued';
    case NotificationSent = 'notification.sent';
    case NotificationFailed = 'notification.failed';

    public function label(): string
    {
        return __(match ($this) {
            self::AssignmentCreated => 'Assignment created',
            self::AssignmentOpened => 'Assignment opened',
            self::AssignmentCancelled => 'Assignment cancelled',
            self::AssignmentWaived => 'Assignment waived',
            self::CourseStarted => 'Course started',
            self::CourseFailed => 'Course failed',
            self::CourseCompleted => 'Course completed',
            self::LessonStarted => 'Lesson started',
            self::LessonFailed => 'Lesson failed',
            self::LessonCompleted => 'Lesson completed',
            self::PlaybackAuthorized => 'Playback authorized',
            self::VideoPlayed => 'Video played',
            self::VideoPaused => 'Video paused',
            self::VideoSeeked => 'Video seeked',
            self::VideoProgressed => 'Video progressed',
            self::VideoEnded => 'Video ended',
            self::VideoRewatched => 'Video rewatched',
            self::QuestionPresented => 'Question presented',
            self::QuestionAnswered => 'Question answered',
            self::QuestionFailed => 'Question failed',
            self::QuestionPassed => 'Question passed',
            self::CertificateIssued => 'Certificate issued',
            self::CertificateRevoked => 'Certificate revoked',
            self::NotificationQueued => 'Notification queued',
            self::NotificationSent => 'Notification sent',
            self::NotificationFailed => 'Notification failed',
        });
    }

    /** Events the client (browser/device) is allowed to submit through the ingestion API. */
    public function isClientReportable(): bool
    {
        return in_array($this, [
            self::AssignmentOpened,
            self::CourseStarted,
            self::LessonStarted,
            self::VideoPlayed,
            self::VideoPaused,
            self::VideoSeeked,
            self::VideoProgressed,
            self::VideoEnded,
            self::VideoRewatched,
            self::QuestionPresented,
        ], true);
    }

    /** @return list<string> */
    public static function clientReportableValues(): array
    {
        return array_values(array_map(
            fn (self $type): string => $type->value,
            array_filter(self::cases(), fn (self $type): bool => $type->isClientReportable()),
        ));
    }
}

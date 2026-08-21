<?php

namespace App\Mail;

use App\Enums\NotificationType;
use App\Models\ScheduledNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TrainingNotificationMail extends Mailable
{
    public function __construct(public readonly ScheduledNotification $notification) {}

    public function envelope(): Envelope
    {
        $type = NotificationType::from($this->notification->type);

        return new Envelope(
            subject: $type->subject($this->notification->assignment?->course->title ?? ''),
        );
    }

    public function content(): Content
    {
        $assignment = $this->notification->assignment;

        return new Content(
            markdown: 'emails.training-notification',
            with: [
                'type' => NotificationType::from($this->notification->type),
                'name' => $this->notification->user->name,
                'assignment' => $assignment,
                'url' => $assignment !== null ? route('my-training.show', ['assignment' => $assignment]) : route('my-training'),
            ],
        );
    }
}

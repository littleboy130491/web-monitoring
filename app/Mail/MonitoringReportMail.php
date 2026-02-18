<?php

namespace App\Mail;

use App\Models\MonitoringReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MonitoringReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public MonitoringReport $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->report->subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.monitoring-report');
    }
}

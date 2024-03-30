<?php

namespace MauticPlugin\AmazonSESBundle\Services\AmazonSES;

use Teknasyon\AwsSesNotification\Email\BouncedEmail as BouncedEmailBase;

class BouncedEmailRecipient
{
    protected string $emailAddress;
    protected string $action;
    protected string $status;
    protected string $diagnosticCode;

    public function __construct(array $recipient)
    {
        $this->emailAddress = isset($recipient['emailAddress']) ? $recipient['emailAddress'] : '';
        $this->action = isset($recipient['action']) ? $recipient['action'] : '';
        $this->status = isset($recipient['status']) ? $recipient['status'] : '';
        $this->diagnosticCode  = isset($recipient['diagnosticCode']) ? $recipient['diagnosticCode'] : 'unknown';
    }

    public function getEmailAddress(): string
    {
        return $this->emailAddress;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDiagnosticCode(): string
    {
        return $this->diagnosticCode;
    }

    public function __toString()
    {
        $message = $this->emailAddress . ' as bounced, reason: ' . $this->diagnosticCode;
        return $message;
    }
}

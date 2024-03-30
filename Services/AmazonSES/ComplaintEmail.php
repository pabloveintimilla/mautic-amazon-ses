<?php

namespace MauticPlugin\AmazonSESBundle\Services\AmazonSES;

use Teknasyon\AwsSesNotification\Email\ComplaintEmail as ComplaintEmailBase;

class ComplaintEmail extends ComplaintEmailBase
{
    protected string $complaintFeedbackType;

    public function __construct(array $sesMessage)
    {
        // Fix base class control
        if (!isset($sesMessage['mail']['sourceIp'])) {
            $sesMessage['mail']['sourceIp'] = isset($sesMessage['mail']['tags']['ses:source-ip']) ?
                $sesMessage['mail']['tags']['ses:source-ip'] :
                '';
        }
        parent::__construct($sesMessage);
        $this->complaintFeedbackType = isset($sesMessage['complaint']['complaintFeedbackType']) ?
            $sesMessage['complaint']['complaintFeedbackType']
            : '';
    }

    public function getComplaintFeedbackType()
    {
        return $this->complaintFeedbackType;
    }
}

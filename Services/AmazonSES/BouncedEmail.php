<?php

namespace MauticPlugin\AmazonSESBundle\Services\AmazonSES;

use Teknasyon\AwsSesNotification\Email\BouncedEmail as BouncedEmailBase;
use MauticPlugin\AmazonSESBundle\Services\AmazonSES\BouncedEmailRecipient;

class BouncedEmail extends BouncedEmailBase
{
    protected array $sesMessage;
    protected array $bounce;

    public function __construct(array $sesMessage)
    {
        // Fix base class control
        if (!isset($sesMessage['mail']['sourceIp'])) {
            $sesMessage['mail']['sourceIp'] = isset($sesMessage['mail']['tags']['ses:source-ip']) ?
                $sesMessage['mail']['tags']['ses:source-ip'] :
                '';
        }
        $this->bounce = isset($sesMessage['bounce']) ?
            $sesMessage['bounce'] :
            [];

        parent::__construct($sesMessage);
    }

    /**
     * Get recipients detail data such as diagnosticCode
     * @return array<BouncedEmailRecipient>
     */
    public function getRecipientDetails()
    {
        $bouncedRecipients = $this->bounce['bouncedRecipients'];
        foreach ($bouncedRecipients as $bouncedRecipient) {
            $receipts[] = new BouncedEmailRecipient($bouncedRecipient);
        }
        return $receipts;
    }

    /**
     * Get bounced subtype
     * @return string
     */
    public function getBounceSubType(): string
    {
        $bouncedRecipients = isset($this->bounce['bounceSubType']) ?
            $this->bounce['bounceSubType'] :
            '';
        return $bouncedRecipients;
    }

    /**
     * Get all bounce data
     */
    public function getBounce(): array
    {
        return $this->bounce;
    }
}

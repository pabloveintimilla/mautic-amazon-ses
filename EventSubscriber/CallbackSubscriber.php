<?php

namespace MauticPlugin\AmazonSESBundle\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Transport\Dsn;
use MauticPlugin\AmazonSESBundle\AmazonSESBundle;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

use MauticPlugin\AmazonSESBundle\Services\AmazonSES\BouncedEmail;
use MauticPlugin\AmazonSESBundle\Services\AmazonSES\ComplaintEmail;

class CallbackSubscriber implements EventSubscriberInterface
{
    protected TransportWebhookEvent $webhookEvent;
    protected array $payload;

    public function __construct(
        private TransportCallback $transportCallback,
        private CoreParametersHelper $coreParametersHelper,
        private LoggerInterface $logger,
        private Client $httpClient,
        private TranslatorInterface $translator
    ) {
    }

    /**
     * Process callback of AWS to register bounced and compilances
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::ON_TRANSPORT_WEBHOOK => 'processCallbackRequest',
        ];
    }

    public function processCallbackRequest(TransportWebhookEvent $webhookEvent): void
    {
        $this->webhookEvent = $webhookEvent;
        if (!$this->validateCallbackRequest()) {
            return;
        }

        $this->payload = $this->webhookEvent->getRequest()->request->all();

        $type    = '';
        if (array_key_exists('Type', $this->payload)) {
            $type = $this->payload['Type'];
        } elseif (array_key_exists('eventType', $this->payload)) {
            $type = $this->payload['eventType'];
        }

        try {
            $this->processJsonPayload($type);
        } catch (\Exception $e) {
            $message = 'AmazonCallback: ' . $e->getMessage();
            $this->logger->error($message);
            $this->webhookEvent->setResponse(new Response($message, Response::HTTP_BAD_REQUEST));
            return;
        }
        $webhookEvent->setResponse(new Response('Callback processed'));
    }

    /**
     * Validate if request callback is correct
     * @return bool
     */
    protected function validateCallbackRequest(): bool
    {
        // Valid if mailer transport is AWS SES
        $dsn = Dsn::fromString($this->coreParametersHelper->get('mailer_dsn'));
        if (AmazonSESBundle::AMAZON_SES_API_SCHEME !== $dsn->getScheme()) {
            return false;
        }

        // Check post data
        $postData = $this->webhookEvent->getRequest()->request->all();
        if (empty($postData)) {
            $message = 'AmazonSESCallback: There is no data to process.';
            $this->logger->error($message);
            $this->webhookEvent->setResponse(new Response($message, Response::HTTP_BAD_REQUEST));
            return false;
        }

        //Check type
        if (
            !array_key_exists('Type', $postData) &&
            !array_key_exists('eventType', $postData)
        ) {
            $message = "Key 'Type' not found in payload";
            $this->webhookEvent->setResponse(new Response($message, Response::HTTP_BAD_REQUEST));
            return false;
        }

        return true;
    }

    /**
     * Process json request from Amazon SES.
     *
     * Based on: https://github.com/mzagmajster/mautic-ses-plugin/blob/main/Mailer/Callback/AmazonCallback.php
     *
     * @see https://docs.aws.amazon.com/ses/latest/dg/event-publishing-retrieving-sns-examples.html#event-publishing-retrieving-sns-bounce
     * @param array<string, mixed> $payload from Amazon SES
     */
    public function processJsonPayload(string $type, $message = ''): void
    {
        switch ($type) {
            case 'SubscriptionConfirmation':
                // Confirm Amazon SNS subscription by calling back the SubscribeURL from the playload
                try {
                    $response = $this->httpClient->get($this->payload['SubscribeURL']);
                    if (200 == $response->getStatusCode()) {
                        $this->logger->info('Callback to SubscribeURL from Amazon SNS successfully');
                    }
                } catch (TransferException $e) {
                    $this->logger->error('Callback to SubscribeURL from Amazon SNS failed, reason: ' . $e->getMessage());
                }
                break;

            case 'Notification':
                try {
                    $message = json_decode($this->payload['Message'], true, 512, JSON_THROW_ON_ERROR);
                } catch (\Exception $e) {
                    $this->logger->error('AmazonCallback: Invalid Notification JSON Payload');
                    throw new HttpException(400, 'AmazonCallback: Invalid Notification JSON Payload');
                }

                $this->processJsonPayload($message['notificationType'], $message);
                break;
            case 'Complaint':
                $this->processComplaint();
                break;
            case 'Bounce':
                $this->processBounce();
                break;
            default:
                $this->logger->warning('Received SES webhook of type ' . $this->payload['Type'] . " but couldn't understand payload");
                $this->logger->debug('SES webhook payload: ' . json_encode($this->payload));
                throw new HttpException(400, "Received SES webhook of type '$this->payload[Type]' but couldn't understand payload");
        }
    }

    /**
     * Process bounce type
     */
    protected function processBounce()
    {
        $bouncedEmail = new BouncedEmail($this->payload);

        // Process only permanent bounce
        if (!$bouncedEmail->shouldRemoved()) {
            return;
        }

        $emailId = $bouncedEmail->getHeaders('X-EMAIL-ID');
        $bouncedRecipients = $bouncedEmail->getRecipientDetails();

        foreach ($bouncedRecipients as $bouncedRecipient) {
            $address = Address::create($bouncedRecipient->getEmailAddress());
            $this->transportCallback
                ->addFailureByAddress($address->getAddress(), (string) $bouncedRecipient, DoNotContact::BOUNCED, $emailId);
            $this->logger->debug((string) $bouncedRecipient . ' ' . $bouncedEmail->getBounceSubType());
        }
    }

    /**
     * Process complaint type
     */
    protected function processComplaint()
    {
        $complaintEmail = new ComplaintEmail($this->payload);
        $complainedRecipients = $complaintEmail->getReceipts();

        foreach ($complainedRecipients as $complainedRecipient) {
            // http://docs.aws.amazon.com/ses/latest/DeveloperGuide/notification-contents.html#complaint-object
            switch ($complaintEmail->getComplaintFeedbackType()) {
                case 'abuse':
                    $reason = $this->translator->trans('mautic.plugin.scmailerses.complaint.reason.abuse');
                    break;
                case 'auth-failure':
                    $reason = $this->translator->trans('mautic.plugin.scmailerses.complaint.reason.auth_failure');
                    break;
                case 'fraud':
                    $reason = $this->translator->trans('mautic.plugin.scmailerses.complaint.reason.fraud');
                    break;
                case 'not-spam':
                    $reason = $this->translator->trans('mautic.plugin.scmailerses.complaint.reason.not_spam');
                    break;
                case 'other':
                    $reason = $this->translator->trans('mautic.plugin.scmailerses.complaint.reason.other');
                    break;
                case 'virus':
                    $reason = $this->translator->trans('mautic.plugin.scmailerses.complaint.reason.virus');
                    break;
                default:
                    $reason = $this->translator->trans('mautic.plugin.scmailerses.complaint.reason.unknown');
                    break;
            }

            $emailId = $complaintEmail->getHeaders('X-EMAIL-ID');
            $address = Address::create($complainedRecipient);
            $this->transportCallback->addFailureByAddress($address->getAddress(), $reason, DoNotContact::UNSUBSCRIBED, $emailId);

            $this->logger->debug("Unsubscribe email '" . $address->getAddress() . "'");
        }
    }
}

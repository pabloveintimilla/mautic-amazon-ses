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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class CallbackSubscriber implements EventSubscriberInterface
{
    protected TransportWebhookEvent $webhookEvent;
    protected array $payload;
    protected array $allowdTypes = ['Type', 'eventType', 'notificationType'];

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

        $this->parseRequest();
        if (!$this->validateCallbackRequest()) {
            return;
        }
        $type = $this->parseType();

        try {
            $this->processJsonPayload($type);
        } catch (\Exception $e) {
            $message = 'AmazonCallback: ' . $e->getMessage();
            $this->logger->error($message);
            $this->webhookEvent->setResponse(new Response($message, Response::HTTP_BAD_REQUEST));
            return;
        }

        $this->webhookEvent->setResponse(new Response("Callback processed: $type"));
    }

    /**
     * Parse request to correct content type
     */
    protected function parseRequest()
    {
        $request = $this->webhookEvent->getRequest();
        $contentType = $request->getContentType();
        switch ($contentType) {
            case 'json':
                $this->payload = $request->request->all();
                break;
            default:
                $this->payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
                break;
        }
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

        // Check data
        if (!is_array($this->payload)) {
            $message = 'There is no data to process.';
            $this->logger->error($message . $this->webhookEvent->getRequest()->getContent());
            $this->webhookEvent->setResponse(new Response($message, Response::HTTP_BAD_REQUEST));
            return false;
        }

        //Check type
        if (
            !$this->arrayKeysExists($this->allowdTypes, $this->payload)
        ) {
            $message = "Type of request is invalid";
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
                $this->processSubscriptionConfirmation();
                break;
            case 'Notification':
                $this->processNotification();
                break;
            case 'Complaint':
                $this->processComplaint();
                break;
            case 'Bounce':
                $this->processBounce();
                break;
            default:
                $message = "Received SES webhook of type: $type but couldn't understand payload: ";
                $this->logger->error($message . json_encode($this->payload));
                throw new BadRequestHttpException($message);
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

    /**
     * Confirm AWS SNS to revice callback
     * @see https://docs.aws.amazon.com/sns/latest/dg/SendMessageToHttp.prepare.html
     */
    protected function processSubscriptionConfirmation()
    {
        if (
            !isset($this->payload['SubscribeURL'])
            || !filter_var($this->payload['SubscribeURL'], FILTER_VALIDATE_URL)
        ) {
            $message = 'Invalid SubscribeURL';
            $this->logger->error($message);
            throw new BadRequestHttpException($message);
        }
        // Confirm Amazon SNS subscription by calling back the SubscribeURL from the playload
        try {
            $response = $this->httpClient->get($this->payload['SubscribeURL']);
            if ($response->getStatusCode() == Response::HTTP_OK) {
                $this->logger->info('Callback to SubscribeURL from Amazon SNS successfully');
            }
        } catch (TransferException $e) {
            $message = 'Callback to SubscribeURL from Amazon SNS failed, reason: ' . $e->getMessage();
            $this->logger->error($message);
            throw new BadRequestHttpException($message);
        }
    }

    /**
     * Process notificacion callback
     */
    protected function processNotification()
    {
        $subject = isset($this->payload['Subject']) ?
            $this->payload['Subject'] :
            'Not subject';
        $message = isset($this->payload['Message']) ?
            $this->payload['Message'] :
            'Not message';
        $data = "$subject: $message";
        $this->logger->info($data);
    }

    /**
     * Get Type of callback
     */
    protected function parseType(): string
    {
        $type = array_intersect_key(
            array_flip($this->allowdTypes),
            array_flip(array_keys($this->payload))
        );
        $key = array_keys($type)[0];
        return $this->payload[$key];
    }

    /**
     * Utility function to identify if a array of keys exist
     */
    protected function arrayKeysExists(array $keys, array $array): bool
    {
        $diff = array_intersect_key(
            array_flip($keys),
            array_flip(array_keys($array))
        );
        return count($diff) > 0;
    }
}

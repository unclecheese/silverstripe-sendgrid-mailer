<?php


namespace UncleCheese\SendGridMailer;

use SendGrid;
use SendGrid\Mail\Mail;
use SilverStripe\Core\Environment;
use Swift_Attachment;
use Swift_DependencyContainer;
use Swift_DependencyException;
use Swift_Events_EventDispatcher;
use Swift_Events_EventListener;
use Swift_Events_SendEvent;
use Swift_Transport;
use Exception;
use Swift_Mime_SimpleMessage;

class SendGridTransport implements Swift_Transport
{
    /**
     * @var SendGrid
     */
    private $client;

    /**
     * @var bool
     */
    private $started = false;

    /**
     * @var Swift_Events_EventDispatcher
     */
    private $eventDispatcher;

    /**
     * SendgridTransport constructor.
     * @param SendGrid $client
     * @param null|Swift_Events_EventDispatcher $eventDispatcher
     * @throws Swift_DependencyException
     */
    public function __construct(SendGrid $client, ?Swift_Events_EventDispatcher $eventDispatcher = null)
    {
        $this->client = $client;
        if (null === $eventDispatcher) {
            $eventDispatcher = Swift_DependencyContainer::getInstance()->lookup('transport.eventdispatcher');
        }
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * {@inheritDoc}
     */
    public function isStarted()
    {
        return $this->started;
    }

    /**
     * {@inheritDoc}
     */
    public function start()
    {
        $this->started = true;
    }

    /**
     * {@inheritDoc}
     */
    public function stop()
    {
        $this->started = false;
    }

    /**
     * {@inheritDoc}
     */
    public function ping()
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        if ($evt = $this->eventDispatcher->createSendEvent($this, $message)) {
            $this->eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
            if ($evt->bubbleCancelled()) {
                return 0;
            }
        }

        $email = new Mail();

        $from = $message->getFrom();
        reset($from);

        if($from){
            $email->setFrom(key($from), current($from));
        }else{
            // Get default from address
            if (!$from = Environment::getEnv('SENDGRID_DEFAULT_FROM_EMAIL')) {
                throw new Exception("Please set 'SENDGRID_DEFAULT_FROM_EMAIL' Environment variable");
            }

            $email->setFrom($from);
        }

        $count = 0;

        $testAddress = Environment::getEnv('SENDGRID_TEST_EMAIL');

        if ($testAddress) {
            $email->addTo($testAddress);
            $count++;
        } else {
            if ($to = $message->getTo()) {
                foreach ($to as $address => $name) {
                    $email->addTo($address, $name);
                    $count++;
                }
            }
        }

        if ($replyTo = $message->getReplyTo()) {
            $emails = array_keys($replyTo);
            $names = array_values($replyTo);
            if (!empty($emails)) {
                $email->setReplyTo($emails[0], $names[0] ?? null);
            }
        }

        if ($bcc = $message->getBcc()) {
            foreach ($bcc as $address => $name) {
                $email->addBcc($address, $name);
                $count++;
            }
        }

        if ($cc = $message->getCc()) {
            foreach ($cc as $address => $name) {
                $email->addCc($address, $name);
                $count++;
            }
        }
        $children = $message->getChildren();
        foreach ($children as $child) {
            if ($child instanceof Swift_Attachment) {
                $email->addAttachment(
                    $child->getBody(),
                    $child->getContentType(),
                    $child->getFilename(),
                    $child->getDisposition(),
                    $child->generateId()
                );
            }
        }


        $email->setSubject($message->getSubject());
        // "multipart/alternative" results in empty email bodies. No plain part
        $email->addContent('text/html', $message->getBody());

        $response = $this->client->send($email);

        if (202 === $response->statusCode()) {
            if ($evt) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
                $evt->setFailedRecipients($failedRecipients);
                $this->eventDispatcher->dispatchEvent($evt, 'sendPerformed');
            }

            return $count;
        }

        $this->throwException(new SendGridTransportException($response->body(), $response->statusCode(), null, $response));
    }

    /**
     * {@inheritDoc}
     */
    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        $this->eventDispatcher->bindEventListener($plugin);
    }

    /**
     * @param SendGridTransportException $e
     * @throws SendGridTransportException
     */
    private function throwException(SendGridTransportException $e)
    {
        if ($evt = $this->eventDispatcher->createTransportExceptionEvent($this, $e)) {
            $this->eventDispatcher->dispatchEvent($evt, 'exceptionThrown');
            if (!$evt->bubbleCancelled()) {
                throw $e;
            }
        } else {
            throw $e;
        }
    }
}

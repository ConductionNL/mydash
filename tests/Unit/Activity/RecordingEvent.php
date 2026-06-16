<?php

/**
 * RecordingEvent
 *
 * In-memory IEvent implementation used by ExtensionTest. Records every
 * field set on it so test assertions can verify the canonical
 * `{app, type, object_type, object_name, link, author, affecteduser}`
 * payload published by ActivityPublisher and the rich subject set by
 * Extension::parse().
 *
 * Kept separate from the OCP IEvent interface because PHPUnit's
 * createMock() builds a proxy that does not retain state across
 * setter to getter pairs, which makes contract assertions
 * (REQ-ACT-003, REQ-ACT-011b) impossible to express on a generated
 * mock.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Activity
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Activity;

use OCP\Activity\IEvent;

/**
 * Stateful test double for {@see IEvent}.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Mirrors NC IEvent surface.
 * @SuppressWarnings(PHPMD.TooManyFields)        Mirrors NC IEvent surface.
 * @SuppressWarnings(PHPMD.ExcessivePublicCount) Mirrors NC IEvent surface.
 * @SuppressWarnings(PHPMD.UnusedPrivateField)   Stored for completeness.
 * @SuppressWarnings(PHPMD.BooleanGetMethodName) Required by IEvent contract.
 */
class RecordingEvent implements IEvent
{

    /**
     * App identifier set on the event.
     *
     * @var string
     */
    private string $app = '';

    /**
     * Event type string.
     *
     * @var string
     */
    private string $type = '';

    /**
     * Author user ID.
     *
     * @var string
     */
    private string $author = '';

    /**
     * Affected (recipient) user ID.
     *
     * @var string
     */
    private string $affectedUser = '';

    /**
     * Unix timestamp.
     *
     * @var integer
     */
    private int $timestamp = 0;

    /**
     * Subject template key.
     *
     * @var string
     */
    private string $subject = '';

    /**
     * Subject parameter array.
     *
     * @var array<int|string, mixed>
     */
    private array $subjectParams = [];

    /**
     * Parsed subject string.
     *
     * @var string
     */
    private string $parsedSubject = '';

    /**
     * Rich subject template.
     *
     * @var string
     */
    private string $richSubject = '';

    /**
     * Rich subject parameters.
     *
     * @var array<string, array<string, string>>
     */
    private array $richSubjectParams = [];

    /**
     * Message body.
     *
     * @var string
     */
    private string $message = '';

    /**
     * Message parameter array.
     *
     * @var array<int|string, mixed>
     */
    private array $messageParams = [];

    /**
     * Parsed message string.
     *
     * @var string
     */
    private string $parsedMessage = '';

    /**
     * Rich message template.
     *
     * @var string
     */
    private string $richMessage = '';

    /**
     * Rich message parameters.
     *
     * @var array<string, array<string, string>>
     */
    private array $richMessageParams = [];

    /**
     * Object type identifier.
     *
     * @var string
     */
    private string $objectType = '';

    /**
     * Object numeric primary key.
     *
     * @var integer
     */
    private int $objectId = 0;

    /**
     * Object human-readable name (UUID slot).
     *
     * @var string
     */
    private string $objectName = '';

    /**
     * Deep-link URL.
     *
     * @var string
     */
    private string $link = '';

    /**
     * Icon URL.
     *
     * @var string
     */
    private string $icon = '';

    /**
     * Optional child event for merging.
     *
     * @var IEvent|null
     */
    private ?IEvent $childEvent = null;

    /**
     * Whether NC should auto-generate a notification.
     *
     * @var boolean
     */
    private bool $generateNotification = true;

    /**
     * Set the application id.
     *
     * @param string $app The application id.
     *
     * @return self
     */
    public function setApp(string $app): self
    {
        $this->app = $app;
        return $this;
    }//end setApp()

    /**
     * Set the event type.
     *
     * @param string $type The event type string.
     *
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }//end setType()

    /**
     * Set the affected (recipient) user id.
     *
     * @param string $affectedUser The recipient user id.
     *
     * @return self
     */
    public function setAffectedUser(string $affectedUser): self
    {
        $this->affectedUser = $affectedUser;
        return $this;
    }//end setAffectedUser()

    /**
     * Set the author (actor) user id.
     *
     * @param string $author The actor user id.
     *
     * @return self
     */
    public function setAuthor(string $author): self
    {
        $this->author = $author;
        return $this;
    }//end setAuthor()

    /**
     * Set the unix timestamp of the event.
     *
     * @param integer $timestamp The unix timestamp.
     *
     * @return self
     */
    public function setTimestamp(int $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }//end setTimestamp()

    /**
     * Set the raw subject template key and parameters.
     *
     * @param string                   $subject    The template key.
     * @param array<int|string, mixed> $parameters The substitution parameters.
     *
     * @return self
     */
    public function setSubject(string $subject, array $parameters=[]): self
    {
        $this->subject       = $subject;
        $this->subjectParams = $parameters;
        return $this;
    }//end setSubject()

    /**
     * Set the parsed (already translated) subject.
     *
     * @param string $subject The translated subject.
     *
     * @return self
     */
    public function setParsedSubject(string $subject): self
    {
        $this->parsedSubject = $subject;
        return $this;
    }//end setParsedSubject()

    /**
     * Return the parsed subject.
     *
     * @return string
     */
    public function getParsedSubject(): string
    {
        return $this->parsedSubject;
    }//end getParsedSubject()

    /**
     * Set the rich-formatted subject template.
     *
     * @param string                               $subject    The template.
     * @param array<string, array<string, string>> $parameters The substitution parameters.
     *
     * @return self
     */
    public function setRichSubject(string $subject, array $parameters=[]): self
    {
        $this->richSubject       = $subject;
        $this->richSubjectParams = $parameters;
        return $this;
    }//end setRichSubject()

    /**
     * Return the rich-formatted subject template.
     *
     * @return string
     */
    public function getRichSubject(): string
    {
        return $this->richSubject;
    }//end getRichSubject()

    /**
     * Return the rich subject parameters.
     *
     * @return array<string, array<string, string>>
     */
    public function getRichSubjectParameters(): array
    {
        return $this->richSubjectParams;
    }//end getRichSubjectParameters()

    /**
     * Set the message body and its parameters.
     *
     * @param string                   $message    The message text.
     * @param array<int|string, mixed> $parameters The substitution parameters.
     *
     * @return self
     */
    public function setMessage(string $message, array $parameters=[]): self
    {
        $this->message       = $message;
        $this->messageParams = $parameters;
        return $this;
    }//end setMessage()

    /**
     * Set the parsed (translated) message.
     *
     * @param string $message The translated message.
     *
     * @return self
     */
    public function setParsedMessage(string $message): self
    {
        $this->parsedMessage = $message;
        return $this;
    }//end setParsedMessage()

    /**
     * Return the parsed message.
     *
     * @return string
     */
    public function getParsedMessage(): string
    {
        return $this->parsedMessage;
    }//end getParsedMessage()

    /**
     * Set the rich-formatted message template.
     *
     * @param string                               $message    The template.
     * @param array<string, array<string, string>> $parameters The substitution parameters.
     *
     * @return self
     */
    public function setRichMessage(string $message, array $parameters=[]): self
    {
        $this->richMessage       = $message;
        $this->richMessageParams = $parameters;
        return $this;
    }//end setRichMessage()

    /**
     * Return the rich-formatted message template.
     *
     * @return string
     */
    public function getRichMessage(): string
    {
        return $this->richMessage;
    }//end getRichMessage()

    /**
     * Return the rich message parameters.
     *
     * @return array<string, array<string, string>>
     */
    public function getRichMessageParameters(): array
    {
        return $this->richMessageParams;
    }//end getRichMessageParameters()

    /**
     * Store the object identity tuple.
     *
     * @param string  $objectType The object type identifier.
     * @param integer $objectId   The numeric primary key.
     * @param string  $objectName The human-readable name slot.
     *
     * @return self
     */
    public function setObject(string $objectType, int $objectId, string $objectName=''): self
    {
        $this->objectType = $objectType;
        $this->objectId   = $objectId;
        $this->objectName = $objectName;
        return $this;
    }//end setObject()

    /**
     * Set the absolute deep-link URL.
     *
     * @param string $link The URL.
     *
     * @return self
     */
    public function setLink(string $link): self
    {
        $this->link = $link;
        return $this;
    }//end setLink()

    /**
     * Return the application id.
     *
     * @return string
     */
    public function getApp(): string
    {
        return $this->app;
    }//end getApp()

    /**
     * Return the event type.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }//end getType()

    /**
     * Return the affected user id.
     *
     * @return string
     */
    public function getAffectedUser(): string
    {
        return $this->affectedUser;
    }//end getAffectedUser()

    /**
     * Return the author user id.
     *
     * @return string
     */
    public function getAuthor(): string
    {
        return $this->author;
    }//end getAuthor()

    /**
     * Return the unix timestamp.
     *
     * @return integer
     */
    public function getTimestamp(): int
    {
        return $this->timestamp;
    }//end getTimestamp()

    /**
     * Return the subject template key.
     *
     * @return string
     */
    public function getSubject(): string
    {
        return $this->subject;
    }//end getSubject()

    /**
     * Return the subject parameters.
     *
     * @return array<int|string, mixed>
     */
    public function getSubjectParameters(): array
    {
        return $this->subjectParams;
    }//end getSubjectParameters()

    /**
     * Return the message body.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }//end getMessage()

    /**
     * Return the message parameters.
     *
     * @return array<int|string, mixed>
     */
    public function getMessageParameters(): array
    {
        return $this->messageParams;
    }//end getMessageParameters()

    /**
     * Return the object type identifier.
     *
     * @return string
     */
    public function getObjectType(): string
    {
        return $this->objectType;
    }//end getObjectType()

    /**
     * Return the numeric object id.
     *
     * @return integer
     */
    public function getObjectId(): int
    {
        return $this->objectId;
    }//end getObjectId()

    /**
     * Return the object name (UUID slot).
     *
     * @return string
     */
    public function getObjectName(): string
    {
        return $this->objectName;
    }//end getObjectName()

    /**
     * Return the deep-link URL.
     *
     * @return string
     */
    public function getLink(): string
    {
        return $this->link;
    }//end getLink()

    /**
     * Set the icon URL.
     *
     * @param string $icon The absolute icon URL.
     *
     * @return self
     */
    public function setIcon(string $icon): self
    {
        $this->icon = $icon;
        return $this;
    }//end setIcon()

    /**
     * Return the icon URL.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return $this->icon;
    }//end getIcon()

    /**
     * Attach a child event for merging.
     *
     * @param IEvent $child The child event.
     *
     * @return self
     */
    public function setChildEvent(IEvent $child): self
    {
        $this->childEvent = $child;
        return $this;
    }//end setChildEvent()

    /**
     * Return the previously attached child event, if any.
     *
     * @return IEvent|null
     */
    public function getChildEvent()
    {
        return $this->childEvent;
    }//end getChildEvent()

    /**
     * Indicate whether the event has the minimum required fields.
     *
     * @return boolean
     */
    public function isValid(): bool
    {
        return ($this->app !== '' && $this->type !== '');
    }//end isValid()

    /**
     * Indicate whether parsing produced a non-empty subject.
     *
     * @return boolean
     */
    public function isValidParsed(): bool
    {
        return ($this->isValid() === true && $this->parsedSubject !== '');
    }//end isValidParsed()

    /**
     * Toggle whether NC should auto-generate a notification.
     *
     * @param boolean $generate Whether to auto-generate.
     *
     * @return self
     */
    public function setGenerateNotification(bool $generate): self
    {
        $this->generateNotification = $generate;
        return $this;
    }//end setGenerateNotification()

    /**
     * Return whether NC should auto-generate a notification.
     *
     * @return boolean
     */
    public function getGenerateNotification(): bool
    {
        return $this->generateNotification;
    }//end getGenerateNotification()
}//end class

<?php

declare(strict_types=1);

/**
 * Mail App
 *
 * @copyright 2022 Anna Larch <anna.larch@gmx.net>
 *
 * @author Anna Larch <anna.larch@gmx.net>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Mail\Service;

use OC\EventDispatcher\EventDispatcher;
use OCA\Mail\Account;
use OCA\Mail\Contracts\ILocalMailboxService;
use OCA\Mail\Contracts\IMailTransmission;
use OCA\Mail\Db\LocalMessage;
use OCA\Mail\Db\LocalMessageMapper;
use OCA\Mail\Db\MessageMapper;
use OCA\Mail\Db\Recipient;
use OCA\Mail\Events\OutboxMessageCreatedEvent;
use OCA\Mail\Service\Attachment\AttachmentService;
use OCP\AppFramework\Db\DoesNotExistException;

class OutboxService implements ILocalMailboxService {

	/** @var IMailTransmission */
	private $transmission;

	/** @var LocalMessageMapper */
	private $mapper;

	/** @var AttachmentService */
	private $attachmentService;

	/** @var MessageMapper */
	private $messageMapper;

	/** @var EventDispatcher */
	private $eventDispatcher;

	public function __construct(IMailTransmission $transmission,
								LocalMessageMapper $mapper,
								AttachmentService $attachmentService,
								MessageMapper $messageMapper,
								EventDispatcher $eventDispatcher) {
		$this->transmission = $transmission;
		$this->mapper = $mapper;
		$this->attachmentService = $attachmentService;
		$this->messageMapper = $messageMapper;
		$this->eventDispatcher = $eventDispatcher;
	}

	/**
	 * @param array $recipients
	 * @param int $type
	 * @return Recipient[]
	 */
	private static function convertToRecipient(array $recipients, int $type): array {
		return array_map(function ($recipient) use ($type) {
			$recipient['type'] = $type;
			return Recipient::fromParams($recipient);
		}, $recipients);
	}

	/**
	 * @return LocalMessage[]
	 */
	public function getMessages(string $userId): array {
		return $this->mapper->getAllForUser($userId);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function getMessage(int $id, string $userId): LocalMessage {
		return $this->mapper->findById($id, $userId);
	}

	public function deleteMessage(string $userId, LocalMessage $message): void {
		$this->attachmentService->deleteLocalMessageAttachments($userId, $message->getId());
		$this->mapper->deleteWithRecipients($message);
	}

	public function sendMessage(LocalMessage $message, Account $account): void {
		$this->transmission->sendLocalMessage($account, $message);
		$this->attachmentService->deleteLocalMessageAttachments($account->getUserId(), $message->getId());
		$this->mapper->deleteWithRecipients($message);
	}

	public function saveMessage(string $userId, LocalMessage $message, array $to, array $cc, array $bcc, array $attachmentIds = []): LocalMessage {
		$toRecipients = self::convertToRecipient($to, Recipient::TYPE_TO);
		$ccRecipients = self::convertToRecipient($cc, Recipient::TYPE_CC);
		$bccRecipients = self::convertToRecipient($bcc, Recipient::TYPE_BCC);
		$message = $this->mapper->saveWithRecipients($message, $toRecipients, $ccRecipients, $bccRecipients);
		$message->setAttachments($this->attachmentService->saveLocalMessageAttachments($message->getId(), $attachmentIds));
		return $message;
	}

	public function updateMessage(string $userId, LocalMessage $message, array $to, array $cc, array $bcc, array $attachmentIds = []): LocalMessage {
		$toRecipients = self::convertToRecipient($to, Recipient::TYPE_TO);
		$ccRecipients = self::convertToRecipient($cc, Recipient::TYPE_CC);
		$bccRecipients = self::convertToRecipient($bcc, Recipient::TYPE_BCC);
		$message = $this->mapper->updateWithRecipients($message, $toRecipients, $ccRecipients, $bccRecipients);
		$message->setAttachments($this->attachmentService->updateLocalMessageAttachments($userId, $message, $attachmentIds));
		return $message;
	}

	public function handleDraft(Account $account, int $draftId): void {
		try {
			$message = $this->messageMapper->findByUserId($account->getUserId(), $draftId);
		} catch (DoesNotExistException $e) {
			// do something here? the draft already doesn't exist
			return;
		}
		$this->eventDispatcher->dispatch(
			OutboxMessageCreatedEvent::class,
			new OutboxMessageCreatedEvent($account, $message)
		);
	}
}

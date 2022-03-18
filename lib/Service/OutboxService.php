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

use OCA\Mail\Account;
use OCA\Mail\Contracts\ILocalMailboxService;
use OCA\Mail\Contracts\IMailTransmission;
use OCA\Mail\Db\LocalAttachmentMapper;
use OCA\Mail\Db\LocalMessage;
use OCA\Mail\Db\LocalMessageMapper;
use OCA\Mail\Db\Recipient;
use OCA\Mail\Db\RecipientMapper;
use OCA\Mail\Exception\ServiceException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

class OutboxService implements ILocalMailboxService {

	/** @var IMailTransmission */
	private $transmission;

	/** @var LoggerInterface */
	private $logger;

	/** @var LocalMessageMapper */
	private $mapper;

	/** @var LocalAttachmentMapper */
	private $attachmentMapper;

	/** @var RecipientMapper */
	private $recipientMapper;

	public function __construct(IMailTransmission $transmission,
								LoggerInterface $logger,
								LocalMessageMapper $mapper,
								LocalAttachmentMapper $attachmentMapper,
								RecipientMapper $recipientMapper) {
		$this->transmission = $transmission;
		$this->logger = $logger;
		$this->mapper = $mapper;
		$this->attachmentMapper = $attachmentMapper;
		$this->recipientMapper = $recipientMapper;
	}

	/**
	 * @return LocalMessage[]
	 * @throws ServiceException
	 */
	public function getMessages(string $userId): array {
		try {
			return $this->mapper->getAllForUser($userId);
		} catch (Exception $e) {
			throw new ServiceException("Could not get messages for user $userId", 0, $e);
		}
	}

	/**
	 * @param int $id
	 * @return LocalMessage
	 * @throws DoesNotExistException
	 */
	public function getMessage(int $id, string $userId): LocalMessage {
		return $this->mapper->findById($id, $userId);
	}

	/**
	 * @throws ServiceException
	 */
	public function deleteMessage(LocalMessage $message): void {
		try {
			$this->mapper->deleteWithRelated($message);
		} catch (Exception $e) {
			throw new ServiceException('Could not delete message' . $e->getMessage(), $e->getCode(), $e);
		}
	}

	/**
	 * @throws ServiceException
	 */
	public function sendMessage(LocalMessage $message, Account $account): void {
		try {
			$this->transmission->sendLocalMessage($account, $message, $message->getRecipients(), $message->getAttachments());
			$this->mapper->deleteWithRelated($message);
		} catch (Exception $e) {
			throw new ServiceException('Could not send message', 0, $e);
		}
	}

	/**
	 * @throws ServiceException
	 */
	public function saveMessage(LocalMessage $message, array $to, array $cc, array $bcc, array $attachmentIds = []): LocalMessage {
		$toRecipients = array_map(function ($recipient) {
			return Recipient::fromRow($recipient);
		}, $to);
		$ccRecipients = array_map(function ($recipient) {
			return Recipient::fromRow($recipient);
		}, $cc);
		$bccRecipients = array_map(function ($recipient) {
			return Recipient::fromRow($recipient);
		}, $bcc);

		try {
			$this->mapper->saveWithRelatedData($message, $toRecipients, $ccRecipients, $bccRecipients, $attachmentIds);
		} catch (Exception $e) {
			throw new ServiceException('Could not save message', 400, $e);
		}
		return $message;
	}
}

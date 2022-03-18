<?php

declare(strict_types=1);

/**
 * @copyright 2022 Anna Larch <anna@nextcloud.com>
 *
 * @author 2022 Anna Larch <anna@nextcloud.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Mail\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DBException;
use Throwable;
use function array_map;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<LocalMessage>
 */
class LocalMessageMapper extends QBMapper {
	/** @var LocalAttachmentMapper */
	private $attachmentMapper;

	/** @var RecipientMapper */
	private $recipientMapper;

	public function __construct(IDBConnection $db,
								LocalAttachmentMapper $attachmentMapper,
								RecipientMapper $recipientMapper) {
		parent::__construct($db, 'mail_local_messages');
		$this->recipientMapper = $recipientMapper;
		$this->attachmentMapper = $attachmentMapper;
	}

	/**
	 * @param string $userId
	 * @return LocalMessage[]
	 * @throws DBException
	 */
	public function getAllForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.*')
			->from('mail_accounts', 'a')
			->join('a', $this->getTableName(), 'm', $qb->expr()->eq('m.account_id', 'a.id'))
			->where(
				$qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR), IQueryBuilder::PARAM_STR),
				$qb->expr()->eq('m.type', $qb->createNamedParameter(LocalMessage::TYPE_OUTGOING, IQueryBuilder::PARAM_INT), IQueryBuilder::PARAM_INT)
			);
		$rows = $qb->execute();

		$results = [];
		$ids = [];
		while (($row = $rows->fetch()) !== false) {
			$results[] = $this->mapRowToEntity($row);
			$ids[] = $row['id'];
		}
		$rows->closeCursor();

		$attachments = $this->attachmentMapper->findByLocalMessageIds($ids);
		$recipients = $this->recipientMapper->findByLocalMessageIds($ids);

		$recipientMap = [];
		foreach ($recipients as $r) {
			$recipientMap[$r->getLocalMessageId()][] = $r;
		}
		$attachmentMap = [];
		foreach ($attachments as $a) {
			$attachmentMap[$a->getLocalMessageId()][] = $a;
		}

		return array_map(static function ($localMessage) use ($attachmentMap, $recipientMap) {
			$localMessage->setAttachments($attachmentMap[$localMessage->getId()] ?? []);
			$localMessage->setRecipients($recipientMap[$localMessage->getId()] ?? []);
			return $localMessage;
		}, $results);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(int $id, string $userId): LocalMessage {
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.*')
			->from('mail_accounts', 'a')
			->join('a', $this->getTableName(), 'm', $qb->expr()->eq('m.account_id', 'a.id'))
			->where(
				$qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR), IQueryBuilder::PARAM_STR),
				$qb->expr()->eq('m.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT), IQueryBuilder::PARAM_INT)
			);
		$entity = $this->findEntity($qb);
		$entity->setAttachments($this->attachmentMapper->findByLocalMessageId($id));
		$entity->setRecipients($this->recipientMapper->findByLocalMessageId($id));
		return $entity;
	}

	/**
	 * @param Recipient[] $to
	 * @param Recipient[] $cc
	 * @param Recipient[] $bcc
	 */
	public function saveWithRelatedData(LocalMessage $message, array $to, array $cc, array $bcc, array $attachmentIds = []): void {
		$this->db->beginTransaction();
		try {
			$message = $this->insert($message);
			$this->recipientMapper->saveRecipients($message->getId(), $to, Recipient::TYPE_TO);
			$this->recipientMapper->saveRecipients($message->getId(), $cc, Recipient::TYPE_CC);
			$this->recipientMapper->saveRecipients($message->getId(), $bcc, Recipient::TYPE_BCC);
			$this->attachmentMapper->saveAttachments($message->getId(), $attachmentIds);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function deleteWithRelated(LocalMessage $message): void {
		$this->db->beginTransaction();
		try {
			$this->attachmentMapper->deleteForLocalMailbox($message->getId());
			$this->recipientMapper->deleteForLocalMailbox($message->getId());
			$this->delete($message);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}

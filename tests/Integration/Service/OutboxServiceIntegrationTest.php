<?php

declare(strict_types=1);

/**
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * Mail
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Mail\Tests\Integration\Service;

use ChristophWurst\Nextcloud\Testing\TestUser;
use OC;
use OCA\Mail\Account;
use OCA\Mail\Contracts\IAttachmentService;
use OCA\Mail\Contracts\IMailTransmission;
use OCA\Mail\Db\LocalMessage;
use OCA\Mail\Db\LocalMessageMapper;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Service\OutboxService;
use OCA\Mail\Tests\Integration\Framework\ImapTest;
use OCA\Mail\Tests\Integration\Framework\ImapTestAccount;
use OCA\Mail\Tests\Integration\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;

class OutboxServiceIntegrationTest extends TestCase {
	use ImapTest,
		ImapTestAccount,
		TestUser;

	/** @var MailAccount */
	private $account;

	/** @var IUser */
	private $user;

	/** @var IAttachmentService */
	private $attachmentService;

	/** @var IMailTransmission */
	private $transmission;

	/** @var OutboxService */
	private $outbox;

	/** @var LocalMessageMapper */
	private $mapper;

	protected function setUp(): void {
		parent::setUp();

		$this->resetImapAccount();

		$this->user = $this->createTestUser();
		$this->account = $this->createTestAccount();
		$this->attachmentService = OC::$server->query(IAttachmentService::class);
		$this->transmission = OC::$server->get(IMailTransmission::class);
		$this->mapper = OC::$server->get(LocalMessageMapper::class);

		$this->db = \OC::$server->getDatabaseConnection();
		$qb = $this->db->getQueryBuilder();
		$delete = $qb->delete($this->mapper->getTableName());
		$delete->execute();

		$this->outbox = new OutboxService(
			$this->transmission,
			$this->mapper,
			$this->attachmentService);
	}

	public function testSaveAndGetMessage(): void {
		$message = new LocalMessage();
		$message->setType(LocalMessage::TYPE_OUTGOING);
		$message->setAccountId($this->account->getId());
		$message->setSubject('subject');
		$message->setBody('message');
		$message->setHtml(true);

		$to = [[
			'label' => 'Penny',
			'email' => 'library@stardewvalley.com'
		]];

		$saved = $this->outbox->saveMessage($this->getTestAccountUserId(), $message, $to, [], []);
		$this->assertNotEmpty($message->getRecipients());
		$this->assertEmpty($message->getAttachments());

		$retrieved = $this->outbox->getMessage($message->getId(), $this->getTestAccountUserId());
		$this->assertNotEmpty($message->getRecipients());
		$this->assertEmpty($message->getAttachments());

		$retrieved->resetUpdatedFields();
		$saved->resetUpdatedFields();
		$this->assertEquals($saved, $retrieved); // Assure both operations are identical
	}

	public function testSaveAndGetMessages(): void {
		$message = new LocalMessage();
		$message->setType(LocalMessage::TYPE_OUTGOING);
		$message->setAccountId($this->account->getId());
		$message->setSubject('subject');
		$message->setBody('message');
		$message->setHtml(true);

		$saved = $this->outbox->saveMessage($this->getTestAccountUserId(), $message, [], [], []);
		$this->assertEmpty($message->getRecipients());
		$this->assertEmpty($message->getAttachments());

		$message = new LocalMessage();
		$message->setType(LocalMessage::TYPE_OUTGOING);
		$message->setAccountId($this->account->getId());
		$message->setSubject('subject');
		$message->setBody('message');
		$message->setHtml(true);

		$saved = $this->outbox->saveMessage($this->getTestAccountUserId(), $message, [], [], []);
		$this->assertEmpty($saved->getRecipients());
		$this->assertEmpty($saved->getAttachments());

		$messages = $this->outbox->getMessages($this->getTestAccountUserId());
		$this->assertCount(2, $messages);
	}

	public function testSaveAndDeleteMessage(): void {
		$message = new LocalMessage();
		$message->setType(LocalMessage::TYPE_OUTGOING);
		$message->setAccountId($this->account->getId());
		$message->setSubject('subject');
		$message->setBody('message');
		$message->setHtml(true);

		$to = [[
			'label' => 'Penny',
			'email' => 'library@stardewvalley.com'
		]];

		$saved = $this->outbox->saveMessage($this->getTestAccountUserId(), $message, $to, [], []);
		$this->assertNotEmpty($message->getRecipients());
		$this->assertEmpty($message->getAttachments());

		$this->outbox->deleteMessage($this->getTestAccountUserId(), $saved);

		$this->expectException(DoesNotExistException::class);
		$this->outbox->getMessage($message->getId(), $this->getTestAccountUserId());
	}

	public function testSaveAndUpdateMessage(): void {
		$message = new LocalMessage();
		$message->setType(LocalMessage::TYPE_OUTGOING);
		$message->setAccountId($this->account->getId());
		$message->setSubject('subject');
		$message->setBody('message');
		$message->setHtml(true);

		$to = [[
			'label' => 'Penny',
			'email' => 'library@stardewvalley.com'
		]];

		$saved = $this->outbox->saveMessage($this->getTestAccountUserId(), $message, $to, [], []);
		$this->assertNotEmpty($message->getRecipients());
		$this->assertCount(1, $saved->getRecipients());
		$this->assertEmpty($message->getAttachments());

		$saved->setSubject('Your Trailer will be put up for sale');
		$cc = [[
			'label' => 'Pam',
			'email' => 'buyMeABeer@stardewvalley.com'
		]];
		$updated = $this->outbox->updateMessage($this->getTestAccountUserId(), $saved, $to, $cc, []);

		$this->assertNotEmpty($updated->getRecipients());
		$this->assertEquals('Your Trailer will be put up for sale', $updated->getSubject());
		$this->assertCount(2, $updated->getRecipients());
	}

	public function testSaveAndSendMessage(): void {
		$message = new LocalMessage();
		$message->setType(LocalMessage::TYPE_OUTGOING);
		$message->setAccountId($this->account->getId());
		$message->setSubject('subject');
		$message->setBody('message');
		$message->setHtml(true);

		$to = [[
			'label' => 'Penny',
			'email' => 'library@stardewvalley.com'
		]];

		$saved = $this->outbox->saveMessage($this->getTestAccountUserId(), $message, $to, [], []);
		$this->assertNotEmpty($message->getRecipients());
		$this->assertCount(1, $saved->getRecipients());
		$this->assertEmpty($message->getAttachments());

		$this->outbox->sendMessage($saved, new Account($this->account));

		$this->expectException(DoesNotExistException::class);
		$this->outbox->getMessage($message->getId(), $this->getTestAccountUserId());
	}
}

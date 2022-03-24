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
use OCA\Mail\Service\OutboxService;
use OCA\Mail\Tests\Integration\Framework\ImapTest;
use OCA\Mail\Tests\Integration\Framework\ImapTestAccount;
use OCA\Mail\Tests\Integration\TestCase;
use OCP\IUser;

class OutboxServiceIntegrationTest extends TestCase {
	use ImapTest,
		ImapTestAccount,
		TestUser;

	/** @var Account */
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

		$this->outbox = new OutboxService(
			$this->transmission,
			$this->mapper,
			$this->attachmentService);
	}

	public function testSaveMessage(): void {
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
}

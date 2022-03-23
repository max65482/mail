<?php

declare(strict_types=1);

/**
 * @author Anna Larch <anna.larch@gmx.net>
 *
 * @copyright 2022 Anna Larch <anna.larch@gmx.net>
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

namespace OCA\Mail\Tests\Unit\Controller;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OC\AppFramework\Http;
use OCA\Mail\Account;
use OCA\Mail\Controller\OutboxController;
use OCA\Mail\Db\LocalMessage;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Exception\ClientException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Http\JsonResponse;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\OutboxService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http as HttpAlias;
use OCP\IRequest;

class OutboxControllerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		$this->appName = 'mail';
		$this->service = $this->createMock(OutboxService::class);
		$this->userId = 'john';
		$this->request = $this->createMock(IRequest::class);
		$this->accountService = $this->createMock(AccountService::class);

		$this->controller = new OutboxController(
			$this->appName,
			$this->userId,
			$this->request,
			$this->service,
			$this->accountService
		);
	}

	public function testIndex(): void {
		$messages = [
			new LocalMessage(),
			new LocalMessage()
		];
		$this->service->expects(self::once())
			->method('getMessages')
			->with($this->userId)
			->willReturn($messages);

		$expected = JsonResponse::success(['messages' => $messages]);
		$actual = $this->controller->index();

		$this->assertEquals($expected, $actual);
	}

	public function testIndexNoMessages(): void {
		$messages = [];

		$this->service->expects(self::once())
			->method('getMessages')
			->with($this->userId)
			->willReturn($messages);

		$expected = JsonResponse::success(['messages' => $messages]);
		$actual = $this->controller->index();

		$this->assertEquals($expected, $actual);
	}

	public function testIndexExeption(): void {
		$this->service->expects(self::once())
			->method('getMessages')
			->with($this->userId)
			->willThrowException(new ServiceException());

		$this->expectException(ServiceException::class);
		$this->controller->index();
	}

	public function testShow(): void {
		$message = new LocalMessage();
		$message->setId(1);
		$message->setAccountId(1);

		$this->service->expects(self::once())
			->method('getMessage')
			->with($message->getId(), $this->userId)
			->willReturn($message);
		$this->accountService->expects(self::once())
			->method('find');

		$expected = JsonResponse::success($message);
		$actual = $this->controller->show($message->getId());

		$this->assertEquals($expected, $actual);
	}

	public function testShowMessageNotFound(): void {
		$message = new LocalMessage();
		$message->setId(1);

		$this->service->expects(self::once())
			->method('getMessage')
			->with($message->getId(), $this->userId)
			->willThrowException(new DoesNotExistException(''));
		$this->accountService->expects(self::never())
			->method('find');

		$expected = JsonResponse::fail(null, HttpAlias::STATUS_NOT_FOUND);
		$actual = $this->controller->show($message->getId());

		$this->assertEquals($expected, $actual);
	}

	public function testShowAccountNotFound(): void {
		$message = new LocalMessage();
		$message->setId(1);
		$message->setAccountId(1);

		$this->service->expects(self::once())
			->method('getMessage')
			->with($message->getId(), $this->userId)
			->willReturn($message);
		$this->accountService->expects(self::once())
			->method('find')
			->willThrowException(new ClientException('', 400));

		$expected = JsonResponse::fail('', Http::STATUS_BAD_REQUEST);
		$actual = $this->controller->show($message->getId());

		$this->assertEquals($expected, $actual);
	}

	public function testSend(): void {
		$message = new LocalMessage();
		$message->setId(1);
		$message->setAccountId(1);
		$account = new Account(new MailAccount());

		$this->service->expects(self::once())
			->method('getMessage')
			->with($message->getId(), $this->userId)
			->willReturn($message);
		$this->accountService->expects(self::once())
			->method('find')
			->with($this->userId, $message->getAccountId())
			->willReturn($account);
		$this->service->expects(self::once())
			->method('sendMessage')
			->with($message, $account);

		$expected = JsonResponse::success('Message sent', Http::STATUS_ACCEPTED);
		$actual = $this->controller->send($message->getId());

		$this->assertEquals($expected, $actual);
	}

	public function testSendNoMessage(): void {
		$message = new LocalMessage();
		$message->setId(1);
		$message->setAccountId(1);

		$this->service->expects(self::once())
			->method('getMessage')
			->with($message->getId(), $this->userId)
			->willThrowException(new DoesNotExistException(''));
		$this->accountService->expects(self::never())
			->method('find');
		$this->service->expects(self::never())
			->method('sendMessage');

		$expected = JsonResponse::fail('', Http::STATUS_NOT_FOUND);
		$actual = $this->controller->send($message->getId());

		$this->assertEquals($expected, $actual);
	}

	public function testSendClientException(): void {
		$message = new LocalMessage();
		$message->setId(1);
		$message->setAccountId(1);

		$this->service->expects(self::once())
			->method('getMessage')
			->with($message->getId(), $this->userId)
			->willReturn($message);
		$this->accountService->expects(self::once())
			->method('find')
			->with($this->userId, $message->getAccountId())
			->willThrowException(new ClientException());
		$this->service->expects(self::never())
			->method('sendMessage');

		$this->expectException(ClientException::class);
		$this->controller->send($message->getId());
	}

	public function testSendServiceException(): void {
		$message = new LocalMessage();
		$message->setId(1);
		$message->setAccountId(1);
		$account = new Account(new MailAccount());

		$this->service->expects(self::once())
			->method('getMessage')
			->with($message->getId(), $this->userId)
			->willReturn($message);
		$this->accountService->expects(self::once())
			->method('find')
			->with($this->userId, $message->getAccountId())
			->willReturn($account);
		$this->service->expects(self::once())
			->method('sendMessage')
			->willThrowException(new ServiceException());

		$this->expectException(ServiceException::class);
		$this->controller->send($message->getId());
	}

	public function testDestroy(): void {
		$message = new LocalMessage();
		$message->setId(1);
		$message->setAccountId(1);
		$account = new Account(new MailAccount());

		$this->service->expects(self::once())
			->method('getMessage')
			->with($message->getId(), $this->userId)
			->willReturn($message);
		$this->accountService->expects(self::once())
			->method('find')
			->with($this->userId, $message->getAccountId())
			->willReturn($account);
		$this->service->expects(self::once())
			->method('deleteMessage')
			->with($message);

		$expected = JsonResponse::success('Message deleted', Http::STATUS_ACCEPTED);
		$actual = $this->controller->destroy($message->getId());

		$this->assertEquals($expected, $actual);
	}

	public function testDestroyNoMessage(): void {
		$message = new LocalMessage();
		$message->setId(1);
		$message->setAccountId(1);

		$this->service->expects(self::once())
			->method('getMessage')
			->with($message->getId(), $this->userId)
			->willThrowException(new DoesNotExistException(''));
		$this->accountService->expects(self::never())
			->method('find');
		$this->service->expects(self::never())
			->method('deleteMessage');

		$this->expectException(DoesNotExistException::class);
		$this->controller->destroy($message->getId());
	}

	public function testDestroyServiceException(): void {
		$message = new LocalMessage();
		$message->setId(1);
		$message->setAccountId(1);
		$account = new Account(new MailAccount());

		$this->service->expects(self::once())
			->method('getMessage')
			->with($message->getId(), $this->userId)
			->willReturn($message);
		$this->accountService->expects(self::once())
			->method('find')
			->with($this->userId, $message->getAccountId())
			->willReturn($account);
		$this->service->expects(self::once())
			->method('deleteMessage')
			->willThrowException(new ServiceException());

		$expected = JsonResponse::fail('');
		$actual = $this->controller->destroy($message->getId());

		$this->assertEquals($expected, $actual);
	}

	public function testDestroyClientException(): void {
		$message = new LocalMessage();
		$message->setId(1);
		$message->setAccountId(1);

		$this->service->expects(self::once())
			->method('getMessage')
			->with($message->getId(), $this->userId)
			->willReturn($message);
		$this->accountService->expects(self::once())
			->method('find')
			->with($this->userId, $message->getAccountId())
			->willThrowException(new ClientException());
		$this->service->expects(self::never())
			->method('deleteMessage');

		$expected = JsonResponse::fail('');
		$actual = $this->controller->destroy($message->getId());

		$this->assertEquals($expected, $actual);
	}

	public function testCreate(): void {
		$message = new LocalMessage();
		$message->setAccountId(1);
		$message->setAliasId(2);
		$message->setSubject('subject');
		$message->setBody('message');
		$message->setHtml(true);
		$message->setInReplyToMessageId('abc');
		$message->setType(LocalMessage::TYPE_OUTGOING);
		$to = [['label' => 'Lewis', 'email' => 'tent@stardewvalley.com']];
		$cc = [['label' => 'Pierre', 'email' => 'generalstore@stardewvalley.com']];

		$this->accountService->expects(self::once())
			->method('find')
			->with($this->userId, $message->getAccountId());
		$this->service->expects(self::once())
			->method('saveMessage')
			->with($message, $to, $cc, [], []);

		$expected = JsonResponse::success($message, Http::STATUS_CREATED);
		$actual = $this->controller->create(
			$message->getAccountId(),
			$message->getSubject(),
			$message->getBody(),
			$message->isHtml(),
			$to,
			$cc,
			[],
			[],
			$message->getAliasId(),
			$message->getInReplyToMessageId()
		);

		$this->assertEquals($expected, $actual);
	}

	public function testCreateAccountNotFound(): void {
		$message = new LocalMessage();
		$message->setAccountId(1);
		$message->setAliasId(2);
		$message->setSubject('subject');
		$message->setBody('message');
		$message->setHtml(true);
		$message->setInReplyToMessageId('abc');
		$message->setType(LocalMessage::TYPE_OUTGOING);
		$to = [['label' => 'Lewis', 'email' => 'tent@stardewvalley.com']];
		$cc = [['label' => 'Pierre', 'email' => 'generalstore@stardewvalley.com']];

		$this->accountService->expects(self::once())
			->method('find')
			->with($this->userId, $message->getAccountId())
			->willThrowException(new ClientException());
		$this->service->expects(self::never())
			->method('saveMessage');

		$expected = JsonResponse::fail(null, Http::STATUS_FORBIDDEN);
		$actual = $this->controller->create(
			$message->getAccountId(),
			$message->getSubject(),
			$message->getBody(),
			$message->isHtml(),
			$to,
			$cc,
			[],
			[],
			$message->getAliasId(),
			$message->getInReplyToMessageId()
		);

		$this->assertEquals($expected, $actual);
	}

	public function testCreateServiceException(): void {
		$message = new LocalMessage();
		$message->setAccountId(1);
		$message->setAliasId(2);
		$message->setSubject('subject');
		$message->setBody('message');
		$message->setHtml(true);
		$message->setInReplyToMessageId('abc');
		$message->setType(LocalMessage::TYPE_OUTGOING);
		$to = [['label' => 'Lewis', 'email' => 'tent@stardewvalley.com']];
		$cc = [['label' => 'Pierre', 'email' => 'generalstore@stardewvalley.com']];

		$this->accountService->expects(self::once())
			->method('find')
			->with($this->userId, $message->getAccountId());
		$this->service->expects(self::once())
			->method('saveMessage')
			->willThrowException(new ServiceException(''));

		$this->expectException(ServiceException::class);
		$this->controller->create(
			$message->getAccountId(),
			$message->getSubject(),
			$message->getBody(),
			$message->isHtml(),
			$to,
			$cc,
			[],
			[],
			$message->getAliasId(),
			$message->getInReplyToMessageId()
		);
	}

	public function testUpdate(): void {
		$message = new LocalMessage();
		$message->setId(1);
		$message->setAccountId(1);
		$message->setAliasId(2);
		$message->setSubject('subject');
		$message->setBody('message');
		$message->setHtml(true);
		$message->setInReplyToMessageId('abc');
		$message->setType(LocalMessage::TYPE_OUTGOING);
		$to = [['label' => 'Lewis', 'email' => 'tent@stardewvalley.com']];
		$cc = [['label' => 'Pierre', 'email' => 'generalstore@stardewvalley.com']];

		$this->service->expects(self::once())
			->method('getMessage')
			->with($message->getId(), $this->userId)
			->willReturn($message);
		$this->accountService->expects(self::once())
			->method('find')
			->with($this->userId, $message->getAccountId());
		$this->service->expects(self::once())
			->method('updateMessage')
			->with($message, $to, $cc, [], [])
			->willReturn($message);

		$expected = JsonResponse::success($message, Http::STATUS_ACCEPTED);
		$actual = $this->controller->update(
			$message->getId(),
			$message->getAccountId(),
			$message->getSubject(),
			$message->getBody(),
			$message->isHtml(),
			$to,
			$cc,
			[],
			[],
			$message->getAliasId(),
			$message->getInReplyToMessageId()
		);

		$this->assertEquals($expected, $actual);
	}

	public function testUpdateMessageNotFound(): void {
		$message = new LocalMessage();
		$message->setId(1);
		$message->setAccountId(1);
		$message->setAliasId(2);
		$message->setSubject('subject');
		$message->setBody('message');
		$message->setHtml(true);
		$message->setInReplyToMessageId('abc');
		$message->setType(LocalMessage::TYPE_OUTGOING);
		$to = [['label' => 'Lewis', 'email' => 'tent@stardewvalley.com']];
		$cc = [['label' => 'Pierre', 'email' => 'generalstore@stardewvalley.com']];

		$this->service->expects(self::once())
			->method('getMessage')
			->with($message->getId(), $this->userId)
			->willThrowException(new DoesNotExistException(''));
		$this->accountService->expects(self::never())
			->method('find');
		$this->service->expects(self::never())
			->method('updateMessage');

		$expected = JsonResponse::fail('', Http::STATUS_NOT_FOUND);
		$actual = $this->controller->update(
			$message->getId(),
			$message->getAccountId(),
			$message->getSubject(),
			$message->getBody(),
			$message->isHtml(),
			$to,
			$cc,
			[],
			[],
			$message->getAliasId(),
			$message->getInReplyToMessageId()
		);

		$this->assertEquals($expected, $actual);
	}

	public function testUpdateClientException(): void {
		$message = new LocalMessage();
		$message->setId(1);
		$message->setAccountId(1);
		$message->setAliasId(2);
		$message->setSubject('subject');
		$message->setBody('message');
		$message->setHtml(true);
		$message->setInReplyToMessageId('abc');
		$message->setType(LocalMessage::TYPE_OUTGOING);
		$to = [['label' => 'Lewis', 'email' => 'tent@stardewvalley.com']];
		$cc = [['label' => 'Pierre', 'email' => 'generalstore@stardewvalley.com']];

		$this->service->expects(self::once())
			->method('getMessage')
			->with($message->getId(), $this->userId)
			->willReturn($message);
		$this->accountService->expects(self::once())
			->method('find')
			->with($this->userId, $message->getAccountId())
			->willThrowException(new ClientException());
		$this->service->expects(self::never())
			->method('updateMessage');

		$this->expectException(ClientException::class);
		$this->controller->update(
			$message->getId(),
			$message->getAccountId(),
			$message->getSubject(),
			$message->getBody(),
			$message->isHtml(),
			$to,
			$cc,
			[],
			[],
			$message->getAliasId(),
			$message->getInReplyToMessageId()
		);
	}

	public function testUpdateServiceException(): void {
		$message = new LocalMessage();
		$message->setId(1);
		$message->setAccountId(1);
		$message->setAliasId(2);
		$message->setSubject('subject');
		$message->setBody('message');
		$message->setHtml(true);
		$message->setInReplyToMessageId('abc');
		$message->setType(LocalMessage::TYPE_OUTGOING);
		$to = [['label' => 'Lewis', 'email' => 'tent@stardewvalley.com']];
		$cc = [['label' => 'Pierre', 'email' => 'generalstore@stardewvalley.com']];

		$this->service->expects(self::once())
			->method('getMessage')
			->with($message->getId(), $this->userId)
			->willReturn($message);
		$this->accountService->expects(self::once())
			->method('find')
			->with($this->userId, $message->getAccountId());
		$this->service->expects(self::once())
			->method('updateMessage')
			->with($message, $to, $cc, [], [])
			->willThrowException(new ServiceException(''));

		$this->expectException(ServiceException::class);
		$this->controller->update(
			$message->getId(),
			$message->getAccountId(),
			$message->getSubject(),
			$message->getBody(),
			$message->isHtml(),
			$to,
			$cc,
			[],
			[],
			$message->getAliasId(),
			$message->getInReplyToMessageId()
		);
	}
}

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

namespace OCA\Mail\Controller;

use OCA\Mail\Db\LocalMessage;
use OCA\Mail\Exception\ClientException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Http\JsonResponse;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\OutboxService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;

class OutboxController extends Controller {

	/** @var OutboxService */
	private $service;

	/** @var string */
	private $userId;

	/** @var AccountService */
	private $accountService;

	public function __construct(string $appName,
								$UserId,
								IRequest $request,
								OutboxService $service,
	AccountService $accountService) {
		parent::__construct($appName, $request);
		$this->userId = $UserId;
		$this->service = $service;
		$this->accountService = $accountService;
	}

	/**
	 * @NoAdminRequired
	 * @TrapError
	 *
	 * @return JsonResponse
	 */
	public function index(): JsonResponse {
		return JsonResponse::success(['messages' => $this->service->getMessages($this->userId)]);
	}

	/**
	 * @NoAdminRequired
	 * @TrapError
	 *
	 * @param int $id
	 * @return JsonResponse
	 */
	public function show(int $id): JsonResponse {
		try {
			$message = $this->service->getMessage($id, $this->userId);
			$this->accountService->find($this->userId, $message->getAccountId());
			return JsonResponse::success($message);
		} catch (ClientException $e) {
			return JsonResponse::fail($e->getMessage(), $e->getHttpCode());
		} catch (DoesNotExistException $e) {
			return JsonResponse::fail(null, Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * @NoAdminRequired
	 * @TrapError
	 *
	 * @param int $accountId
	 * @param string $subject
	 * @param string $body
	 * @param bool $isHtml
	 * @param array $to i. e. [['label' => 'Lewis', 'email' => 'tent@stardewvalley.com'], ['label' => 'Pierre', 'email' => 'generalstore@stardewvalley.com']]
	 * @param array $cc
	 * @param array $bcc
	 * @param array $attachmentIds
	 * @param int|null $aliasId
	 * @param string|null $inReplyToMessageId
	 * @return JsonResponse
	 */
	public function create(
		int $accountId,
		string $subject,
		string $body,
		bool $isHtml,
		array $to = [],
		array $cc = [],
		array $bcc = [],
		array $attachmentIds = [],
		?int $aliasId = null,
		?string $inReplyToMessageId = null
	): JsonResponse {
		try {
			$this->accountService->find($this->userId, $accountId);
		} catch (ClientException $e) {
			return JsonResponse::fail(null, Http::STATUS_FORBIDDEN);
		}

		$message = new LocalMessage();
		$message->setType(LocalMessage::TYPE_OUTGOING);
		$message->setAccountId($accountId);
		$message->setAliasId($aliasId);
		$message->setSubject($subject);
		$message->setBody($body);
		$message->setHtml($isHtml);
		$message->setInReplyToMessageId($inReplyToMessageId);

		$this->service->saveMessage($message, $to, $cc, $bcc, $attachmentIds);

		return JsonResponse::success($message, Http::STATUS_CREATED);
	}

	/**
	 * @NoAdminRequired
	 * @TrapError
	 *
	 * @param int $id
	 * @param int $accountId
	 * @param string $subject
	 * @param string $body
	 * @param bool $isHtml
	 * @param array $to i. e. [['label' => 'Lewis', 'email' => 'tent@stardewvalley.com'], ['label' => 'Pierre', 'email' => 'generalstore@stardewvalley.com']]
	 * @param array $cc
	 * @param array $bcc
	 * @param array $attachmentIds
	 * @param int|null $aliasId
	 * @param string|null $inReplyToMessageId
	 * @return JsonResponse
	 */
	public function update(int $id,
						   int $accountId,
						   string $subject,
						   string $body,
						   bool $isHtml,
						   array $to = [],
						   array $cc = [],
						   array $bcc = [],
						   array $attachmentIds = [],
						   ?int $aliasId = null,
						   ?string $inReplyToMessageId = null): JsonResponse {
		try {
			$message = $this->service->getMessage($id, $this->userId);
			$this->accountService->find($this->userId, $message->getAccountId());
		} catch (DoesNotExistException $e) {
			return JsonResponse::fail('', Http::STATUS_NOT_FOUND);
		}

		$message->setAccountId($accountId);
		$message->setSubject($subject);
		$message->setBody($body);
		$message->setHtml($isHtml);
		$message->setAliasId($aliasId);
		$message->setInReplyToMessageId($inReplyToMessageId);

		$message = $this->service->updateMessage($message, $to, $cc, $bcc, $attachmentIds);

		return JsonResponse::success($message, Http::STATUS_ACCEPTED);
	}

	/**
	 * @NoAdminRequired
	 * @TrapError
	 *
	 * @param int $id
	 * @return JsonResponse
	 */
	public function send(int $id): JsonResponse {
		try {
			$message = $this->service->getMessage($id, $this->userId);
			$account = $this->accountService->find($this->userId, $message->getAccountId());
			$this->service->sendMessage($message, $account);
		} catch (DoesNotExistException $e) {
			return JsonResponse::fail($e->getMessage(), Http::STATUS_NOT_FOUND);
		}
		return  JsonResponse::success(
			'Message sent', Http::STATUS_ACCEPTED
		);
	}

	/**
	 * @NoAdminRequired
	 * @TrapError
	 *
	 * @param int $id
	 * @return JsonResponse
	 */
	public function destroy(int $id): JsonResponse {
		try {
			$message = $this->service->getMessage($id, $this->userId);
			$this->accountService->find($this->userId, $message->getAccountId());
			$this->service->deleteMessage($message);
		} catch (ServiceException | ClientException $e) {
			return JsonResponse::fail($e->getMessage());
		}
		return JsonResponse::success('Message deleted', Http::STATUS_ACCEPTED);
	}
}

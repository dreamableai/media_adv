<?php

declare(strict_types=1);

/**
 * @copyright 2021 Andrey Borysenko <andrey18106x@gmail.com>
 * @copyright 2021 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2021 Andrey Borysenko <andrey18106x@gmail.com>
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

namespace OCA\MediaDC\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;


/**
 * Class CollectorResult
 *
 * @package OCA\MediaDC\Db
 *
 * @method int getTaskId()
 * @method string getGroupFilesIds()
 * @method void setTaskId(string $taskId)
 * @method void setGroupFilesIds(string $groupFilesIds)
 */
class CollectorTaskDetail extends Entity implements JsonSerializable {

	protected $taskId;
	protected $groupFilesIds;

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		if (isset($params['id'])) {
			$this->setId($params['id']);
		}
		if (isset($params['taskId'])) {
			$this->setTaskId($params['taskId']);
		}
		if (isset($params['groupFilesIds'])) {
			$this->setGroupFilesIds($params['groupFilesIds']);
		}
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->getId(),
			'task_id' => $this->getTaskId(),
			'group_file_ids' => $this->getGroupFilesIds(),
		];
	}

}

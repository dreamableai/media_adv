<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2021-2022 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2021-2022 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2021-2022 Andrey Borysenko <andrey18106x@gmail.com>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#index', 'url' => '/tasks/{taskId}', 'verb' => 'GET', 'postfix' => 'collector'],
		['name' => 'page#index', 'url' => '/configuration', 'verb' => 'GET', 'postfix' => 'configuration'],
		['name' => 'page#index', 'url' => '/resolved', 'verb' => 'GET', 'postfix' => 'resolved'],

		// SETTINGS API
		['name' => 'settings#index', 'url' => '/api/v1/settings', 'verb' => 'GET'],
		['name' => 'settings#update', 'url' => '/api/v1/settings', 'verb' => 'PUT'],
		['name' => 'settings#getSettingById', 'url' => '/api/v1/settings/{id}', 'verb' => 'GET'],
		['name' => 'settings#getSettingByName', 'url' => '/api/v1/settings/name/{name}', 'verb' => 'GET'],
		['name' => 'settings#updateSetting', 'url' => '/api/v1/settings/name/{name}', 'verb' => 'PUT'],
		['name' => 'settings#truncate', 'url' => '/api/v1/settings/truncate/{name}', 'verb' => 'POST'],
		['name' => 'settings#systemInfo', 'url' => '/api/v1/system-info', 'verb' => 'GET'],

		// COLLECTOR API
		['name' => 'collector#index', 'url' => '/api/v1/tasks/', 'verb' => 'GET'],
		['name' => 'collector#details', 'url' => '/api/v1/tasks/{taskId}', 'verb' => 'GET'],
		['name' => 'collector#getTaskInfo', 'url' => '/api/v1/tasks/{taskId}/info', 'verb' => 'GET'],
		['name' => 'collector#getTaskResultsExport', 'url' => '/api/v1/tasks/{taskId}/export/{format}', 'verb' => 'GET'],
		['name' => 'collector#deleteTaskDetail', 'url' => '/api/v1/tasks/{taskId}/detail/{groupId}', 'verb' => 'DELETE'],
		['name' => 'collector#getDetailGroupFilesInfo', 'url' => '/api/v1/tasks/{taskId}/files/{groupId}/all', 'verb' => 'GET'],
		['name' => 'collector#getDetailFilesTotalSize', 'url' => '/api/v1/tasks/{taskId}/filestotal', 'verb' => 'GET'],
		['name' => 'collector#deleteTaskDetailFile', 'url' => '/api/v1/tasks/{taskId}/files/{groupId}/{fileId}', 'verb' => 'DELETE'],
		['name' => 'collector#deleteTask', 'url' => '/api/v1/tasks/{taskId}', 'verb' => 'DELETE'],
		['name' => 'collector#terminateTask', 'url' => '/api/v1/tasks/{taskId}/terminate', 'verb' => 'POST'],
		['name' => 'collector#duplicateTask', 'url' => '/api/v1/tasks/{taskId}/duplicate', 'verb' => 'POST'],
		['name' => 'collector#runTask', 'url' => '/api/v1/tasks/run', 'verb' => 'POST'],
		['name' => 'collector#restartTask', 'url' => '/api/v1/tasks/restart', 'verb' => 'POST'],

		// COLLECTOR RESOLVED API
		['name' => 'collector#resolved', 'url' => '/api/v1/resolved', 'verb' => 'GET'],
		['name' => 'collector#markResolved', 'url' => '/api/v1/resolved/mark/{fileId}', 'verb' => 'POST'],
		['name' => 'collector#cleanupResolved', 'url' => '/api/v1/resolved/{type}/cleanup', 'verb' => 'POST'],

		// BATCH ACTIONS API
		['name' => 'collector#removeTaskDetailGroups', 'url' => '/api/v1/tasks/{taskId}/details/remove', 'verb' => 'POST'],
		['name' => 'collector#deleteTaskDetailGroupsFiles', 'url' => '/api/v1/tasks/{taskId}/details/delete', 'verb' => 'POST'],
		['name' => 'collector#removeTaskDetailFiles', 'url' => '/api/v1/tasks/{taskId}/files/{groupId}/remove', 'verb' => 'POST'],
		['name' => 'collector#deleteTaskDetailFiles', 'url' => '/api/v1/tasks/{taskId}/files/{groupId}/delete', 'verb' => 'POST'],
	]
];

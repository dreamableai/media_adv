<?php

declare(strict_types=1);

/**
 * @copyright Сopyright (c) 2021-2022 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Сopyright (c) 2021-2022 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2021-2022 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @license AGPL-3.0-or-later
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
 *
 */

namespace OCA\MediaDC\Service;

use OCP\Files\File;
use OCP\Files\Node;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Log\LoggerInterface;
use OCP\BackgroundJob\IJobList;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IPreview;

use OCA\MediaDC\Db\Setting;
use OCA\MediaDC\Db\SettingMapper;
use OCA\MediaDC\Db\CollectorTask;
use OCA\MediaDC\Db\CollectorTaskMapper;
use OCA\MediaDC\Db\CollectorTaskDetail;
use OCA\MediaDC\Db\CollectorTaskDetailMapper;
use OCA\MediaDC\Service\PythonService;
use OCA\MediaDC\BackgroundJob\QueuedTaskJob;
use OCP\Lock\LockedException;


class CollectorService
{

	/** @var string */
	private $userId;

	/** @var Folder */
	private $userFolder;

	/** @var SettingMapper */
	private $settingsMapper;

	/** @var CollectorTaskMapper */
	private $tasksMapper;

	/** @var CollectorTaskDetailMapper */
	private $tasksDetailsMapper;

	/** @var PythonService */
	private $pythonService;

	/** @var UtilsService */
	private $utils;

	/** @var PhotosService */
	private $photosService;

	/** @var VideosService */
	private $videosService;

	/** @var LoggerInterface */
	private $logger;

	/** @var IJobList */
	private $jobList;

	/** @var IPreview */
	private $previewManager;

	const TARGET_MIME_TYPE = [
		0 => ['image'],
		1 => ['video'],
		2 => ['image', 'video'],
	];

	const TASK_TYPE_MANUAL = 'manual';
	const TASK_TYPE_AUTO = 'auto';
	const TASK_TYPE_QUEUED = 'queued';


	public function __construct(
		?string $userId,
		IRootFolder $rootFolder,
		SettingMapper $settingsMapper,
		CollectorTaskMapper $tasksMapper,
		CollectorTaskDetailMapper $tasksDetailsMapper,
		PythonService $pythonService,
		LoggerInterface $logger,
		PhotosService $photosService,
		VideosService $videosService,
		IJobList $jobList,
		IPreview $previewManager,
		UtilsService $utils
	) {
		if ($userId !== null) {
			$this->userId = $userId;
			$this->userFolder = $rootFolder->getUserFolder($this->userId);
		}
		$this->settingsMapper = $settingsMapper;
		$this->tasksMapper = $tasksMapper;
		$this->tasksDetailsMapper = $tasksDetailsMapper;
		$this->utils = $utils;
		$this->pythonService = $pythonService;
		$this->logger = $logger;
		$this->photosService = $photosService;
		$this->videosService = $videosService;
		$this->jobList = $jobList;
		$this->previewManager = $previewManager;
	}

	/**
	 * Run background Python script for collecting duplicates.
	 *
	 * @param array $params task params
	 *
	 * @return array created task start result (queued or started)
	 */
	public function runTask(array $params = []): array
	{
		/** @var Setting */
		$pyLimitSetting = $this->settingsMapper->findByName('python_limit');
		$processesRunning = count($this->tasksMapper->findAllRunning());
		// $queuedTask = null;

		if ($pyLimitSetting !== null && $processesRunning < (int)$pyLimitSetting->getValue()) {
			$createdTask = $this->createCollectorTask($params);
			if ($createdTask !== null) {
				$this->pythonService->run('/main.py', ['-t' => $createdTask->getId()], true, ['PHP_PATH' => $this->utils->getPhpInterpreter()]);
			} else {
				return ['success' => $createdTask !== null, 'empty' => true];
			}
		} else {
			return ['success' => false, 'limit' => true];
			// Add as Queued job
			// TODO: Add queued mechanism
			// $queuedTask = $this->createQueuedTask($params);
		}

		// return ['success' => $createdTask !== null, 'queued' => $queuedTask !== null];
		return ['success' => $createdTask !== null, 'limit' => false];
	}

	/**
	 * Restart existing Collector Task
	 *
	 * @param array $params
	 *
	 * @return array task restart result (queued or started)
	 */
	public function restartTask(array $params = [])
	{
		if (isset($params['taskId'])) {
			$taskId = $params['taskId'];
		}
		if (isset($params['targetDirectoryIds'])) {
			$targetDirectoryIds = $params['targetDirectoryIds'];
		}
		if (isset($params['excludeList'])) {
			$excludeList = $params['excludeList'];
		}
		if (isset($params['collectorSettings'])) {
			$collectorSettings = $params['collectorSettings'];
		}

		/** @var Setting */
		$pyLimitSetting = $this->settingsMapper->findByName('python_limit');
		$processesRunning = $this->tasksMapper->findAllRunning();
		$taskIdsRunning = array_map(function ($task) {
			return $task->getId();
		}, $processesRunning);
		/** @var CollectorTask */
		$collectorTask = $this->tasksMapper->find($taskId);
		$taskData = $this->getTargetDirectoriesData($params['targetDirectoryIds'], intval($params['collectorSettings']['target_mtype']), $excludeList);
		$empty = false;
		$queuedTask = null;
		$this->terminate($taskId);

		if ($taskData['files_total'] > 0) {
			$collectorTask->setTargetDirectoryIds(json_encode($targetDirectoryIds));
			$collectorTask->setExcludeList(json_encode($excludeList));
			$collectorTask->setCollectorSettings(json_encode($collectorSettings));
			$collectorTask->setFilesScanned(0);
			$collectorTask->setFilesTotal($taskData['files_total']);
			$collectorTask->setFilesTotalSize($taskData['files_total_size']);
			$collectorTask->setCreatedTime(time());
			$collectorTask->setUpdatedTime(0);
			$collectorTask->setFinishedTime(0);
			$collectorTask->setDeletedFilesCount(0);
			$collectorTask->setDeletedFilesSize(0);
			$collectorTask->setErrors('');
		} else {
			$empty = true;
		}

		if (!in_array($taskId, $taskIdsRunning)) {
			if ($pyLimitSetting !== null && count($processesRunning) < (int)$pyLimitSetting->getValue() && !$empty) {
				$collectorTask = $this->tasksMapper->update($collectorTask);
				$this->deleteTaskDetails($taskId);
				$this->pythonService->run('/main.py', ['-t' => $taskId], true, ['PHP_PATH' => $this->utils->getPhpInterpreter()]);
			} else if ($empty) {
				return ['success' => false, 'empty' => $empty];
			} else {
				// Add as Queued job
				// $queuedTask = $this->createQueuedTask($params);
				return ['success' => false, 'limit' => true, 'empty' => $empty];
			}
		} else {
			$this->tasksMapper->update($collectorTask);
			$this->deleteTaskDetails($taskId);
			$this->pythonService->run('/main.py', ['-t' => $taskId], true, ['PHP_PATH' => $this->utils->getPhpInterpreter()]);
		}

		return [
			'success' => $collectorTask !== null,
			'queued' => $queuedTask !== null,
			'empty' => $empty,
			'restartedTask' => $collectorTask,
		];
	}

	/**
	 * Terminate CollectorTask background Python process
	 *
	 * @param int $taskId
	 *
	 * @return \OCA\MediaDC\Db\CollectorTask terminated CollectorTask
	 */
	public function terminate($taskId): CollectorTask
	{
		/** @var CollectorTask */
		$collectorTask = $this->tasksMapper->find($taskId);
		if (intval($collectorTask->getPyPid()) !== 0 && $taskId === $collectorTask->getId()) {
			exec("kill " . intval($collectorTask->getPyPid()), $output, $result_code);
			if ($result_code === 0) {
				$this->logger->info("CollectorTask terminated.\n" . json_encode($collectorTask->jsonSerialize()));
				$collectorTask->setPyPid(0);
			} else {
				$this->logger->error("Can't terminate CollectorTask background process.\n" . json_encode($collectorTask->jsonSerialize()));
			}
		}
		return $collectorTask;
	}

	/**
	 * Duplicate CollectorTask
	 *
	 * @param int $taskId
	 *
	 * @return \OCA\MediaDC\Db\CollectorTask|null duplicated CollectorTask
	 */
	public function duplicate($taskId): ?CollectorTask
	{
		/** @var CollectorTask */
		$collectorTask = $this->tasksMapper->find($taskId);
		$collectorSettings = json_decode($collectorTask->getCollectorSettings(), true);
		$duplicatedCollectorTask = $this->createCollectorTask([
			'type' => 'duplicated',
			'targetDirectoryIds' => json_decode($collectorTask->getTargetDirectoryIds()),
			'collectorSettings' => [
				'hashing_algorithm' => $collectorSettings['hashing_algorithm'],
				'similarity_threshold' => $collectorSettings['similarity_threshold'],
				'hash_size' => $collectorSettings['hash_size'],
				'target_mtype' => $collectorSettings['target_mtype'],
				'finish_notification' => $collectorSettings['finish_notification'],
			],
			'excludeList' => json_decode($collectorTask->getExcludeList(), true),
		]);
		return $duplicatedCollectorTask;
	}

	/**
	 * @param array $params task params
	 * @param bool $queued queued task flag (task type)
	 *
	 * @return \OCA\MediaDC\Db\CollectorTask|null created Collector Task
	 */
	public function createCollectorTask(array $params = [], bool $queued = false): ?CollectorTask
	{
		if (count($params) === 0) {
			/** @var Setting */
			$pyAlgorithmSetting = $this->settingsMapper->findByName('hashing_algorithm');
			/** @var Setting */
			$pyThresholdSetting = $this->settingsMapper->findByName('similarity_threshold');
			/** @var Setting */
			$pyHashSizeSetting = $this->settingsMapper->findByName('hash_size');
		} else {
			/** @var string */
			$pyAlgorithmSetting = $params['collectorSettings']['hashing_algorithm'];
			/** @var string */
			$pyThresholdSetting = $params['collectorSettings']['similarity_threshold'];
			/** @var string */
			$pyHashSizeSetting = $params['collectorSettings']['hash_size'];
			/** @var Setting */
			$excludeListSetting = $this->settingsMapper->findByName('exclude_list');
			$excludeList = count($params) === 0 ? [
				'admin' => $excludeListSetting->getValue(),
				'user' => [
					'mask' => [],
					'fileid' => [],
				],
			] : $params['excludeList'];
			$taskData = $this->getTargetDirectoriesData($params['targetDirectoryIds'], intval($params['collectorSettings']['target_mtype']), $excludeList);
		}

		$task = new CollectorTask([
			'owner' => $this->userId,
			'type' => $queued ? self::TASK_TYPE_QUEUED : self::TASK_TYPE_MANUAL,
			'targetDirectoryIds' => count($params) === 0 ? json_encode([$this->userFolder->getId()]) : json_encode($params['targetDirectoryIds']),
			'excludeList' => json_encode($excludeList),
			'collectorSettings' => json_encode([
				'hashing_algorithm' => count($params) === 0 ? $pyAlgorithmSetting->getValue() : $pyAlgorithmSetting,
				'similarity_threshold' => count($params) === 0 ? $pyThresholdSetting->getValue() : intval($pyThresholdSetting),
				'hash_size' => count($params) === 0 ? $pyHashSizeSetting->getValue() : intval($pyHashSizeSetting),
				'target_mtype' => count($params) === 0 ? 0 : intval($params['collectorSettings']['target_mtype']),
				'finish_notification' => count($params) === 0 ? true : $params['collectorSettings']['finish_notification'],
				'duplicated' => isset($params['type']) && $params['type'] === 'duplicated',
			]),
			'filesScanned' => 0,
			'filesTotal' => count($params) === 0
				? $this->getTargetFolderFilesCount(
					$this->userFolder,
					$this->isShared($this->userFolder),
					count($params) === 0 ? self::TARGET_MIME_TYPE[0]
						: $params['collectorSettings']['target_mtype'],
					$excludeList
				)
				: $taskData['files_total'],
			'filesTotalSize' => count($params) === 0 ? $this->userFolder->getSize() : $taskData['files_total_size'],
			'deletedFilesCount' => 0,
			'deletedFilesSize' => 0,
			'createdTime' => time(),
			'finishedTime' => 0,
			'pyPid' => 0,
			'errors' => ''
		]);

		if ($task->getFilesTotal() > 0) {
			return $this->tasksMapper->insert($task);
		} else {
			return null;
		}
	}

	/**
	 * Create task and add it to the queued jobs list
	 *
	 * @param array $params task params
	 *
	 * @return \OCA\MediaDC\Db\CollectorTask|null created queued Collector task
	 */
	public function createQueuedTask(array $params = []): ?CollectorTask
	{
		$createdTask = $this->createCollectorTask($params, true);
		if ($createdTask !== null) {
			$this->jobList->add(QueuedTaskJob::class, [
				'taskId' => $createdTask->getId(),
				'targetDirectoryIds' => $createdTask->getTargetDirectoryIds(),
				'excludeList' => $createdTask->getExcludeList(),
				'collectorSettings' => $createdTask->getCollectorSettings()
			]);
		}
		return $createdTask;
	}

	/**
	 * Clean up Collector job (remove deleted photos&vidoes hashes from database)
	 *
	 * @return array Collector cleanup job results
	 */
	public function cleanup(): array
	{
		$this->logger->info('[' . self::class . '] cleanup job executed.');
		$photos = $this->photosService->getAllFileids();
		$photosDeleted = 0;
		foreach ($photos as $photo) {
			if ($this->photosService->canBeDeleted($photo->getFileid())) {
				$this->photosService->delete($photo);
				$photosDeleted += 1;
			}
		}
		$videosDeleted = 0;
		$videos = $this->videosService->getAllFileids();
		foreach ($videos as $video) {
			if ($this->videosService->canBeDeleted($video->getFileid())) {
				$this->videosService->delete($video);
				$videosDeleted += 1;
			}
		}
		$result = [
			'photosDeleted' => $photosDeleted,
			'videosDeleted' => $videosDeleted
		];
		$this->logger->info('[' . self::class . '] cleanup job finished. Results: ' . json_encode($result));
		return $result;
	}

	/**
	 * @param int $taskId
	 *
	 * @return \OCA\MediaDC\Db\CollectorTask[]|array
	 */
	public function getCollectorTask(int $taskId)
	{
		try {
			return $this->tasksMapper->find($taskId);
		} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
			return [
				'success' => false,
				'message' => 'Not found'
			];
		}
	}

	/**
	 * Returns current user's tasks
	 *
	 * @return \OCA\MediaDC\Db\CollectorTask[]
	 */
	public function getUserCollectorTasks(): array
	{
		return $this->tasksMapper->findAllByOwner($this->userId);
	}

	/**
	 * Returns current user's recent tasks
	 *
	 * @param int $limit
	 * @param int $offset
	 *
	 * @return array
	 */
	public function getUserRecentTasks(int $limit = null, int $offset = null): array
	{
		return $this->tasksMapper->findRecentByOwner($this->userId, $limit, $offset);
	}

	/**
	 * @param int $taskId
	 *
	 * @return \OCA\MediaDC\Db\CollectorTask deleted task
	 */
	public function delete(int $taskId): CollectorTask
	{
		/** @var CollectorTask */
		$collectorTask = $this->tasksMapper->find($taskId);
		$this->terminate($taskId);
		$this->deleteTaskDetails($taskId);
		return $this->tasksMapper->delete($collectorTask);
	}

	/**
	 * @param int $taskDetailId
	 *
	 * @return \OCA\MediaDC\Db\CollectorTaskDetail deleted task detail
	 */
	public function deleteTaskDetail(int $taskDetailId): CollectorTaskDetail
	{
		$taskDetail = $this->tasksDetailsMapper->find($taskDetailId);
		$taskDetailGroupFileIds = json_decode($taskDetail->getGroupFilesIds());
		foreach ($taskDetailGroupFileIds as $fileId) {
			$this->markResolvedPhoto($fileId, true);
			$this->markResolvedVideo($fileId, true);
		}
		return $this->tasksDetailsMapper->delete($taskDetail);
	}

	/**
	 * @param int $taskId
	 *
	 * @return void
	 */
	public function deleteTaskDetails(int $taskId): void
	{
		$this->tasksDetailsMapper->deleteAllByTaskId($taskId);
	}

	/**
	 * Returns basic info about target directories
	 *
	 * @param \OCA\MediaDC\Db\CollectorTask $task
	 *
	 * @return array target directories info
	 */
	public function getTaskInfo(CollectorTask $task): array
	{
		$targetDirectories = [];
		$excludeDirectories = [];
		$targetDirectoryIds = json_decode($task->getTargetDirectoryIds());
		$taskSettings = json_decode($task->getCollectorSettings(), true);
		$excludeList = json_decode($task->getExcludeList(), true);
		foreach ($targetDirectoryIds as $targetDirectoryId) {
			$nodes = $this->userFolder->getById($targetDirectoryId);
			if (count($nodes) === 1 && $nodes[0] instanceof Folder) {
				/** @var Folder */
				$directory = $nodes[0];
				array_push($targetDirectories, [
					'fileid' => $directory->getId(),
					'filename' => $directory->getName(),
					'filesize' => (!$this->hasIgnoreFlag($directory)) ? $this->getTargetFolderFilesSize($directory, $taskSettings['target_mtype'], $excludeList) : 0,
					'fileowner' => $directory->getOwner()->getUID(),
					'filepath' => $directory->getPath(),
					'filerelpath' => $directory->getInternalPath(),
					'hasignoreflag' => $this->hasIgnoreFlag($directory),
				]);
			}
		}
		foreach ($excludeList['user']['fileid'] as $excludeFileId) {
			$nodes = $this->userFolder->getById($excludeFileId);
			if (count($nodes) === 1 && $nodes[0] instanceof Folder) {
				/** @var Folder */
				$directory = $nodes[0];
				array_push($excludeDirectories, [
					'fileid' => $directory->getId(),
					'filename' => $directory->getName(),
					'fileowner' => $directory->getOwner()->getUID(),
					'filepath' => $directory->getPath(),
				]);
			}
		}
		return [
			'target_directories' => $targetDirectories,
			'exclude_directories' => $excludeDirectories,
		];
	}

	/**
	 * @param int $taskDetailId
	 * @param bool $filesizeAscending
	 *
	 * @return array $filesInfo
	 */
	public function getDetailGroupFilesInfo(int $taskDetailId, bool $filesizeAscending)
	{
		try {
			/** @var CollectorTaskDetail */
			$collectorTaskDetail = $this->tasksDetailsMapper->find($taskDetailId);
			$filesInfo = [];
			$filesTotalSize = 0;
			$groupFilesIds = json_decode($collectorTaskDetail->getGroupFilesIds());
			foreach ($groupFilesIds as $groupFileId) {
				/** @var File $node */
				foreach ($this->userFolder->getById($groupFileId) as $node) {
					if ($node instanceof File) {
						array_push($filesInfo, [
							'fileid' => $node->getId(),
							'fileowner' => $node->getOwner()->getUID(),
							'fileetag' => $node->getEtag(),
							'filename' => $node->getName(),
							'filemtype' => $node->getMimeType(),
							'filempart' => $node->getMimePart(),
							'relfilepath' => $node->getInternalPath(),
							'filepath' => $node->getPath(),
							'filesize' => $node->getSize(),
							'has_preview' => $this->previewManager->isAvailable($node),
						]);
						$filesTotalSize += $node->getSize();
					}
				}
				// Sort page files by filesize (ascending/descending)
				usort(
					$filesInfo,
					($filesizeAscending) ?
						function (array $file_x, array $file_y) {
							return $file_x['filesize'] - $file_y['filesize'];
						}
						: function (array $file_x, array $file_y) {
							return $file_y['filesize'] - $file_x['filesize'];
						}
				);
			}
			return [
				'files' => $filesInfo,
				'filessize' => $filesTotalSize,
			];
		} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
			$this->logger->error("Can't find file(s) of CollectorTaskDetail (\$id = " . $taskDetailId .  ")\n");
			return [
				'success' => false,
				'message' => 'Not found files info'
			];
		}
	}

	/**
	 * @param int $taskId
	 *
	 * @return array filessize and filestotal
	 */
	public function getDetailFilesTotalSize(int $taskId)
	{
		$size = 0;
		$count = 0;
		$taskDetails = $this->tasksDetailsMapper->findAllById($taskId);
		foreach ($taskDetails as $taskDetail) {
			$groupFileIds = json_decode($taskDetail->getGroupFilesIds());
			foreach ($groupFileIds as $groupFileId) {
				$nodes = $this->userFolder->getById($groupFileId);
				if (count($nodes) === 1) {
					$size += $nodes[0]->getSize();
					$count += 1;
				}
			}
		}
		return [
			'filessize' => $size,
			'filestotal' => $count,
		];
	}

	/**
	 * Removes Collector Task Detail group file
	 *
	 * @param int $taskId
	 * @param int $taskDetailId
	 * @param int $fileid
	 * @param bool $removeIfOneLeft
	 *
	 * @return array $result
	 */
	public function deleteTaskDetailFile($taskId, $taskDetailId, $fileid, $removeIfOneLeft = true)
	{
		/** @var CollectorTask */
		$collectorTask = $this->tasksMapper->find($taskId);
		$deletedFilesCount = $collectorTask->getDeletedFilesCount();
		$deletedFilesSize = $collectorTask->getDeletedFilesSize();
		/** @var CollectorTaskDetail */
		$collectorTaskDetail = $this->tasksDetailsMapper->find($taskDetailId);

		$groupFiles = json_decode($collectorTaskDetail->getGroupFilesIds());
		$fileidIndex = array_search($fileid, $groupFiles);
		if ($fileidIndex !== false) {
			array_splice($groupFiles, $fileidIndex, 1);
		}

		$nodes = $this->userFolder->getById($fileid);

		if (count($nodes) === 1) {
			/** @var File */
			$file = $nodes[0];
			try {
				$filesize = $file->getSize();
				$file->delete();
				$collectorTask->setDeletedFilesCount($deletedFilesCount + 1);
				$collectorTask->setDeletedFilesSize($deletedFilesSize + $filesize);
				$this->tasksMapper->update($collectorTask);
				if (count($groupFiles) === 1 && $removeIfOneLeft) {
					$this->tasksDetailsMapper->delete($collectorTaskDetail);
					$updatedTaskDetail = null;
				} else {
					$collectorTaskDetail->setGroupFilesIds(json_encode($groupFiles));
					$updatedTaskDetail = $this->tasksDetailsMapper->update($collectorTaskDetail);
				}
				return [
					'success' => true,
					'task' => $collectorTask,
					'taskDetail' => $updatedTaskDetail,
					'fileid' => $fileid,
					'filesize' => $filesize,
				];
			} catch (LockedException $e) {
				return [
					'success' => false,
					'locked' => true
				];
			} catch (NotPermittedException | NotFoundException $e) {
				return [
					'success' => false,
					'not_permited' => $e instanceof NotPermittedException,
					'not_found' => $e instanceof NotFoundException,
				];
			}
		} else {
			return [
				'success' => false,
			];
		}
	}

	/**
	 * Remove ColectorTaskDetail groups with deleting coresponding files
	 *
	 * @param array $taskDetailIds
	 *
	 * @return array $result
	 */
	public function removeTaskDetailGroups(array $taskDetailIds)
	{
		$result = [];
		foreach ($taskDetailIds as $taskDetailId) {
			array_push($result, $this->deleteTaskDetail($taskDetailId));
		}
		return [
			'success' => count($result) === count($taskDetailIds),
			'removedTaskDetails' => $result
		];
	}

	/**
	 * Delete ColectorTaskDetail groups with deleting coresponding files
	 *
	 * @param int $taskDetailId
	 * @param array $fileIds
	 *
	 * @return array $result
	 */
	public function deleteTaskDetailFiles(int $taskDetailId, array $fileIds)
	{
		$result = [];
		$errors = [
			'locked' => [],
			'not_permited' => [],
			'not_found' => [],
		];
		/** @var CollectorTaskDetail $taskDetail */
		$taskDetail = $this->tasksDetailsMapper->find($taskDetailId);
		$taskDetailGroupFileIds = json_decode($taskDetail->getGroupFilesIds());
		foreach ($fileIds as $fileId) {
			$deleteFileResult = $this->deleteTaskDetailFile((int)$taskDetail->getTaskId(), $taskDetailId, $fileId, false);
			if ($deleteFileResult['success']) {
				$fileIdIndex = array_search($fileId, $taskDetailGroupFileIds);
				if ($fileIdIndex !== false) {
					array_push($result, array_splice($taskDetailGroupFileIds, $fileIdIndex, 1)[0]);
				}
			} else {
				if (isset($deleteFileResult['locked']) && $deleteFileResult['locked']) {
					array_push($errors['locked'], $fileId);
				}
				if (isset($deleteFileResult['not_permited']) && $deleteFileResult['not_permited']) {
					array_push($errors['not_permited'], $fileId);
				}
				if (isset($deleteFileResult['not_found']) && $deleteFileResult['not_found']) {
					array_push($errors['not_found'], $fileId);
				}
			}
		}
		if (count($taskDetailGroupFileIds) <= 1) {
			$this->tasksDetailsMapper->delete($taskDetail);
		} else {
			$taskDetail->setGroupFilesIds(json_encode($taskDetailGroupFileIds));
			$this->tasksDetailsMapper->update($taskDetail);
		}
		return [
			'success' => count($result) == count($fileIds),
			'deletedFileIds' => $result,
			'errors' => $errors,
		];
	}

	/**
	 * Remove ColectorTaskDetail groups with deleting coresponding files
	 *
	 * @param int $taskDetailId
	 * @param array $fileIds
	 *
	 * @return array $result
	 */
	public function removeTaskDetailFiles(int $taskDetailId, array $fileIds)
	{
		$result = [];
		/** @var CollectorTaskDetail $taskDetail */
		$taskDetail = $this->tasksDetailsMapper->find($taskDetailId);
		$taskDetailGroupFileIds = json_decode($taskDetail->getGroupFilesIds());
		foreach ($fileIds as $fileId) {
			$fileIdIndex = array_search($fileId, $taskDetailGroupFileIds);
			if ($fileIdIndex !== false) {
				array_push($result, array_splice($taskDetailGroupFileIds, $fileIdIndex, 1)[0]);
				$this->markResolvedPhoto($fileId, true);
				$this->markResolvedVideo($fileId, true);
			}
		}
		$taskDetail->setGroupFilesIds(json_encode($taskDetailGroupFileIds));
		$this->tasksDetailsMapper->update($taskDetail);
		if (count($taskDetailGroupFileIds) <= 1) {
			$this->tasksDetailsMapper->delete($taskDetail);
		}
		return ['success' => count($result) == count($fileIds), 'removedFileIds' => $result];
	}

	/**
	 * @param int $taskId
	 * @param int $limit
	 * @param int $offset
	 * @param array $filter
	 *
	 * @return array
	 */
	public function details(int $taskId, int $limit = null, int $offset = null, array $filter = []): array
	{
		try {
			return $this->tasksDetailsMapper->findAllById($taskId, $limit, $offset);
		} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
			$this->logger->error("Can't find CollectorTaskDetail by \$taskId = " . $taskId . "\n" . $e->getMessage());
			return [
				'success' => false,
				'message' => 'Not found'
			];
		}
	}

	/**
	 * Returns current user's resolved files (photos and videos)
	 * 
	 * @param int $limit
	 * @param int $offset
	 */
	public function resolved(string $type, int $limit = null, int $offset = null)
	{
		if (in_array($type, ['photos', 'videos'])) {
			return [
				$type => $type === 'photos' ? $this->photosService->getResolvedPhotos($this->userId, $limit, $offset)
					: $this->videosService->getResolvedVideos($this->userId, $limit, $offset)
			];
		}
		return null;
	}

	/**
	 * Mark resolved
	 * 
	 * @param string $type
	 * @param int $fileid
	 * @param bool $resolved
	 * 
	 * @return int
	 */
	public function markResolved(string $type, int $fileid, bool $resolved = true): array
	{
		if ($type === 'photos') {
			$result = $this->photosService->resolve($fileid, $resolved);
		} else if ($type === 'videos') {
			$result = $this->videosService->resolve($fileid, $resolved);
		}
		return ['success' => $result === 1];
	}

	/**
	 * Mark resolved photo
	 * 
	 * @param int $fileid
	 * @param bool $resolved
	 * 
	 * @return int
	 */
	public function markResolvedPhoto(int $fileid, bool $resolved = true): array
	{
		$result = $this->photosService->resolve($fileid, $resolved);
		return ['success' => $result === 1];
	}

	/**
	 * Mark resolved video
	 * 
	 * @param int $fileid
	 * @param bool $resolved
	 * 
	 * @return int
	 */
	public function markResolvedVideo(int $fileid, bool $resolved = true): array
	{
		$result = $this->videosService->resolve($fileid, $resolved);
		return ['success' => $result === 1];
	}

	/**
	 * @param array $targetDirectoryIds
	 * @param int $targetMtype
	 * @param array $excludeList
	 *
	 * @return array target directories `files_total` and `files_total_size`
	 */
	public function getTargetDirectoriesData($targetDirectoryIds, $targetMtype, $excludeList)
	{
		$result = [
			'files_total' => 0,
			'files_total_size' => 0,
		];
		foreach ($targetDirectoryIds as $targetId) {
			$nodes = $this->userFolder->getById($targetId);
			if (count($nodes) > 0) {
				foreach ($nodes as $node) {
					if ($node instanceof Folder && $this->passesExcludeList($node, $excludeList) && !$this->hasIgnoreFlag($node)) {
						$result['files_total'] += $this->getTargetFolderFilesCount($node, $this->isShared($node), $targetMtype, $excludeList);
						$result['files_total_size'] += $this->getTargetFolderFilesSize($node, $targetMtype, $excludeList);
					} else if (
						$node instanceof File && $this->isValidFile($node, $this->isShared($node), $targetMtype)
						&& $this->passesExcludeList($node, $excludeList)
					) {
						$result['files_total'] += 1;
						$result['files_total_size'] += $node->getSize();
					}
				}
			}
		}
		return $result;
	}

	/**
	 * @param Folder $folder
	 * @param int $targetMtype
	 * @param array $excludeList
	 *
	 * @return int Valid target files size
	 */
	public function getTargetFolderFilesSize($folder, $targetMtype, $excludeList)
	{
		if ($this->hasIgnoreFlag($folder)) {
			return 0;
		}
		$size = 0;
		$nodes = $folder->getDirectoryListing();
		if (count($nodes) > 0) {
			foreach ($nodes as $node) {
				if ($node instanceof Folder && $this->passesExcludeList($node, $excludeList)) {
					$size += $this->getTargetFolderFilesSize($node, $targetMtype, $excludeList);
				} else if (
					$node instanceof File && $this->isValidFile($node, $this->isShared($node), $targetMtype)
					&& $this->passesExcludeList($node, $excludeList)
				) {
					$size += $node->getSize();
				}
			}
		}
		return $size;
	}

	/**
	 * @param Folder $folder
	 * @param bool $shared
	 * @param int $targetMtype
	 * @param array $excludeList
	 *
	 * @return int folder files count
	 */
	public function getTargetFolderFilesCount($folder, $shared, $targetMtype, $excludeList)
	{
		if ($this->hasIgnoreFlag($folder)) {
			return 0;
		}
		$count = 0;
		$nodes = $folder->getDirectoryListing();
		foreach ($nodes as $node) {
			if (
				$node instanceof File && $this->isValidFile($node, $shared, $targetMtype)
				&& $this->passesExcludeList($node, $excludeList)
			) {
				$count += 1;
			} else if ($node instanceof Folder && $this->passesExcludeList($node, $excludeList)) {
				$count += $this->getTargetFolderFilesCount($node, $this->isShared($node), $targetMtype, $excludeList);
			}
		}
		return $count;
	}

	/**
	 * Check if Folder or File passes excludeList
	 *
	 * @param Node $node
	 * @param array $excludeList
	 *
	 * @return bool
	 */
	private function passesExcludeList(Node $node, array $excludeList)
	{
		if (isset($excludeList['admin']['mask'])) {
			foreach ($excludeList['admin']['mask'] as $rule) {
				if (fnmatch($rule, $node->getName())) {
					return false;
				}
			}
		}
		if (isset($excludeList['user']['mask'])) {
			foreach ($excludeList['user']['mask'] as $rule) {
				if (fnmatch($rule, $node->getName())) {
					return false;
				}
			}
		}
		if (isset($excludeList['admin']['fileid'])) {
			foreach ($excludeList['admin']['fileid'] as $fileid) {
				if ($fileid === $node->getId()) {
					return false;
				}
			}
		}
		if (isset($excludeList['user']['fileid'])) {
			foreach ($excludeList['user']['fileid'] as $fileid) {
				if ($fileid == $node->getId()) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Check if Folder contains ignore flags `.nomedia` or `.noimage`
	 * 
	 * @param Folder $folder target folder
	 * 
	 * @return bool
	 */
	public function hasIgnoreFlag(Folder $folder): bool
	{
		if ($folder instanceof Folder && ($folder->nodeExists('.nomedia') || $folder->nodeExists('.noimage'))) {
			return true;
		}
		return false;
	}

	/**
	 * @param Node $node
	 *
	 * @return bool
	 */
	private function isShared(Node $node): bool
	{
		return $node->getStorage()->instanceOfStorage(SharedStorage::class) ||
			$node->getStorage()->instanceOfStorage(\OCA\GroupFolders\Mount\GroupFolderStorage::class);
	}

	/**
	 * @param File $file
	 * @param bool $shared
	 * @param int $targetMtype
	 *
	 * @return bool
	 */
	private function isValidFile(File $file, bool $shared, int $targetMtype): bool
	{
		return in_array($file->getMimePart(), self::TARGET_MIME_TYPE[$targetMtype])
			&& $this->isShared($file) === $shared;
	}
}

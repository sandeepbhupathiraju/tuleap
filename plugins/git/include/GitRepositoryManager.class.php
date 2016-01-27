<?php
/**
 * Copyright (c) Enalean, 2012 - 2016. All Rights Reserved.
 *
 * This file is a part of Tuleap.
 *
 * Tuleap is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Tuleap is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Tuleap. If not, see <http://www.gnu.org/licenses/>.
 */

require_once 'PathJoinUtil.php';

/**
 * This class is responsible of management of several repositories.
 *
 * It works in close cooperation with GitRepositoryFactory (to instanciate repo)
 */
class GitRepositoryManager {

    /**
     * @var Git_Mirror_MirrorDataMapper
     */
    private $mirror_data_mapper;

    /**
     * @var GitRepositoryMirrorUpdater
     */
    private $mirror_updater;

    /**
     * @var GitRepositoryFactory
     */
    private $repository_factory;

    /**
     * @var Git_SystemEventManager
     */
    private $git_system_event_manager;

    /**
     * @var GitDao
     */
    private $dao;

    /**
     * @var String
     */
    private $backup_directory;

    /**
     * @var System_Command
     */
    private $system_command;

    /**
     * @param GitRepositoryFactory $repository_factory
     * @param Git_SystemEventManager   $git_system_event_manager
     * @param backup_directory
     */
    public function __construct(
        GitRepositoryFactory $repository_factory,
        Git_SystemEventManager $git_system_event_manager,
        GitDao $dao,
        $backup_directory,
        GitRepositoryMirrorUpdater $mirror_updater,
        Git_Mirror_MirrorDataMapper $mirror_data_mapper
    ) {
        $this->repository_factory       = $repository_factory;
        $this->git_system_event_manager = $git_system_event_manager;
        $this->dao                      = $dao;
        $this->backup_directory         = $backup_directory;
        $this->mirror_updater           = $mirror_updater;
        $this->mirror_data_mapper       = $mirror_data_mapper;
        $this->system_command           = new System_Command();
    }

    /**
     * Delete all project repositories (on project deletion).
     *
     * @param Project $project
     */
    public function deleteProjectRepositories(Project $project) {
        $repositories = $this->repository_factory->getAllRepositories($project);
        foreach ($repositories as $repository) {
            $repository->forceMarkAsDeleted();
            $this->git_system_event_manager->queueRepositoryDeletion($repository);
        }
    }

    private function initRepository(GitRepository $repository, GitRepositoryCreator $creator) {
        if (!$creator->isNameValid($repository->getName())) {
            throw new Exception($GLOBALS['Language']->getText(
                'plugin_git',
                'actions_input_format_error',
                array($creator->getAllowedCharsInNamePattern(), GitDao::REPO_NAME_MAX_LENGTH)
            ));
        }

        $this->assertRepositoryNameNotAlreadyUsed($repository);
        $id = $this->dao->save($repository);

        $repository->setId($id);
    }

    /**
     * Create a new GitRepository through its backend
     *
     * @param  GitRepository $repository
     * @throws Exception
     */
    public function create(GitRepository $repository, GitRepositoryCreator $creator, array $mirror_ids) {
        $this->initRepository($repository, $creator);

        if ($mirror_ids) {
            $this->mirror_updater->updateRepositoryMirrors($repository, $mirror_ids);
        }

        $this->git_system_event_manager->queueRepositoryUpdate($repository);
    }

    public function createFromBundle(GitRepository $repository, GitRepositoryCreator $creator, $bundle_path) {
        $this->initRepository($repository, $creator);

        $bundle_path_arg = escapeshellarg($bundle_path);
        $repository_full_path_arg = escapeshellarg($repository->getFullPath());
        $this->system_command->exec("sudo -u gitolite /usr/share/tuleap/plugins/git/bin/gl-clone-bundle.sh $bundle_path_arg $repository_full_path_arg");

        $this->git_system_event_manager->queueRepositoryUpdate($repository);
    }

    /**
     * Fork a repository
     *
     * @param GitRepository $repository      The repo to fork
     * @param Project       $to_project      The project to create the repo in
     * @param PFUser        $user            The user who does the fork (she will own the clone)
     * @param String        $namespace       The namespace to put the repo in (might be emtpy)
     * @param String        $scope           Either GitRepository::REPO_SCOPE_INDIVIDUAL or GitRepository::REPO_SCOPE_PROJECT
     * @param Array         $forkPermissions Permissions to be applied for the new repository
     */
    public function fork(GitRepository $repository, Project $to_project, PFUser $user, $namespace, $scope, array $forkPermissions) {
        $clone = clone $repository;
        $clone->setProject($to_project);
        $clone->setCreator($user);
        $clone->setParent($repository);
        $clone->setNamespace($namespace);
        $clone->setId(null);
        $path = unixPathJoin(array($to_project->getUnixName(), $namespace, $repository->getName())).'.git';
        $clone->setPath($path);
        $clone->setScope($scope);

        $this->assertRepositoryNameNotAlreadyUsed($clone);
        $this->doForkRepository($repository, $clone, $forkPermissions);

        $this->mirrorForkedRepository($clone, $repository);
    }

    private function mirrorForkedRepository(
        GitRepository $forked_repository,
        GitRepository $base_repository
    ) {
        $base_repository_mirrors = $this->mirror_data_mapper->fetchAllRepositoryMirrors($base_repository);

        $project_destination               = $forked_repository->getProject();
        $allowed_mirrors_forked_repository = $this->mirror_data_mapper->fetchAllForProject($project_destination);

        $repository_mirrors_ids            = array();
        foreach ($base_repository_mirrors as $mirror) {
            if (in_array($mirror, $allowed_mirrors_forked_repository)) {
                $repository_mirrors_ids[] = $mirror->id;
            }
        }

        if ($repository_mirrors_ids) {
            $this->mirror_updater->updateRepositoryMirrors($forked_repository, $repository_mirrors_ids);
            $this->git_system_event_manager->queueRepositoryUpdate($forked_repository);
        }
    }

    private function doForkRepository(GitRepository $repository, GitRepository $clone, array $forkPermissions) {
        $id = $repository->getBackend()->fork($repository, $clone, $forkPermissions);
        $clone->setId($id);
        if ($id) {
            $this->git_system_event_manager->queueRepositoryFork($repository, $clone);
        } else {
            throw new Exception($GLOBALS['Language']->getText('plugin_git', 'actions_no_repository_forked'));
        }
    }

    private function assertRepositoryNameNotAlreadyUsed(GitRepository $repository) {
        if ($this->isRepositoryNameAlreadyUsed($repository)) {
            throw new Exception($GLOBALS['Language']->getText('plugin_git', 'actions_create_repo_exists', array($repository->getName())));
        }
    }

    /**
     * For several repositories at once
     *
     * @param array         $repositories    Array of GitRepositories to fork
     * @param Project       $to_project      The project to create the repo in
     * @param PFUser        $user            The user who does the fork (she will own the clone)
     * @param String        $namespace       The namespace to put the repo in (might be emtpy)
     * @param String        $scope           Either GitRepository::REPO_SCOPE_INDIVIDUAL or GitRepository::REPO_SCOPE_PROJECT
     * @param array         $forkPermissions Permissions to be applied for the new repository
     *
     * @return Boolean
     *
     * @throws Exception
     */
    public function forkRepositories(array $repositories, Project $to_project, PFUser $user, $namespace, $scope, array $forkPermissions) {
        $repos = array_filter($repositories);
        if (count($repos) > 0 && $this->isNamespaceValid($repos[0], $namespace)) {
            return $this->forkAllRepositories($repos, $user, $namespace, $scope, $to_project, $forkPermissions);
        }
        throw new Exception($GLOBALS['Language']->getText('plugin_git', 'actions_no_repository_forked'));
    }

    private function isNamespaceValid(GitRepository $repository, $namespace) {
        if ($namespace) {
            $ns_chunk = explode('/', $namespace);
            foreach ($ns_chunk as $chunk) {
                //TODO use creator
                if (!$repository->getBackend()->isNameValid($chunk)) {
                    throw new Exception($GLOBALS['Language']->getText('plugin_git', 'fork_repository_invalid_namespace'));
                }
            }
        }
        return true;
    }

    private function forkAllRepositories(array $repos, PFUser $user, $namespace, $scope, Project $project, array $forkPermissions) {
        $forked = false;
        foreach ($repos as $repo) {
            try {
                if ($repo->userCanRead($user)) {
                    $this->fork($repo, $project, $user, $namespace, $scope, $forkPermissions);
                    $forked = true;
                }
            } catch (GitRepositoryAlreadyExistsException $e) {
                $GLOBALS['Response']->addFeedback('warning', $GLOBALS['Language']->getText('plugin_git', 'fork_repository_exists', array($repo->getName())));
            } catch (Exception $e) {
                $GLOBALS['Response']->addFeedback('warning', 'Got an unexpected error while forking ' . $repo->getName() . ': ' . $e->getMessage());
            }
        }
        return $forked;
    }

    /**
     * Return true if proposed name already exists as a repository path
     *
     * @param Project $project
     * @param String  $name
     *
     * @return Boolean
     */
    public function isRepositoryNameAlreadyUsed(GitRepository $new_repository) {
        $repositories = $this->repository_factory->getAllRepositories($new_repository->getProject());
        foreach ($repositories as $existing_repo) {
            $new_repo_path      = $new_repository->getPathWithoutLazyLoading();
            $existing_repo_path = $existing_repo->getPathWithoutLazyLoading();
            if ($new_repo_path == $existing_repo_path) {
                return true;
            }
            if ($this->nameIsSubPathOfExistingRepository($existing_repo_path, $new_repo_path)) {
                return true;
            }
            if ($this->nameAlreadyExistsAsPath($existing_repo_path, $new_repo_path)) {
                return true;
            }
        }
    }

    private function nameIsSubPathOfExistingRepository($repository_path, $new_path) {
        $repo_path_without_dot_git = $this->stripFinalDotGit($repository_path);
        if (strpos($new_path, "$repo_path_without_dot_git/") === 0) {
            return true;
        }
        return false;
    }

    private function nameAlreadyExistsAsPath($repository_path, $new_path) {
        $new_path = $this->stripFinalDotGit($new_path);
        if (strpos($repository_path, "$new_path/") === 0) {
            return true;
        }
        return false;
    }

    private function stripFinalDotGit($path) {
        return substr($path, 0, strrpos($path, '.git'));
    }

    /**
     *
     * Purge archived Gitolite repositories
     *
     * @param Logger $logger
     *
     */
    public function purgeArchivedRepositories(Logger $logger) {
        if(!isset($GLOBALS['sys_file_deletion_delay'])) {
            $logger->warn("Purge of archived Gitolite repositories is disabled: sys_file_deletion_delay is missing in local.inc file");
            return;
        }
        $retention_period      = intval($GLOBALS['sys_file_deletion_delay']);
        $archived_repositories = $this->repository_factory->getArchivedRepositoriesToPurge($retention_period);
        foreach ($archived_repositories as $repository) {
            try {
                $backend = $repository->getBackend();
                $backend->deletePermissions($repository);
                if ($backend->archiveBeforePurge($repository)) {
                    $logger->info('Archive of the Gitolite repository: '.$repository->getName().' done');
                } else {
                    $logger->warn('An error occured while archiving Gitolite repository: '.$repository->getName());
                }
                $logger->info('Purge of archived Gitolite repository: '.$repository->getName());
                $backend->deleteArchivedRepository($repository);
            } catch (GitDriverErrorException $exception) {
                $logger->error($exception->getMessage());
            }
        }
    }

    /**
     *
     * Get archived Gitolite repositories for restore
     *
     * @param Int $project_id
     *
     * @return GitRepository[]
     */
    public function getRepositoriesForRestoreByProjectId($project_id) {
        $archived_repositories = array();
        $retention_period      = intval($GLOBALS['sys_file_deletion_delay']);
        $deleted_repositories  = $this->repository_factory->getDeletedRepositoriesByProjectId($project_id, $retention_period);
        foreach ($deleted_repositories as $repository) {
            $archive = realpath($this->backup_directory.'/'.$repository->getBackupPath().".tar.gz");
            if (file_exists($archive)) {
                array_push($archived_repositories, $repository);
            }
        }
        return $archived_repositories;
    }
}

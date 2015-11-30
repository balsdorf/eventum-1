<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 encoding=utf-8: */
// +----------------------------------------------------------------------+
// | Eventum - Issue Tracking System                                      |
// +----------------------------------------------------------------------+
// | Copyright (c) 2015 Eventum Team.                                     |
// |                                                                      |
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License as published by |
// | the Free Software Foundation; either version 2 of the License, or    |
// | (at your option) any later version.                                  |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to:                           |
// |                                                                      |
// | Free Software Foundation, Inc.                                       |
// | 51 Franklin Street, Suite 330                                        |
// | Boston, MA 02110-1301, USA.                                          |
// +----------------------------------------------------------------------+

namespace Eventum\Controller;

use Date_Helper;
use Exception;
use Issue;
use Logger;
use SCM;

class ScmPingController extends BaseController
{
    /** @var int[] */
    private $issues;

    /** @var string[] */
    private $files;

    /** @var string[] */
    private $module;

    /** @var string */
    private $username;

    /** @var string */
    private $commit_msg;

    /** @var string */
    private $scm_name;

    /** @var string[] */
    private $old_versions;

    /** @var string[] */
    private $new_versions;

    /** @var bool */
    private $json;

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $get = $this->getRequest()->query;

        $this->scm_name = $get->get('scm_name');
        $this->module = $get->get('module');
        $this->username = $get->get('username');
        $this->issues = $get->get('issue');
        $this->commit_msg = $get->get('commit_msg');
        $this->files = $get->get('files');
        $this->old_versions = $get->get('old_versions');
        $this->new_versions = $get->get('new_versions');
        $this->json = $get->getBoolean('json');
    }

    /**
     * @inheritdoc
     */
    protected function canAccess()
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    protected function defaultAction()
    {
        try {
            ob_start();
            $this->pingAction($this->module, $this->username, $this->scm_name, $this->issues, $this->commit_msg);
            $status = array(
                'code' => 0,
                'message' => ob_get_clean(),
            );
        } catch (Exception $e) {
            $code = $e->getCode();
            $status = array(
                'code' => $code ? $code : -1,
                'message' => $e->getMessage(),
            );
            Logger::app()->error($e);
        }

        if ($this->json) {
            echo json_encode($status);
            exit;
        } else {
            echo $status['message'];
            exit($status['code']);
        }
    }

    /**
     * @param string[] $module
     * @param string $username
     * @param string $scm_name
     * @param int[] $issues
     * @param string $commit_msg
     */
    private function pingAction($module, $username, $scm_name, $issues, $commit_msg)
    {
        // module is per file (svn hook)
        if (is_array($module)) {
            $module = null;
        }

        $nfiles = count($this->files);
        $commit_time = Date_Helper::getCurrentDateGMT();

        // process checkins for each issue
        foreach ($issues as $issue_id) {
            // check early if issue exists to report proper message back
            // workflow needs to know project_id to find out which workflow class to use.
            $prj_id = Issue::getProjectID($issue_id);
            if (!$prj_id) {
                echo "issue #$issue_id not found\n";
                continue;
            }

            $files = array();
            for ($y = 0; $y < $nfiles; $y++) {
                $file = array(
                    'file' => $this->files[$y],
                    // version may be missing to indicate 'added' or ''removed' state
                    'old_version' => isset($this->old_versions[$y]) ? $this->old_versions[$y] : null,
                    'new_version' => isset($this->new_versions[$y]) ? $this->new_versions[$y] : null,
                    // there may be per file global (cvs) or module (svn)
                    'module' => isset($module) ? $module : $this->module[$y],
                );

                $files[] = $file;
            }

            SCM::addCheckins($issue_id, $commit_time, $scm_name, $username, $commit_msg, $files);

            // print report to stdout of commits so hook could report status back to commiter
            $details = Issue::getDetails($issue_id);
            echo "#$issue_id - {$details['iss_summary']} ({$details['sta_title']})\n";
        }
    }

    /**
     * @inheritdoc
     */
    protected function prepareTemplate()
    {
    }
}

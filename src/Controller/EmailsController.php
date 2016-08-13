<?php

/*
 * This file is part of the Eventum (Issue Tracking System) package.
 *
 * @copyright (c) Eventum Team
 * @license GNU General Public License, version 2 or later (GPL-2+)
 *
 * For the full copyright and license information,
 * please see the COPYING and AUTHORS files
 * that were distributed with this source code.
 */
namespace Eventum\Controller;

use Access;
use Auth;
use AuthCookie;
use CRM;
use Email_Account;
use Issue;
use Prefs;
use Support;
use Custom_Field; // TECHSOFT-CSTM: Custom import

class EmailsController extends BaseController
{
    /** @var string */
    protected $tpl_name = 'emails.tpl.html';

    /** @var int */
    private $usr_id;

    /** @var int */
    private $prj_id;

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $request = $this->getRequest();

        $this->prj_id = $request->query->getInt('prj_id');
    }

    /**
     * @inheritdoc
     */
    protected function canAccess()
    {
        Auth::checkAuthentication();

        $usr_id = Auth::getUserID();
        if (!Access::canAccessAssociateEmails($usr_id)) {
            // TODO: cleanup template from 'no_access'
            //$tpl->assign('no_access', 1);
            return false;
        }

        $prj_id = Auth::getCurrentProject();
        if ($this->prj_id && $this->prj_id != $prj_id) {
            AuthCookie::setProjectCookie($this->prj_id);
            // TODO: redirect and check access for project switch!
            $this->prj_id = $prj_id;
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    protected function defaultAction()
    {
    }

    /**
     * @inheritdoc
     */
    protected function prepareTemplate()
    {
        $pagerRow = Support::getParam('pagerRow') ?: 0;
        $rows = Support::getParam('rows') ?: APP_DEFAULT_PAGER_SIZE;

        $options = Support::saveSearchParams();
        $list = Support::getEmailListing($options, $pagerRow, $rows);
        $prefs = Prefs::get($this->usr_id);

        /** TECHSOFT-CSTM: Start. Assign component field for new issue emails **/
        $prj_id = Auth::getCurrentProject();
        $crm = CRM::getInstance($prj_id);
        $rows = $list["list"];
        $pat = '/^#(?P<id>\d+): \*\*\*New Issue Created By Customer\/Contractor\*\*\*:/';
        for ($i=0; $i < count($rows); $i++) {
            $sup_to = $rows[$i]["sup_to"];
            $sup_from = $rows[$i]["sup_from"];
            $component = "";
            $sup_subject = $rows[$i]["sup_subject"];

            if ($sup_from === "support@techsoft3d.com" &&
                ($sup_to === "eventum@techsoft3d.com" || $sup_to === "eventum_test@techsoft3d.com") &&
                preg_match($pat, $sup_subject, $matches) > 0 ){

                    $issue_id = $matches["id"];

                    $custom = Custom_Field::getListByIssue($prj_id, $issue_id, false, array(18));
                    $component = $custom[0]["value"];

                    $contact_id = Issue::getContactID($issue_id);
                    $contact = $crm->getContact($contact_id);

                    $sup_from = $contact->getEmail();
                    $customer = $contact->getCustomers()[0];
                    $sup_subject = str_replace("***New Issue Created By Customer/Contractor***", "***New Issue***", $sup_subject);

                    $rows[$i]["sup_from"] = $sup_from;
                    $rows[$i]["customer_title"] = $customer->getName();
                    $rows[$i]["sup_customer_id"] = $customer->getCustomerID();
                    $rows[$i]["sup_subject"] = $sup_subject;
            }

            $rows[$i]["component"] = $component;
        }
        $list['list'] = $rows;
        /** TECHSOFT-CSTM:  Assign category field for new issue emails **/


        $this->tpl->assign(
            [
                'options' => $options,
                'sorting' => Support::getSortingInfo($options),

                'list' => $list['list'],
                'list_info' => $list['info'],
                'issues' => Issue::getColList(),
                'accounts' => Email_Account::getAssocList($this->prj_id),

                'refresh_rate' => $prefs['email_refresh_rate'] * 60,
                'refresh_page' => 'emails.php',
            ]
        );
    }
}

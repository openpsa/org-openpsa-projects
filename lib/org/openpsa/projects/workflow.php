<?php
/**
 * @package org.openpsa.projects
 * @author Nemein Oy http://www.nemein.com/
 * @copyright Nemein Oy http://www.nemein.com/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

/**
 * org.openpsa.projects site interface class.
 *
 * @package org.openpsa.projects
 */
class org_openpsa_projects_workflow
{
    /**
     * @throws midcom_error
     */
    public static function run(string $command, org_openpsa_projects_task_dba $task)
    {
        if (!method_exists(__CLASS__, $command)) {
            throw new midcom_error("Method not implemented");
        }
        return static::$command($task);
    }

    /**
     * Returns the status type of a given status
     */
    public static function get_status_type(int $status) : string
    {
        $map = [
            org_openpsa_projects_task_status_dba::REJECTED => 'rejected',
            org_openpsa_projects_task_status_dba::PROPOSED => 'not_started',
            org_openpsa_projects_task_status_dba::DECLINED => 'not_started',
            org_openpsa_projects_task_status_dba::ACCEPTED => 'not_started',
            org_openpsa_projects_task_status_dba::STARTED => 'ongoing',
            org_openpsa_projects_task_status_dba::REOPENED => 'ongoing',
            org_openpsa_projects_task_status_dba::COMPLETED => 'closed',
            org_openpsa_projects_task_status_dba::APPROVED => 'closed',
            org_openpsa_projects_task_status_dba::CLOSED => 'closed',
            org_openpsa_projects_task_status_dba::ONHOLD => 'on_hold'
        ];
        if (array_key_exists($status, $map)) {
            return $map[$status];
        }
        return 'on_hold';
    }

    public static function render_status_control(org_openpsa_projects_task_dba $task) : string
    {
        $prefix = midcom_core_context::get()->get_key(MIDCOM_CONTEXT_ANCHORPREFIX);
        if ($task->status < org_openpsa_projects_task_status_dba::COMPLETED) {
            $action = 'complete';
            $checked = '';
        } else {
            if ($task->status == org_openpsa_projects_task_status_dba::COMPLETED) {
                $action = 'remove_complete';
            } else {
                $action = 'reopen';
            }
            $checked = ' checked="checked"';
        }
        $html = '<form method="post" action="' . $prefix . 'workflow/' . $task->guid . '/">';
        $html .= '<input type="hidden" name="org_openpsa_projects_workflow_action[' . $action . ']" value="true" />';
        $html .= '<input type="checkbox"' . $checked . ' name="org_openpsa_projects_workflow_dummy" value="true" onchange="this.form.submit()" />';
        $html .= '</form>';
        return $html;
    }

    /**
     * Shortcut for creating status object
     */
    public static function create_status(org_openpsa_projects_task_dba $task, int $status_type, int $target_person, string $comment = '') : bool
    {
        debug_print_function_stack('create_status called from: ');
        $status = new org_openpsa_projects_task_status_dba();
        $status->targetPerson = $target_person;
        $status->task = $task->id;
        $status->type = $status_type;
        $status->comment = $comment;

        $ret = $status->create();

        if (!$ret) {
            debug_add('failed to create status object, errstr: ' . midcom_connection::get_error_string(), MIDCOM_LOG_WARN);
        }
        return $ret;
    }

    /**
     * Propose task to a resource
     */
    public static function propose(org_openpsa_projects_task_dba $task, int $pid, string $comment = '') : bool
    {
        debug_add("saving proposed status for person {$pid}");
        return self::create_status($task, org_openpsa_projects_task_status_dba::PROPOSED, $pid, $comment);
    }

    /**
     * Accept the proposal
     */
    public static function accept(org_openpsa_projects_task_dba $task, int $pid = -1, string $comment = '') : bool
    {
        if ($pid < 0) {
            $pid = midcom_connection::get_user();
        }
        debug_add("task->accept() called with user #" . $pid);

        return self::create_status($task, org_openpsa_projects_task_status_dba::ACCEPTED, $pid, $comment);
    }

    /**
     * Decline the proposal
     */
    static function decline(org_openpsa_projects_task_dba $task, string $comment = '') : bool
    {
        debug_add("task->decline() called with user #" . midcom_connection::get_user());

        return self::create_status($task, org_openpsa_projects_task_status_dba::DECLINED, midcom_connection::get_user(), $comment);
    }

    /**
     * Mark task as started (in case it's not already done)
     */
    public static function start(org_openpsa_projects_task_dba $task, int $started_by = 0) : bool
    {
        debug_add("task->start() called with user #" . midcom_connection::get_user());
        //PONDER: Check actual status objects for more accurate logic ?
        if (   $task->status >= org_openpsa_projects_task_status_dba::STARTED
            && $task->status <= org_openpsa_projects_task_status_dba::APPROVED) {
            //We already have started status
            debug_add('Task has already been started');
            return true;
        }
        return self::create_status($task, org_openpsa_projects_task_status_dba::STARTED, $started_by);
    }

    /**
     * Mark task as completed
     */
    public static function complete(org_openpsa_projects_task_dba $task, string $comment = '') : bool
    {
        debug_add("task->complete() called with user #" . midcom_connection::get_user());
        //TODO: Check deliverables
        if (!self::create_status($task, org_openpsa_projects_task_status_dba::COMPLETED, 0, $comment)) {
            return false;
        }
        //PONDER: Check ACL instead ?
        if (self::is_manager($task)) {
            //Manager marking task completed also approves it at the same time
            debug_add('We\'re the manager of this task (or it is orphaned), approving straight away');
            return self::approve($task, $comment);
        }

        return true;
    }

    /**
     * Drops a completed task to started status
     */
    static function remove_complete(org_openpsa_projects_task_dba $task, $comment = '') : bool
    {
        debug_add("task->remove_complete() called with user #" . midcom_connection::get_user());
        if ($task->status != org_openpsa_projects_task_status_dba::COMPLETED) {
            //Status is not completed, we can't remove that status.
            debug_add('status != completed, aborting');
            return false;
        }
        return self::_drop_to_started($task, $comment);
    }

    /**
     * Drops tasks status to started
     */
    private static function _drop_to_started(org_openpsa_projects_task_dba $task, string $comment = '') : bool
    {
        if ($task->status <= org_openpsa_projects_task_status_dba::STARTED) {
            debug_add('Task has not been started, aborting');
            return false;
        }
        return self::create_status($task, org_openpsa_projects_task_status_dba::STARTED, 0, $comment);
    }

    /**
     * Mark task as approved
     */
    static function approve(org_openpsa_projects_task_dba $task, string $comment = '') : bool
    {
        debug_add("task->approve() called with user #" . midcom_connection::get_user());
        //TODO: Check deliverables / Require to be completed first
        //PONDER: Check ACL instead ?
        if (!self::is_manager($task)) {
            debug_add("Current user #" . midcom_connection::get_user() . " is not manager of task, thus cannot approve", MIDCOM_LOG_ERROR);
            return false;
        }

        if (!self::create_status($task, org_openpsa_projects_task_status_dba::APPROVED, 0, $comment)) {
            return false;
        }
        debug_add('approved tasks get closed at the same time, calling this->close()');
        return self::close($task, $comment);
    }

    static function reject(org_openpsa_projects_task_dba $task, string $comment = '') : bool
    {
        debug_add("task->reject() called with user #" . midcom_connection::get_user());
        //TODO: Check deliverables / Require to be completed first
        //PONDER: Check ACL instead ?
        if (!self::is_manager($task)) {
            debug_add("Current user #" . midcom_connection::get_user() . " is not manager of task, thus cannot reject", MIDCOM_LOG_ERROR);
            return false;
        }
        return self::create_status($task, org_openpsa_projects_task_status_dba::REJECTED, 0, $comment);
    }

    /**
     * Drops an approved task to started status
     */
    static function remove_approve(org_openpsa_projects_task_dba $task, string $comment = '') : bool
    {
        debug_add("task->remove_approve() called with user #" . midcom_connection::get_user());
        if ($task->status != org_openpsa_projects_task_status_dba::APPROVED) {
            debug_add('Task is not approved, aborting');
            return false;
        }
        return self::_drop_to_started($task, $comment);
    }

    /**
     * Mark task as closed
     */
    public static function close(org_openpsa_projects_task_dba $task, string $comment = '') : bool
    {
        debug_add("task->close() called with user #" . midcom_connection::get_user());
        //TODO: Check deliverables / require to be approved first
        //PONDER: Check ACL instead?
        if (!self::is_manager($task)) {
            debug_add("Current user #" . midcom_connection::get_user() . " is not manager of task, thus cannot close", MIDCOM_LOG_ERROR);
            return false;
        }

        if (self::create_status($task, org_openpsa_projects_task_status_dba::CLOSED, 0, $comment)) {
            midcom::get()->uimessages->add(midcom::get()->i18n->get_string('org.openpsa.projects', 'org.openpsa.projects'), sprintf(midcom::get()->i18n->get_string('marked task "%s" closed', 'org.openpsa.projects'), $task->title));
            if ($task->agreement) {
                $agreement = new org_openpsa_sales_salesproject_deliverable_dba($task->agreement);

                // Set agreement delivered if this is the only open task for it
                $task_qb = org_openpsa_projects_task_dba::new_query_builder();
                $task_qb->add_constraint('agreement', '=', $task->agreement);
                $task_qb->add_constraint('status', '<', org_openpsa_projects_task_status_dba::CLOSED);
                $task_qb->add_constraint('id', '<>', $task->id);
                if ($task_qb->count() == 0) {
                    // No other open tasks, mark as delivered
                    $agreement->deliver(false);
                } else {
                    midcom::get()->uimessages->add(midcom::get()->i18n->get_string('org.openpsa.projects', 'org.openpsa.projects'), sprintf(midcom::get()->i18n->get_string('did not mark deliverable "%s" delivered due to other tasks', 'org.openpsa.sales'), $agreement->title), 'info');
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Reopen a closed task
     */
    public static function reopen(org_openpsa_projects_task_dba $task, string $comment = '') : bool
    {
        debug_add("task->reopen() called with user #" . midcom_connection::get_user());
        if ($task->status != org_openpsa_projects_task_status_dba::CLOSED) {
            debug_add('Task is not closed, aborting');
            return false;
        }
        return self::create_status($task, org_openpsa_projects_task_status_dba::REOPENED, 0, $comment);
    }

    private static function is_manager(org_openpsa_projects_task_dba $task) : bool
    {
        return (   $task->manager == 0
                || midcom_connection::get_user() == $task->manager);
    }
}

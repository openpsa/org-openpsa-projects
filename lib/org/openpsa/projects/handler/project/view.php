<?php
/**
 * @package org.openpsa.projects
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

use midcom\datamanager\datamanager;

/**
 * Project view handler
 *
 * @package org.openpsa.projects
 */
class org_openpsa_projects_handler_project_view extends midcom_baseclasses_components_handler
{
    private org_openpsa_projects_project $project;

    /**
     * Generates an object view.
     */
    public function _handler_read(string $guid, array &$data)
    {
        $this->project = new org_openpsa_projects_project($guid);

        $dm = datamanager::from_schemadb($this->_config->get('schemadb_project'))
            ->set_storage($this->project);

        $data['object_view'] = $dm->get_content_html();
        $data['object'] = $this->project;

        $this->populate_toolbar();
        midcom::get()->head->set_pagetitle($this->project->get_label());
        org_openpsa_projects_viewer::add_breadcrumb_path($this->project, $this);

        // Let MidCOM know about the object
        midcom::get()->metadata->set_request_metadata($this->project->metadata->revised, $guid);
        $this->bind_view_to_object($this->project, $dm->get_schema()->get_name());

        return $this->show('show-project');
    }

    /**
     * Add the supported operations into the toolbar.
     */
    private function populate_toolbar()
    {
        $workflow = $this->get_workflow('datamanager');
        $buttons = [];
        if ($this->project->can_do('midgard:update')) {
            $buttons[] = $workflow->get_button($this->router->generate('project-edit', ['guid' => $this->project->guid]), [
                MIDCOM_TOOLBAR_ACCESSKEY => 'e',
            ]);
            $buttons[] = $workflow->get_button($this->router->generate('task-new-2', [
                'guid' => $this->project->guid,
                'type' => 'project'
            ]), [
                MIDCOM_TOOLBAR_LABEL => $this->_l10n->get("create task"),
                MIDCOM_TOOLBAR_GLYPHICON => 'calendar-check-o',
            ]);
        }

        $siteconfig = org_openpsa_core_siteconfig::get_instance();
        if ($sales_url = $siteconfig->get_node_full_url('org.openpsa.sales')) {
            $buttons[] = [
                MIDCOM_TOOLBAR_URL => $sales_url . "salesproject/{$this->project->guid}/",
                MIDCOM_TOOLBAR_LABEL => $this->_i18n->get_string('salesproject', 'org.openpsa.sales'),
                MIDCOM_TOOLBAR_GLYPHICON => 'book',
            ];
        }
        $this->_view_toolbar->add_items($buttons);
        org_openpsa_relatedto_plugin::add_button($this->_view_toolbar, $this->project->guid);
    }
}

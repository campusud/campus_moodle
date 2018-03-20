<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This file contains the core_privacy\manager class.
 *
 * @package core_privacy
 * @copyright 2018 Jake Dallimore <jrhdallimore@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace core_privacy;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist_collection;

defined('MOODLE_INTERNAL') || die();

/**
 * The core_privacy\manager class, providing a facade to describe, export and delete personal data across Moodle and its components.
 *
 * This class is responsible for communicating with and collating privacy data from all relevant components, where relevance is
 * determined through implementations of specific marker interfaces. These marker interfaces describe the responsibilities (in terms
 * of personal data storage) as well as the relationship between the component and the core_privacy subsystem.
 *
 * The interface hierarchy is as follows:
 * ├── local\metadata\null_provider
 * ├── local\metadata\provider
 * ├── local\request\data_provider
 *     └── local\request\core_data_provider
 *         └── local\request\core_user_data_provider
 *             └── local\request\plugin\provider
 *             └── local\request\subsystem\provider
 *         └── local\request\user_preference_provider
 *     └── local\request\shared_data_provider
 *         └── local\request\plugin\subsystem_provider
 *         └── local\request\plugin\subplugin_provider
 *         └── local\request\subsystem\plugin_provider
 *
 * Describing personal data:
 * -------------------------
 * All components must state whether they store personal data (and DESCRIBE it) by implementing one of the metadata providers:
 * - local\metadata\null_provider (indicating they don't store personal data)
 * - local\metadata\provider (indicating they do store personal data, and describing it)
 *
 * The manager requests metadata for all Moodle components implementing the local\metadata\provider interface.
 *
 * Export and deletion of personal data:
 * -------------------------------------
 * Those components storing personal data need to provide EXPORT and DELETION of this data by implementing a request provider.
 * Which provider implementation depends on the nature of the component; whether it's a sub-component and which components it
 * stores data for.
 *
 * Export and deletion for sub-components (or any component storing data on behalf of another component) is managed by the parent
 * component. If a component contains sub-components, it must ask those sub-components to provide the relevant data. Only certain
 * 'core provider' components are called directly from the manager and these must provide the personal data stored by both
 * themselves, and by all sub-components. Because of this hierarchical structure, the core_privacy\manager needs to know which
 * components are to be called directly by core: these are called core data providers. The providers implemented by sub-components
 * are called shared data providers.
 *
 * The following are interfaces are not implemented directly, but are marker interfaces uses to classify components by nature:
 * - local\request\data_provider:
 *      Not implemented directly. Used to classify components storing personal data of some kind. Includes both components storing
 *      personal data for themselves and on behalf of other components.
 *      Include: local\request\core_data_provider and local\request\shared_data_provider.
 * - local\request\core_data_provider:
 *      Not implemented directly. Used to classify components storing personal data for themselves and which are to be called by the
 *      core_privacy subsystem directly.
 *      Includes: local\request\core_user_data_provider and local\request\user_preference_provider.
 * - local\request\core_user_data_provider:
 *      Not implemented directly. Used to classify components storing personal data for themselves, which are either a plugin or
 *      subsystem and which are to be called by the core_privacy subsystem directly.
 *      Includes: local\request\plugin\provider and local\request\subsystem\provider.
 * - local\request\shared_data_provider:
 *      Not implemented directly. Used to classify components storing personal data on behalf of other components and which are
 *      called by the owning component directly.
 *      Includes: local\request\plugin\subsystem_provider, local\request\plugin\subplugin_provider and local\request\subsystem\plugin_provider
 *
 * The manager only requests the export or deletion of personal data for components implementing the local\request\core_data_provider
 * interface or one of its descendants; local\request\plugin\provider, local\request\subsystem\provider or local\request\user_preference_provider.
 * Implementing one of these signals to the core_privacy subsystem that the component must be queried directly from the manager.
 *
 * Any component using another component to store personal data on its behalf, is responsible for making the relevant call to
 * that component's relevant shared_data_provider class.
 *
 * For example:
 * The manager calls a core_data_provider component (e.g. mod_assign) which, in turn, calls relevant subplugins or subsystems
 * (which assign uses to store personal data) to get that data. All data for assign and its sub-components is aggregated by assign
 * and returned to the core_privacy subsystem.
 *
 * @copyright 2018 Jake Dallimore <jrhdallimore@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /**
     * Checks whether the given component is compliant with the core_privacy API.
     * To be considered compliant, a component must declare whether (and where) it stores personal data.
     *
     * Components which do store personal data must:
     * - Have implemented the core_privacy\local\metadata\provider interface (to describe the data it stores) and;
     * - Have implemented the core_privacy\local\request\data_provider interface (to facilitate export of personal data)
     * - Have implemented the core_privacy\local\request\deleter interface
     *
     * Components which do not store personal data must:
     * - Have implemented the core_privacy\local\metadata\null_provider interface to signal that they don't store personal data.
     *
     * @param string $component frankenstyle component name, e.g. 'mod_assign'
     * @return bool true if the component is compliant, false otherwise.
     */
    public function component_is_compliant(string $component) : bool {
        // Components which don't store user data need only implement the null_provider.
        if ($this->component_implements($component, \core_privacy\local\metadata\null_provider::class)) {
            return true;
        }
        // Components which store user data must implement the local\metadata\provider and the local\request\data_provider.
        if ($this->component_implements($component, \core_privacy\local\metadata\provider::class) &&
            $this->component_implements($component, \core_privacy\local\request\data_provider::class)) {
            return true;
        }
        return false;
    }

    /**
     * Get the privacy metadata for all components.
     *
     * @return collection[] The array of collection objects, indexed by frankenstyle component name.
     */
    public function get_metadata_for_components() : array {
        // Get the metadata, and put into an assoc array indexed by component name.
        $metadata = [];
        foreach ($this->get_component_list() as $component) {
            if ($this->component_implements($component, \core_privacy\local\metadata\provider::class)) {
                $metadata[$component] = $this->get_provider_classname($component)::get_metadata(new collection($component));
            }
        }
        return $metadata;
    }

    /**
     * Gets a collection of resultset objects for all components.
     *
     * @param int $userid the id of the user we're fetching contexts for.
     * @return contextlist_collection the collection of contextlist items for the respective components.
     */
    public function get_contexts_for_userid(int $userid) : contextlist_collection {
        $clcollection = new contextlist_collection($userid);
        foreach ($this->get_component_list() as $component) {
            if ($this->component_implements($component, \core_privacy\local\request\core_user_data_provider::class)) {
                $contextlist = $this->get_provider_classname($component)::get_contexts_for_userid($userid);
            } else {
                $contextlist = new local\request\contextlist();
            }

            // Each contextlist is tied to its respective component.
            $contextlist->set_component($component);

            // Add contexts that the component may not know about.
            // Example of these include activity completion which modules do not know about themselves.
            $contextlist = local\request\helper::add_shared_contexts_to_contextlist_for($userid, $contextlist);

            if (count($contextlist)) {
                $clcollection->add_contextlist($contextlist);
            }
        }

        return $clcollection;
    }

    /**
     * Export all user data for the specified approved_contextlist items.
     *
     * Note: userid and component are stored in each respective approved_contextlist.
     *
     * @param contextlist_collection $contextlistcollection the collection of contextlists for all components.
     * @return string the location of the exported data.
     * @throws \moodle_exception if the contextlist_collection does not contain all approved_contextlist items or if one of the
     * approved_contextlists' components is not a core_data_provider.
     */
    public function export_user_data(contextlist_collection $contextlistcollection) {
        // Export for the various components/contexts.
        foreach ($contextlistcollection as $approvedcontextlist) {
            if (!$approvedcontextlist instanceof \core_privacy\local\request\approved_contextlist) {
                throw new \moodle_exception('Contextlist must be an approved_contextlist');
            }

            $component = $approvedcontextlist->get_component();
            // Core user data providers.
            if ($this->component_implements($component, \core_privacy\local\request\core_user_data_provider::class)) {
                if (count($approvedcontextlist)) {
                    // This plugin has data it knows about. It is responsible for storing basic data about anything it is
                    // told to export.
                    $this->get_provider_classname($component)::export_user_data($approvedcontextlist);
                }
            } else {
                // This plugin does not know that it has data - export the shared data it doesn't know about.
                local\request\helper::export_data_for_null_provider($approvedcontextlist);
            }
        }

        // Check each component for non contextlist items too.
        foreach ($this->get_component_list() as $component) {
            // Core user preference providers.
            if ($this->component_implements($component, \core_privacy\local\request\user_preference_provider::class)) {
                $this->get_provider_classname($component)::export_user_preferences($contextlistcollection->get_userid());
            }
        }

        return local\request\writer::with_context(\context_system::instance())->finalise_content();
    }

    /**
     * Delete all user data for approved contexts lists provided in the collection.
     *
     * This call relates to the forgetting of an entire user.
     *
     * Note: userid and component are stored in each respective approved_contextlist.
     *
     * @param contextlist_collection $contextlistcollection the collections of approved_contextlist items on which to call deletion.
     * @throws \moodle_exception if the contextlist_collection doesn't contain all approved_contextlist items, or if the component
     * for an approved_contextlist isn't a core provider.
     */
    public function delete_data_for_user(contextlist_collection $contextlistcollection) {
        // Delete the data.
        foreach ($contextlistcollection as $approvedcontextlist) {
            if (!$approvedcontextlist instanceof \core_privacy\local\request\approved_contextlist) {
                throw new \moodle_exception('Contextlist must be an approved_contextlist');
            }

            if ($this->component_is_core_provider($approvedcontextlist->get_component())) {
                if (count($approvedcontextlist)) {
                    // The component knows about data that it has.
                    // Have it delete its own data.
                    $this->get_provider_classname($approvedcontextlist->get_component())::delete_data_for_user($approvedcontextlist);
                }
            }

            // Delete any shared user data it doesn't know about.
            local\request\helper::delete_data_for_user($approvedcontextlist);
        }
    }

    /**
     * Delete all use data which matches the specified deletion criteria.
     *
     * @param   context         $context   The specific context to delete data for.
     */
    public function delete_data_for_all_users_in_context(\context $context) {
        foreach ($this->get_component_list() as $component) {
            if ($this->component_implements($component, \core_privacy\local\request\core_user_data_provider::class)) {
                // This component knows about specific data that it owns.
                // Have it delete all of that user data for the context.
                $this->get_provider_classname($component)::delete_data_for_all_users_in_context($context);
            }

            // Delete any shared user data it doesn't know about.
            local\request\helper::delete_data_for_all_users_in_context($component, $context);
        }
    }

    /**
     * Check whether the specified component is a core provider.
     *
     * @param string $component the frankenstyle component name.
     * @return bool true if the component is a core provider, false otherwise.
     */
    protected function component_is_core_provider($component) {
        return $this->component_implements($component, \core_privacy\local\request\core_data_provider::class);
    }

    /**
     * Returns a list of frankenstyle names of core components (plugins and subsystems).
     *
     * @return array the array of frankenstyle component names.
     */
    protected function get_component_list() {
        $components = [];
        // Get all plugins.
        $plugintypes = \core_component::get_plugin_types();
        foreach ($plugintypes as $plugintype => $typedir) {
            $plugins = \core_component::get_plugin_list($plugintype);
            foreach ($plugins as $pluginname => $plugindir) {
                $components[] = $plugintype . '_' . $pluginname;
            }
        }
        // Get all subsystems.
        foreach (\core_component::get_core_subsystems() as $name => $path) {
            if (isset($path)) {
                $components[] = 'core_' . $name;
            }
        }
        return $components;
    }

    /**
     * Return the fully qualified provider classname for the component.
     *
     * @param string $component the frankenstyle component name.
     * @return string the fully qualified provider classname.
     */
    protected function get_provider_classname($component) {
        return static::get_provider_classname_for_component($component);
    }

    /**
     * Return the fully qualified provider classname for the component.
     *
     * @param string $component the frankenstyle component name.
     * @return string the fully qualified provider classname.
     */
    public static function get_provider_classname_for_component(string $component) {
        return "$component\privacy\provider";
    }

    /**
     * Checks whether the component's provider class implements the specified interface.
     * This can either be implemented directly, or by implementing a descendant (extension) of the specified interface.
     *
     * @param string $component the frankenstyle component name.
     * @param string $interface the name of the interface we want to check.
     * @return bool True if an implementation was found, false otherwise.
     */
    protected function component_implements(string $component, string $interface) : bool {
        $providerclass = $this->get_provider_classname($component);
        if (class_exists($providerclass)) {
            $rc = new \ReflectionClass($providerclass);
            return $rc->implementsInterface($interface);
        }
        return false;
    }
}

<?php
/*
 * Copyright (c) Enalean, 2016 - 2017. All Rights Reserved.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, If not, see <http://www.gnu.org/licenses/>
 */

namespace Tuleap\Theme\BurningParrot;

use Admin_Homepage_Dao;
use CSRFSynchronizerToken;
use Event;
use EventManager;
use Layout;
use Project;
use ProjectManager;
use Tuleap\Layout\SidebarPresenter;
use URLRedirect;
use User_LoginPresenterBuilder;
use UserManager;
use Widget_Static;
use TemplateRendererFactory;
use HTTPRequest;
use PFUser;
use ForgeConfig;
use Tuleap\Theme\BurningParrot\Navbar\PresenterBuilder as NavbarPresenterBuilder;
use Tuleap\Layout\IncludeAssets;

class BurningParrotTheme extends Layout
{
    /** @var ProjectManager */
    private $project_manager;

    /** @var \MustacheRenderer */
    private $renderer;

    /** @var PFUser */
    private $user;

    /** @var HTTPRequest */
    private $request;

    public function __construct($root, PFUser $user)
    {
        parent::__construct($root);
        $this->user            = $user;
        $this->project_manager = ProjectManager::instance();
        $this->request         = HTTPRequest::instance();
        $this->renderer        = TemplateRendererFactory::build()->getRenderer($this->getTemplateDir());
        $tlp_include_assets    = new IncludeAssets(ForgeConfig::get('tuleap_dir') . '/src/www/themes/common/tlp/dist', '/themes/common/tlp/dist');
        $this->includeFooterJavascriptFile($tlp_include_assets->getFileURL('tlp.' . $user->getLocale() . '.min.js'));
        $this->includeFooterJavascriptFile($this->include_asset->getFileURL('burningparrot.js'));
    }

    public function includeCalendarScripts()
    {
    }

    public function getDatePicker()
    {
    }

    public function header(array $params)
    {
        $url_redirect                = new URLRedirect(EventManager::instance());
        $header_presenter_builder    = new HeaderPresenterBuilder();
        $main_classes                = isset($params['main_classes']) ? $params['main_classes'] : array();
        $sidebar                     = $this->getSidebarFromParams($params);
        $body_classes                = $this->getArrayOfClassnamesForBodyTag($params, $sidebar);
        $current_project_navbar_info = $this->getCurrentProjectNavbarInfo($params);

        $header_presenter = $header_presenter_builder->build(
            new NavbarPresenterBuilder(),
            $this->request,
            $this->user,
            $this->imgroot,
            $params['title'],
            $this->_feedback->logs,
            $body_classes,
            $main_classes,
            $sidebar,
            $current_project_navbar_info,
            $this->getListOfIconUnicodes(),
            $url_redirect
        );

        $this->renderer->renderToPage('header', $header_presenter);
    }

    private function getArrayOfClassnamesForBodyTag($params, $sidebar)
    {
        $body_classes = array();

        if (isset($params['body_class'])) {
            $body_classes = $params['body_class'];
        }

        if (! $sidebar) {
            return $body_classes;
        }

        $body_classes[] = 'has-sidebar';

        if ($this->shouldIncludeSitebarStatePreference($params)) {
            $body_classes[] = $this->user->getPreference('sidebar_state');
        }

        return $body_classes;
    }

    public function footer(array $params)
    {
        $javascript_files = array();
        EventManager::instance()->processEvent(
            Event::BURNING_PARROT_GET_JAVASCRIPT_FILES,
            array(
                'javascript_files' => &$javascript_files
            )
        );

        foreach ($javascript_files as $javascript_file) {
            $this->includeFooterJavascriptFile($javascript_file);
        }

        $footer = new FooterPresenter(
            $this->javascript_in_footer,
            $this->getTuleapVersion()
        );
        $this->renderer->renderToPage('footer', $footer);

        if ($this->isInDebugMode()) {
            $this->showDebugInfo();
        }
    }

    public function displayStaticWidget(Widget_Static $widget)
    {
        $this->renderer->renderToPage('widget', $widget);
    }

    private function getTemplateDir()
    {
        return __DIR__ . '/templates/';
    }

    private function isInDebugMode()
    {
        return (ForgeConfig::get('DEBUG_MODE') && (ForgeConfig::get('DEBUG_DISPLAY_FOR_ALL') || user_ismember(1, 'A')));
    }

    public function displayStandardHomepage(
        $display_homepage_news,
        $display_homepage_login_form,
        $is_secure
    ) {
        $homepage_dao = $this->getAdminHomepageDao();
        $current_user = UserManager::instance()->getCurrentUser();

        $headline = $homepage_dao->getHeadlineByLanguage($current_user->getLocale());

        $most_secure_url = '';
        if (ForgeConfig::get('sys_https_host')) {
            $most_secure_url = 'https://'. ForgeConfig::get('sys_https_host');
        }

        $login_presenter_builder = new User_LoginPresenterBuilder();
        $login_csrf              = new CSRFSynchronizerToken('/account/login.php');
        $login_presenter         = $login_presenter_builder->buildForHomepage($is_secure, $login_csrf);

        $templates_dir = ForgeConfig::get('codendi_dir') . '/src/templates/homepage/';
        $renderer      = TemplateRendererFactory::build()->getRenderer($templates_dir);
        $presenter     = new HomePagePresenter(
            $headline,
            $current_user,
            $most_secure_url,
            $login_presenter,
            $display_homepage_login_form
        );
        $renderer->renderToPage('homepage', $presenter);
    }

    private function getAdminHomepageDao()
    {
        return new Admin_Homepage_Dao();
    }

    private function getTuleapVersion()
    {
        return trim(file_get_contents($GLOBALS['tuleap_dir'] . '/VERSION'));
    }

    private function getSidebarFromParams(array $params)
    {
        if (isset($params['sidebar'])) {
            return $params['sidebar'];
        } else if (! empty($params['group'])) {
            $project = $this->project_manager->getProject($params['group']);

            return $this->getSidebarPresenterForProject($project, $params);
        }

        return false;
    }

    private function getSidebarPresenterForProject(Project $project, array $params)
    {
        $project_sidebar_presenter = new ProjectSidebarPresenter(
            $this->getUser(),
            $project,
            $this->getProjectSidebar($params, $project),
            $this->getProjectPrivacy($project)
        );

        return new SidebarPresenter(
            'project-sidebar',
            $this->renderer->renderToString('project-sidebar', $project_sidebar_presenter)
        );
    }

    private function getCurrentProjectNavbarInfo(array $params)
    {
        if (empty($params['group'])) {
            return false;
        }

        $project = $this->project_manager->getProject($params['group']);

        return new CurrentProjectNavbarInfoPresenter(
            $project,
            $this->getProjectPrivacy($project)
        );
    }

    private function shouldIncludeSitebarStatePreference(array $params)
    {
        $is_in_siteadmin     = isset($params['in_siteadmin']) && $params['in_siteadmin'] === true;
        $user_has_preference = $this->user->getPreference('sidebar_state');

        return ! $is_in_siteadmin && $user_has_preference;
    }
}

<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;

/**
 * DokuWiki Plugin navinormi (Action Component)
 *
 * Inserts the navigation bar above and below the content of every norm page.
 * It is generated on each request (not part of the cached page), so changes to
 * the table of contents take effect immediately.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author Marcel Keienborg <marcel@aufentha.lt>
 */
class action_plugin_navinormi extends ActionPlugin
{
    /** @var array|null|false navigation of the current page; false = not yet computed */
    private $nav = false;

    /** @inheritDoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'handleRender', 'top');
        $controller->register_hook('TPL_ACT_RENDER', 'AFTER', $this, 'handleRender', 'bottom');
    }

    public function handleRender(Event $event, string $position)
    {
        if ($event->data !== 'show') {
            return;
        }
        if ($this->nav === false) {
            global $ID;
            $this->nav = plugin_load('helper', 'navinormi')->getNavigation((string) $ID);
        }
        if ($this->nav === null) {
            return;
        }
        echo plugin_load('helper', 'navinormi')->renderNavigation($this->nav, $position);
    }
}

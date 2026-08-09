<?php

namespace GlpiPlugin\Glpimobile;

use CommonGLPI;
use Session;

/**
 * Setup-menu entry so the notification config page is reachable after install
 * (Setup → GLPI Mobile), not only via the plugin-list gear.
 */
class Menu extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('GLPI Mobile', 'glpimobile');
    }

    public static function getIcon()
    {
        return 'ti ti-device-mobile';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight('config', READ);
    }

    public static function canCreate(): bool
    {
        return (bool) Session::haveRight('config', UPDATE);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        return [
            'title' => self::getTypeName(),
            'page'  => '/plugins/glpimobile/front/config.php',
            'icon'  => self::getIcon(),
        ];
    }
}

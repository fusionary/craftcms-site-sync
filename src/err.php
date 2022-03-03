<?php

namespace timkelty\craftcms\sitesync;

define('PATH', '/apps/error.log');
define('ENABLED', true);

class err {
    public static function log($obj, $marker = null, $enabled = ENABLED): void {
        if ($enabled) {
            error_log('[' . date("F j, Y, g:i a e O") . '] ' . ($marker ? $marker . ' ' : '') . print_r($obj, true) . "\n", 3, PATH);
        }
    }

    public static function bool($obj): string {
        return ($obj ? 'true' : 'false');
    }
}

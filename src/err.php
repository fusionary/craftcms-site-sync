<?php

namespace timkelty\craftcms\sitesync;

define('PATH', '/app/error.log');
define('ENABLED', false);
define('LIMIT', 40);

class err {
    private static $instance = null;
    private $count = 0;

    // My name is Singleton, Singleton Class
    public static function i(): err {
        if (self::$instance == null) {
            self::$instance = new err();
        }

        return self::$instance;
    }

    public function log($obj, $marker = null, $enabled = ENABLED): void {
        if ($enabled && $this->count < LIMIT) {
            error_log('[' . date("F j, Y, g:i a") . '] ' . ($marker ? $marker . ' ' : '') . print_r($obj, true) . "\n", 3, PATH);
            $this->count++;
        }
    }

    public static function bool($obj): string {
        return ($obj ? 'true' : 'false');
    }
}

<?php
// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart
// this is an autogenerated file - do not edit
function autoload5ac4f12e757c8346316c073be9169bd1($class) {
    static $classes = null;
    if ($classes === null) {
        $classes = array(
            'proftpddirectoryitem' => '/ProftpdDirectoryItem.class.php',
            'proftpddirectoryparser' => '/ProftpdDirectoryParser.class.php',
            'proftpdplugin' => '/proftpdPlugin.class.php',
            'proftpdplugindescriptor' => '/ProftpdPluginDescriptor.class.php',
            'proftpdplugininfo' => '/ProftpdPluginInfo.class.php'
        );
    }
    $cn = strtolower($class);
    if (isset($classes[$cn])) {
        require dirname(__FILE__) . $classes[$cn];
    }
}
spl_autoload_register('autoload5ac4f12e757c8346316c073be9169bd1');
// @codeCoverageIgnoreEnd
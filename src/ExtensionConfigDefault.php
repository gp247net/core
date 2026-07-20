<?php
namespace GP247\Core;

abstract class  ExtensionConfigDefault
{       
    public $configGroup;
    public $configCode;
    public $configKey;
    public $title;
    public $version;
    public $requireCore;
    public $requirePackages;
    public $requireExtensions;
    public $auth;
    public $link;
    public $image;
    const ON = 1;
    const OFF = 0;
    const ALLOW = 1;
    const DENIED = 0;
    public $appPath;

    /**
     * Install app
     */
    abstract public function install();

    /**
     * Uninstall app
     */
    abstract public function uninstall();

    /**
     * Update app after its files have been replaced with a newer version.
     *
     * Non-abstract on purpose: extensions built before the update mechanism
     * (plugin format 1.0) must keep working without code changes — for them a
     * plain file replacement is a complete update. Extensions that need data
     * migrations per version should override this method.
     *
     * @param string|null $fromVersion Version installed before the file replacement.
     * @return array ['error' => 0|1, 'msg' => string]
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-005
     */
    public function update(?string $fromVersion = null)
    {
        return ['error' => 0, 'msg' => ''];
    }

    /**
     * Enable app
     */
    abstract public function enable();

    /**
     * Disable app
     */
    abstract public function disable();

    /**
     * Get info app
     */
    abstract public function getInfo();


    /**
     * Apply for store
     */
    abstract public function setupStore($storeId = null);
       
    /**
     * Remove for store
     */
    abstract function removeStore($storeId = null);
    
    /**
     * Process when click button plugin in admin
     */
    public function clickApp()
    {
        return null;
    }

    /**
     * Config app
     */
    public function endApp()
    {
        return null;
    }
}

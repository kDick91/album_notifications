<?php

namespace OCA\AlbumNotifications\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'album_notifications';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Background jobs are now registered via info.xml only
        // No need to register them here in Nextcloud 31+
    }

    public function boot(IBootContext $context): void {
        // Boot logic if needed
    }
}
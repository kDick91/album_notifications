<?php

namespace OCA\AlbumNotifications\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\AlbumNotifications\BackgroundJob\DailyNotificationJob;

class Application extends App implements IBootstrap {
    public const APP_ID = 'album_notifications';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Register background job
        $context->registerBackgroundJob(DailyNotificationJob::class);
    }

    public function boot(IBootContext $context): void {
        // Boot logic if needed
    }
}
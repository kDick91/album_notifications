<?php

namespace OCA\AlbumNotifications\Settings;

use OCP\Settings\ISettings;
use OCP\IUserSession;
use OCP\AppFramework\Http\TemplateResponse;

class AlbumNotificationsSettings implements ISettings {
  private $userSession;

  public function __construct(IUserSession $userSession) {
    $this->userSession = $userSession;
  }

  public function getForm() {
    $user = $this->userSession->getUser();
    $displayName = $user ? $user->getDisplayName() : 'Unknown';
    $parameters = ['user' => $displayName];
    return new TemplateResponse('album_notifications', 'settings', $parameters);
  }

  public function getSection() {
    return 'album_notifications';
  }

  public function getPriority() {
    return 10;
  }
}
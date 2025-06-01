<?php

namespace OCA\AlbumNotifications\Settings;

use OCP\Settings\ISettings;
use OCP\IUserSession;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCA\Photos\Service\AlbumService;

class AlbumNotificationsSettings implements ISettings {
    private $userSession;
    private $config;
    private $albumService;
    private $userId;

    public function __construct(IUserSession $userSession, IConfig $config, AlbumService $albumService) {
        $this->userSession = $userSession;
        $this->config = $config;
        $this->albumService = $albumService;
        $this->userId = $userSession->getUser()->getUID();
    }

    public function getForm() {
        $displayName = $this->userSession->getUser()->getDisplayName();

        // Fetch albums using AlbumService
        try {
            $myAlbums = $this->albumService->getAlbums($this->userId);
            $sharedAlbums = $this->albumService->getSharedAlbums($this->userId);
        } catch (\Exception $e) {
            $myAlbums = [];
            $sharedAlbums = [];
        }

        // Normalize album data
        $albums = array_merge(
            array_map(function ($album) {
                return ['id' => $album['albumId'], 'name' => $album['name']];
            }, $myAlbums),
            array_map(function ($album) {
                return ['id' => $album['albumId'], 'name' => $album['name']];
            }, $sharedAlbums)
        );

        // Get selected albums from config
        $selectedAlbumsJson = $this->config->getUserValue($this->userId, 'album_notifications', 'selected_albums', '[]');
        $selected_albums = json_decode($selectedAlbumsJson, true);

        $parameters = [
            'user' => $displayName,
            'albums' => $albums,
            'selected_albums' => $selected_albums,
        ];
        return new TemplateResponse('album_notifications', 'settings', $parameters);
    }

    public function getSection() {
        return 'album_notifications';
    }

    public function getPriority() {
        return 10;
    }
}
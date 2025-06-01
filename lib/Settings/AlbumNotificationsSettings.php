<?php

namespace OCA\AlbumNotifications\Settings;

use OCP\Settings\ISettings;
use OCP\IUserSession;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Http\Client\IClientService;

class AlbumNotificationsSettings implements ISettings {
    private $userSession;
    private $config;
    private $urlGenerator;
    private $clientService;
    private $userId;

    public function __construct(IUserSession $userSession, IConfig $config, IURLGenerator $urlGenerator, IClientService $clientService) {
        $this->userSession = $userSession;
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
        $this->clientService = $clientService;
        $this->userId = $userSession->getUser()->getUID();
    }

    public function getForm() {
        $displayName = $this->userSession->getUser()->getDisplayName();

        // Fetch albums from Photos app
        $client = $this->clientService->newClient();
        $myAlbumsUrl = $this->urlGenerator->linkToRouteAbsolute('photos.albums.myAlbums');
        $sharedAlbumsUrl = $this->urlGenerator->linkToRouteAbsolute('photos.albums.sharedAlbums');

        try {
            $myAlbumsResponse = $client->get($myAlbumsUrl);
            $myAlbums = json_decode($myAlbumsResponse->getBody(), true) ?? [];
        } catch (\Exception $e) {
            $myAlbums = [];
        }

        try {
            $sharedAlbumsResponse = $client->get($sharedAlbumsUrl);
            $sharedAlbums = json_decode($sharedAlbumsResponse->getBody(), true) ?? [];
        } catch (\Exception $e) {
            $sharedAlbums = [];
        }

        // Combine albums
        $albums = array_merge($myAlbums, $sharedAlbums);

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
<?php

use OCP\IRequest;
use Psr\Log\LoggerInterface;

public function __construct(
    IUserSession $userSession,
    IConfig $config,
    IURLGenerator $urlGenerator,
    IClientService $clientService,
    IRequest $request,
    LoggerInterface $logger
) {
    $this->userSession = $userSession;
    $this->config = $config;
    $this->urlGenerator = $urlGenerator;
    $this->clientService = $clientService;
    $this->request = $request;
    $this->logger = $logger;
    $this->userId = $userSession->getUser()->getUID();
}

public function getForm() {
    $displayName = $this->userSession->getUser()->getDisplayName();

    $client = $this->clientService->newClient();
    $myAlbumsUrl = $this->urlGenerator->linkToRouteAbsolute('photos.albums.myAlbums');
    $sharedAlbumsUrl = $this->urlGenerator->linkToRouteAbsolute('photos.albums.sharedAlbums');

    $sessionCookie = $this->request->getCookie('nc_session_id');
    $options = [
        'headers' => [
            'Cookie' => 'nc_session_id=' . $sessionCookie,
        ],
    ];

    try {
        $myAlbumsResponse = $client->get($myAlbumsUrl, $options);
        $myAlbumsBody = $myAlbumsResponse->getBody();
        $this->logger->log(\Psr\Log\LogLevel::DEBUG, 'My Albums Response: ' . $myAlbumsBody, ['app' => 'album_notifications']);
        $myAlbums = json_decode($myAlbumsBody, true) ?? [];
    } catch (\Exception $e) {
        $this->logger->log(\Psr\Log\LogLevel::ERROR, 'Failed to fetch my albums: ' . $e->getMessage(), ['app' => 'album_notifications']);
        $myAlbums = [];
    }

    try {
        $sharedAlbumsResponse = $client->get($sharedAlbumsUrl, $options);
        $sharedAlbumsBody = $sharedAlbumsResponse->getBody();
        $this->logger->log(\Psr\Log\LogLevel::DEBUG, 'Shared Albums Response: ' . $sharedAlbumsBody, ['app' => 'album_notifications']);
        $sharedAlbums = json_decode($sharedAlbumsBody, true) ?? [];
    } catch (\Exception $e) {
        $this->logger->log(\Psr\Log\LogLevel::ERROR, 'Failed to fetch shared albums: ' . $e->getMessage(), ['app' => 'album_notifications']);
        $sharedAlbums = [];
    }

    $albums = array_merge($myAlbums, $sharedAlbums);
    $this->logger->log(\Psr\Log\LogLevel::DEBUG, 'Combined Albums: ' . json_encode($albums), ['app' => 'album_notifications']);

    $selectedAlbumsJson = $this->config->getUserValue($this->userId, 'album_notifications', 'selected_albums', '[]');
    $selected_albums = json_decode($selectedAlbumsJson, true);

    $parameters = [
        'user' => $displayName,
        'albums' => $albums,
        'selected_albums' => $selected_albums,
    ];
    return new TemplateResponse('album_notifications', 'settings', $parameters);
}
<?php

namespace OCA\AlbumNotifications\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IUserSession;

class SettingsController extends Controller {
    private $config;
    private $userSession;
    private $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        IConfig $config,
        IUserSession $userSession
    ) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userSession = $userSession;
        $this->userId = $userSession->getUser()->getUID();
    }

    /**
     * @NoAdminRequired
     */
    public function saveSettings(): JSONResponse {
        $selectedAlbums = $this->request->getParam('selected_albums');
        
        if ($selectedAlbums !== null) {
            // Validate JSON
            $decoded = json_decode($selectedAlbums, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->config->setUserValue(
                    $this->userId,
                    'album_notifications',
                    'selected_albums',
                    $selectedAlbums
                );
                return new JSONResponse(['status' => 'success']);
            }
        }
        
        return new JSONResponse(['status' => 'error', 'message' => 'Invalid data'], 400);
    }
}
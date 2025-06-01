<?php

namespace OCA\AlbumNotifications\Settings;

use OCP\Settings\ISettings;
use OCP\IUserSession;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IDBConnection;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class AlbumNotificationsSettings implements ISettings {
    private $userSession;
    private $config;
    private $urlGenerator;
    private $db;
    private $appManager;
    private $logger;
    private $userId;

    public function __construct(
        IUserSession $userSession,
        IConfig $config,
        IURLGenerator $urlGenerator,
        IDBConnection $db,
        IAppManager $appManager,
        LoggerInterface $logger
    ) {
        $this->userSession = $userSession;
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
        $this->db = $db;
        $this->appManager = $appManager;
        $this->logger = $logger;
        $this->userId = $userSession->getUser()->getUID();
    }

    public function getForm() {
        $displayName = $this->userSession->getUser()->getDisplayName();
        
        // Debug information
        $debugInfo = $this->getDebugInfo();
        $albums = [];

        // Try to get albums from different sources
        $albums = array_merge(
            $this->getMemoriesAlbums(),
            $this->getPhotosAlbums(),
            $this->getPhotosCollaborativeAlbums()
        );

        // Remove duplicates based on album ID
        $uniqueAlbums = [];
        $seenIds = [];
        foreach ($albums as $album) {
            if (!in_array($album['id'], $seenIds)) {
                $uniqueAlbums[] = $album;
                $seenIds[] = $album['id'];
            }
        }

        // Get selected albums from config
        $selectedAlbumsJson = $this->config->getUserValue($this->userId, 'album_notifications', 'selected_albums', '[]');
        $selected_albums = json_decode($selectedAlbumsJson, true) ?? [];

        $parameters = [
            'user' => $displayName,
            'albums' => $uniqueAlbums,
            'selected_albums' => $selected_albums,
            'debug_info' => $debugInfo,
        ];
        return new TemplateResponse('album_notifications', 'settings', $parameters);
    }

    private function getDebugInfo(): array {
        $debug = [];
        
        // Check enabled apps
        $debug['photos_enabled'] = $this->appManager->isEnabledForUser('photos');
        $debug['memories_enabled'] = $this->appManager->isEnabledForUser('memories');
        $debug['user_id'] = $this->userId;
        
        // Check for existing tables
        $possibleTables = [
            'photos_albums_files',
            'photos_albums', 
            'oc_photos_albums',
            'photos_album',
            'memories_albums',
            'oc_memories_albums',
            'photos_albums_collabs',
            'oc_photos_albums_collabs'
        ];
        
        $debug['existing_tables'] = [];
        foreach ($possibleTables as $table) {
            if ($this->tableExists($table)) {
                $debug['existing_tables'][] = $table;
                $debug['table_structure'][$table] = $this->getTableStructure($table);
                $debug['table_sample_data'][$table] = $this->getSampleData($table);
            }
        }
        
        $this->logger->log(LogLevel::INFO, 'Debug info: ' . json_encode($debug), ['app' => 'album_notifications']);
        
        return $debug;
    }

    private function getTableStructure(string $tableName): array {
        try {
            $result = $this->db->executeQuery("DESCRIBE `{$tableName}`");
            $columns = [];
            while ($row = $result->fetch()) {
                $columns[] = $row['Field'] ?? $row['COLUMN_NAME'] ?? 'unknown';
            }
            $result->closeCursor();
            return $columns;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getSampleData(string $tableName): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from($tableName)
               ->setMaxResults(3);
            
            $result = $qb->executeQuery();
            $data = [];
            while ($row = $result->fetch()) {
                $data[] = $row;
            }
            $result->closeCursor();
            return $data;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getMemoriesAlbums(): array {
        $albums = [];

        if (!$this->appManager->isEnabledForUser('memories')) {
            return $albums;
        }

        $possibleTables = ['memories_albums', 'oc_memories_albums'];
        
        foreach ($possibleTables as $tableName) {
            if ($this->tableExists($tableName)) {
                try {
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('*')
                       ->from($tableName);
                    
                    $result = $qb->executeQuery();
                    while ($row = $result->fetch()) {
                        $albums[] = [
                            'id' => 'memories_' . ($row['album_id'] ?? $row['id'] ?? 'unknown'),
                            'name' => $row['name'] ?? $row['title'] ?? 'Unnamed Album',
                            'source' => 'memories',
                            'raw_data' => $row
                        ];
                    }
                    $result->closeCursor();
                    break;
                } catch (\Exception $e) {
                    $this->logger->log(LogLevel::ERROR, 'Memories error: ' . $e->getMessage(), ['app' => 'album_notifications']);
                }
            }
        }

        return $albums;
    }

    private function getPhotosAlbums(): array {
        $albums = [];

        if (!$this->appManager->isEnabledForUser('photos')) {
            return $albums;
        }

        $possibleTables = ['photos_albums', 'oc_photos_albums', 'photos_album'];
        
        foreach ($possibleTables as $tableName) {
            if ($this->tableExists($tableName)) {
                try {
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('*')
                       ->from($tableName);
                    
                    $result = $qb->executeQuery();
                    while ($row = $result->fetch()) {
                        $albums[] = [
                            'id' => 'photos_' . ($row['id'] ?? $row['album_id'] ?? 'unknown'),
                            'name' => $row['name'] ?? $row['title'] ?? 'Unnamed Album',
                            'source' => 'photos',
                            'raw_data' => $row
                        ];
                    }
                    $result->closeCursor();
                    break;
                } catch (\Exception $e) {
                    $this->logger->log(LogLevel::ERROR, 'Photos error: ' . $e->getMessage(), ['app' => 'album_notifications']);
                }
            }
        }

        return $albums;
    }

    private function getPhotosCollaborativeAlbums(): array {
        $albums = [];

        $possibleTables = ['photos_albums_collabs', 'oc_photos_albums_collabs'];
        
        foreach ($possibleTables as $tableName) {
            if ($this->tableExists($tableName)) {
                try {
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('*')
                       ->from($tableName);
                    
                    $result = $qb->executeQuery();
                    while ($row = $result->fetch()) {
                        $albums[] = [
                            'id' => 'collab_' . ($row['id'] ?? $row['album_id'] ?? 'unknown'),
                            'name' => $row['name'] ?? $row['title'] ?? 'Collaborative Album',
                            'source' => 'collaborative',
                            'raw_data' => $row
                        ];
                    }
                    $result->closeCursor();
                    break;
                } catch (\Exception $e) {
                    $this->logger->log(LogLevel::ERROR, 'Collaborative albums error: ' . $e->getMessage(), ['app' => 'album_notifications']);
                }
            }
        }

        return $albums;
    }

    private function tableExists(string $tableName): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('1'))
               ->from($tableName)
               ->setMaxResults(1);
            $qb->executeQuery()->closeCursor();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getSection() {
        return 'album_notifications';
    }

    public function getPriority() {
        return 10;
    }
}